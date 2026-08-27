<?php

namespace App\Models;

use App\Http\Controllers\Api\FileController;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\URL;

class Borrower extends Model
{
    use Auditable, HasFactory;

    /**
     * Registration statuses that are NOT membership.
     *
     * A `pending` applicant has not been approved yet and a `rejected` one
     * never will be; neither may appear anywhere the system means "member".
     * `inactive` and `blacklisted` are intentionally absent — those people are
     * members in poor standing, not non-members.
     *
     * @var list<string>
     */
    public const NON_MEMBER_STATUSES = ['pending', 'rejected'];

    protected $fillable = [
        'registration_uuid',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthdate',
        'civil_status',
        'gender',
        'address',
        'street_address',
        'barangay',
        'city',
        'province',
        'contact_number',
        'email',
        'employer_or_business',
        'monthly_income',
        'date_hired',
        'pledge_amount',
        'spouse_first_name',
        'spouse_middle_name',
        'spouse_last_name',
        'spouse_contact_number',
        'spouse_occupation',
        'photo_path',
        'branch_id',
        'status',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'date_hired' => 'date',
            'monthly_income' => 'decimal:2',
            'pledge_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Borrower $borrower) {
            // Row-level lock prevents two concurrent creates from reading the same last code
            // (mirrors the Loan::booted() pattern already in use)
            $last = static::query()->orderByDesc('id')->lockForUpdate()->first();
            $nextNum = $last ? (int) substr($last->borrower_code, 4) + 1 : 1;
            $borrower->borrower_code = 'BRW-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        });

        /**
         * A pledge row is created for EVERY borrower, `pending` applicants
         * included. The hook stays unconditional on purpose: it keeps the
         * pledge amount typed on the public registration form, and an approved
         * registration then needs no backfill.
         *
         * The member / non-member split is made on READ instead — see
         * Borrower::scopeMembers() and ShareCapitalPledge::scopeForMembers().
         */
        static::created(function (Borrower $borrower) {
            $borrower->shareCapitalPledge()->create([
                'amount' => $borrower->pledge_amount ?? 0,
                'schedule' => '15/30',
                'auto_credit' => false,
            ]);
        });
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' '));
    }

    /**
     * A temporary signed link to the borrower's photo, or null if none is set.
     *
     * The photo is on the private disk and is not web-reachable; this link is
     * the only way in and expires shortly after being minted.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path
            ? URL::temporarySignedRoute(
                'files.borrower-photo',
                now()->addMinutes(FileController::LINK_TTL_MINUTES),
                ['borrower' => $this->getKey()],
            )
            : null);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function coMakers(): HasMany
    {
        return $this->hasMany(CoMaker::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function shareCapitalPledge(): HasOne
    {
        return $this->hasOne(ShareCapitalPledge::class);
    }

    public function shareCapitalLedger(): HasMany
    {
        return $this->hasMany(ShareCapitalLedger::class);
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Everyone who belongs to the cooperative, whatever their standing.
     *
     * Deliberately NOT scopeActive(): `inactive` and `blacklisted` borrowers
     * are still members and legitimately hold share capital, so narrowing this
     * to `active` would drop their pledges and holdings — a regression, not a
     * simplification. This mirrors the Members screen's own semantics:
     * everything except a registration that is still `pending` or was
     * `rejected`.
     */
    public function scopeMembers($query)
    {
        return $query->whereNotIn('status', self::NON_MEMBER_STATUSES);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Free-text borrower lookup: code, any name part, contact number or email.
     *
     * `email` is included because the Members screen searches it. That screen
     * filtered client-side over one page of results; moving it server-side
     * would have silently dropped email matching without this.
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('borrower_code', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('middle_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('contact_number', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }
}
