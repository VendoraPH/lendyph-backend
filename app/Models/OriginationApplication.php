<?php

namespace App\Models;

use App\Exceptions\OriginationErasureRefusedException;
use App\Exceptions\OriginationStatusWriteException;
use App\Models\Builders\OriginationApplicationBuilder;
use App\Services\BranchAccess;
use App\Support\PhilippineMobile;
use App\Support\StaffRouteBinding;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An online loan application (origination audit §4). It stays an
 * application, never a member or a loan, until "Submit for approval" hands it
 * off: then it follows its loan's status (ApplicationTransitions).
 *
 * Privacy (decisions 5, 10 and 16):
 * - the draft, mobile and email are encrypted at rest; the list columns
 *   (name, product, amount, status, dates) stay plain;
 * - the mobile is also kept as a keyed hash, which finds the open draft and
 *   lets a mobile hold only one (`open_mobile_hash`, unique);
 * - it isn't Auditable: audit rows about it are written explicitly and hold
 *   ids only, never the draft;
 * - every application has a branch once intake places it; staff see only
 *   their branches' applications, and one with no branch needs
 *   `branches:view_all`.
 *
 * Integrity:
 * - the status isn't fillable, and any write of it outside
 *   ApplicationTransitions::move() (save, bulk update) is refused;
 * - `version` moves on with every save, so a move made on what a client read
 *   earlier is caught (409) even when the status has come back;
 * - only the retention erasure deletes an application (eraseForRetention()),
 *   never while it is under a legal hold, so its consents, notes and history
 *   can't be cascaded away by any other delete.
 *
 * @property string $status
 * @property ?string $reference_number
 * @property ?array<string, mixed> $draft
 */
class OriginationApplication extends Model
{
    public const STATUSES = [
        'draft', 'incomplete', 'submitted', 'document_verification', 'additional_info_required', 'under_assessment',
        'pending_approval', 'approved', 'rejected', 'withdrawn', 'converted', 'expired',
    ];

    /** Not yet submitted: the applicant's own, never a loan application yet. */
    public const UNSUBMITTED_STATUSES = ['draft', 'incomplete'];

    /** Nothing more happens to the application. */
    public const CLOSED_STATUSES = ['rejected', 'withdrawn', 'converted', 'expired'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'version' => 0];

    private static bool $statusWriteOpen = false;

    private static bool $erasureOpen = false;

    protected $fillable = [
        'reference_number', 'link_id', 'branch_id', 'loan_product_id', 'requested_amount', 'requested_term',
        'applicant_name', 'mobile', 'mobile_hash', 'email', 'draft', 'last_completed_step', 'completion_percent', 'is_test',
        'assigned_officer_id', 'borrower_id', 'loan_id', 'applicant_message', 'legal_hold',
        'started_at', 'last_saved_at', 'submitted_at', 'decided_at', 'erased_at',
    ];

    protected $hidden = ['mobile', 'mobile_hash', 'open_mobile_hash', 'email', 'draft'];

    protected function casts(): array
    {
        return [
            'link_id' => 'integer',
            'branch_id' => 'integer',
            'loan_product_id' => 'integer',
            'requested_amount' => 'decimal:2',
            'requested_term' => 'integer',
            'mobile' => 'encrypted',
            'email' => 'encrypted',
            'draft' => 'encrypted:array',
            'completion_percent' => 'integer',
            'version' => 'integer',
            'is_test' => 'boolean',
            'assigned_officer_id' => 'integer',
            'borrower_id' => 'integer',
            'loan_id' => 'integer',
            'legal_hold' => 'boolean',
            'started_at' => 'datetime',
            'last_saved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    /** The HKDF label of the mobile hash's own key. */
    public const MOBILE_HASH_KEY_LABEL = 'lendyph:loan-origination:mobile-hash';

    protected static function booted(): void
    {
        static::saving(function (self $application): void {
            if (! $application->isDirty('status') || self::$statusWriteOpen) {
                return;
            }

            if (! $application->exists && $application->status === 'draft') {
                return;
            }

            throw OriginationStatusWriteException::outsideTransitions($application->getKey());
        });

        static::updating(function (self $application): void {
            $application->version = (int) $application->getOriginal('version') + 1;
        });

        static::deleting(function (self $application): void {
            if (! self::$erasureOpen) {
                throw OriginationErasureRefusedException::outsideRetention($application->getKey());
            }
        });
    }

    /** @param  \Illuminate\Database\Query\Builder  $query */
    public function newEloquentBuilder($query): OriginationApplicationBuilder
    {
        return new OriginationApplicationBuilder($query);
    }

    /**
     * Run a status write. For ApplicationTransitions::move() only
     * (OriginationImmutabilityArchTest holds every caller to it).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public static function withinTransition(Closure $write): mixed
    {
        $previous = self::$statusWriteOpen;
        self::$statusWriteOpen = true;

        try {
            return $write();
        } finally {
            self::$statusWriteOpen = $previous;
        }
    }

    public static function statusWriteIsOpen(): bool
    {
        return self::$statusWriteOpen;
    }

    public static function erasureIsOpen(): bool
    {
        return self::$erasureOpen;
    }

    /**
     * Erase this application with its consents, notes, history and documents
     * (the retention erasure, origination decision 10), re-reading its legal
     * hold under a lock first.
     *
     * @throws OriginationErasureRefusedException while it is under a legal hold
     */
    public function eraseForRetention(): void
    {
        DB::transaction(function (): void {
            /** @var self $locked */
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->legal_hold) {
                throw OriginationErasureRefusedException::legalHold();
            }

            self::$erasureOpen = true;

            try {
                $locked->delete();
            } finally {
                self::$erasureOpen = false;
            }
        });

        $this->exists = false;
    }

    /**
     * A keyed hash of a Philippine mobile, in its `639…` form, so the same
     * number typed any way finds the same draft. Keyed with its own key,
     * derived from the app key with HKDF under its own label, never the app
     * key itself: a plain hash of a ten-digit number space is reversed in
     * seconds.
     */
    public static function mobileHash(string $mobile): string
    {
        $normalised = PhilippineMobile::normalise($mobile) ?? preg_replace('/\D/', '', $mobile);

        return hash_hmac('sha256', (string) $normalised, self::mobileHashKey());
    }

    private static function mobileHashKey(): string
    {
        $appKey = (string) config('app.key');
        $material = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return hash_hkdf('sha256', $material, 32, self::MOBILE_HASH_KEY_LABEL);
    }

    /**
     * Applications $user may see: their branches' only; one with no branch
     * only with `branches:view_all`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(BranchAccess::class)->constrain($query, $user, $this->qualifyColumn('branch_id'));
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return StaffRouteBinding::scope(
            parent::resolveRouteBindingQuery($query, $value, $field),
            fn ($query, User $user) => $query->visibleTo($user),
        );
    }

    public function isUnsubmitted(): bool
    {
        return in_array($this->status, self::UNSUBMITTED_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(OriginationLink::class, 'link_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OriginationDocument::class, 'application_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(OriginationConsent::class, 'application_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OriginationNote::class, 'application_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OriginationStatusHistory::class, 'application_id');
    }
}
