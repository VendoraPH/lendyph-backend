<?php

namespace App\Models;

use App\Services\BranchAccess;
use App\Services\LikePattern;
use App\Services\SequenceCode;
use App\Services\SignedFileLink;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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

    /**
     * Statuses of a person whose membership has ended (CDA design §1): they
     * withdrew, or they died. Still members for the records (their loans,
     * ledgers and the CDA counts of members who left), so they stay out of
     * NON_MEMBER_STATUSES, but no new credit or activity starts for them:
     * - a loan is not created for them, nor with them as co-maker, and their
     *   loan is not edited, restructured or released (NotAFormerMember);
     * - a withdrawn member gets no share capital entry or pledge change; a
     *   deceased member's stay allowed, so the estate can be settled;
     * - payment reminders and share capital auto-credit skip them;
     * - a savings account needs `active`.
     *
     * @var list<string>
     */
    public const FORMER_MEMBER_STATUSES = ['withdrawn', 'deceased'];

    /** @var list<string> */
    public const MEMBER_TYPES = ['regular', 'associate'];

    public const MEMBER_TYPE_REGULAR = 'regular';

    /**
     * The `borrower_code` family. Named so SequenceAllocator predicts codes from
     * the same constant the hook issues them from.
     */
    public const CODE_PREFIX = 'BRW';

    protected $fillable = [
        'registration_uuid',
        'external_account_no',
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
        'membership_date',
        'member_type',
        'membership_number',
        'membership_ended_on',
        'membership_end_reason',
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
            'membership_date' => 'date',
            'membership_ended_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Borrower $borrower) {
            // Row-level lock prevents two concurrent creates from reading the same last code
            // (mirrors the Loan::booted() pattern already in use)
            $last = static::query()->orderByDesc('id')->lockForUpdate()->first();

            // Parsed, not cast. `(int) substr($code, 4) + 1` answers 1 for any
            // code it cannot read, and BRW-000001 already exists — so a single
            // malformed row would make EVERY borrower create fail on the unique
            // index from then on, teller and importer alike, with nothing in the
            // error to say why. SequenceCode stops on the bad row and names it.
            $borrower->borrower_code = $last === null
                ? SequenceCode::first(self::CODE_PREFIX)
                : SequenceCode::after(self::CODE_PREFIX, $last->borrower_code, "borrowers.id {$last->id}");

            // A member created with a membership date gets their membership
            // number with it: the member code, unless one was given.
            if ($borrower->membership_date !== null && blank($borrower->membership_number)) {
                $borrower->membership_number = $borrower->borrower_code;
            }
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
        // The first membership date a member is given brings their membership
        // number with it (the member code), unless they already have one or
        // another member already holds that code as their number.
        static::updating(function (Borrower $borrower): void {
            if ($borrower->isDirty('membership_date') && $borrower->getOriginal('membership_date') === null
                && $borrower->membership_date !== null && blank($borrower->membership_number)
                && ! static::query()->where('membership_number', $borrower->borrower_code)->whereKeyNot($borrower->id)->exists()) {
                $borrower->membership_number = $borrower->borrower_code;
            }
        });

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
            ? SignedFileLink::to(
                'files.borrower-photo',
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

    /**
     * Members whose membership hasn't ended: scopeMembers() without the
     * withdrawn and deceased. What payment reminders and share capital
     * auto-credit act on.
     */
    public function scopeCurrentMembers($query)
    {
        return $query->whereNotIn('status', [...self::NON_MEMBER_STATUSES, ...self::FORMER_MEMBER_STATUSES]);
    }

    public function isFormerMember(): bool
    {
        return in_array($this->status, self::FORMER_MEMBER_STATUSES, true);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Borrowers in the branches $user may see (BranchAccess). A borrower with
     * no branch (an anonymous registration not yet assigned) is visible only
     * to `branches:view_all`. A local scope only: jobs and commands never
     * call it.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $branchIds = app(BranchAccess::class)->for($user);

        return $branchIds === null ? $query : $query->whereIn('borrowers.branch_id', $branchIds);
    }

    /**
     * Every `{borrower}` route parameter resolves only to a borrower the
     * signed-in user may see, so another branch's member answers 404. Without
     * a signed-in user (submission-token uploads, signed links, console) the
     * binding is unscoped.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $user = request()->user();

        return $user instanceof User ? $query->visibleTo($user) : $query;
    }

    /**
     * Free-text borrower lookup: code, any name part, the full name, contact
     * number or email.
     *
     * `email` is included because the Members screen searches it. That screen
     * filtered client-side over one page of results; moving it server-side
     * would have silently dropped email matching without this.
     *
     * Without `$matchContactDetails`, the contact number and email are left out:
     * for a caller who may not read them, a hit would confirm that a number or
     * address belongs to a member.
     */
    public function scopeSearch($query, string $term, bool $matchContactDetails = true)
    {
        $like = LikePattern::contains($term);
        // A whole name typed with spaces ("Maria Dela Cruz") lives in no single
        // column, so it is also matched against the name as shown (suffix
        // included) and against first + last name. Runs of spaces in the term
        // collapse to one. NULLIF: CONCAT_WS skips a NULL but not an empty part.
        $nameLike = LikePattern::contains(preg_replace('/\s+/', ' ', trim($term)));

        return $query->where(function ($q) use ($like, $nameLike, $matchContactDetails) {
            $q->where('borrower_code', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->when($matchContactDetails, fn ($contact) => $contact
                    ->orWhere('contact_number', 'like', $like)
                    ->orWhere('email', 'like', $like))
                ->orWhereRaw("CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) LIKE ?", [$nameLike])
                ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$nameLike]);
        });
    }
}
