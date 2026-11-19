<?php

namespace App\Models;

use App\Services\BranchAccess;
use App\Support\PhilippineMobile;
use App\Support\StaffRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $fillable = [
        'reference_number', 'status', 'link_id', 'branch_id', 'loan_product_id', 'requested_amount', 'requested_term',
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

    /**
     * A keyed hash of a Philippine mobile, in its `639…` form, so the same
     * number typed any way finds the same draft. Keyed with the app key: a
     * plain hash of a ten-digit number space is reversed in seconds.
     */
    public static function mobileHash(string $mobile): string
    {
        $normalised = PhilippineMobile::normalise($mobile) ?? preg_replace('/\D/', '', $mobile);

        return hash_hmac('sha256', 'loan-origination-mobile:'.$normalised, (string) config('app.key'));
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
