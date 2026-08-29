<?php

namespace App\Models;

use App\Services\SequenceCode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Loan extends Model
{
    use Auditable, HasFactory;

    /**
     * Loans that have been money out the door — the portfolio a balance,
     * release, disbursement, or income report is measured over.
     *
     * A loan that was released does not stop having been released because it
     * later defaulted or was closed by a restructure, so those belong here too
     * — the cash went out the door either way, and a releases or disbursement
     * report that hides it is wrong. Whether such a loan still OWES anything is
     * a different question: see COLLECTIBLE_STATUSES.
     */
    public const EVER_RELEASED_STATUSES = ['released', 'ongoing', 'completed', 'defaulted', 'restructured'];

    /**
     * The `application_number` family. Named so SequenceAllocator predicts
     * numbers from the same constant the hook issues them from.
     */
    public const CODE_PREFIX = 'LA';

    /**
     * Loans that can still owe money on a schedule.
     *
     * `ongoing` matters because RepaymentService flips `released → ongoing` on
     * the very first payment, so filtering on `released` alone silently drops
     * every loan that has ever paid. `defaulted` matters because a defaulted
     * loan is precisely what a delinquency report exists to show.
     *
     * `restructured` is deliberately NOT here, and must not be re-added.
     * LoanService::closeRestructuredSource() is the only thing that sets it,
     * and it means one thing: closed, because the balance moved to a new loan.
     * It clears the open schedules first and zeroes the insurance, so such a
     * loan owes nothing and RepaymentService refuses payments against it.
     *
     * Note the ordering dependency this carries: before that behaviour shipped,
     * `restructured` was stamped on loans that were still live with open
     * schedules. Listing it here is the correct reading of THAT data. So the
     * restructure work — including its backfill of the damaged rows to
     * released/ongoing — has to land before this line does, or those live loans
     * disappear from every delinquency report.
     */
    public const COLLECTIBLE_STATUSES = ['released', 'ongoing', 'defaulted'];

    /**
     * Loans that are out the door and not yet finished with — the portfolio the
     * loans screen's "Active Loans" card counts, and what `?status=active`
     * expands to.
     *
     * Not the same set as COLLECTIBLE_STATUSES, and the difference is
     * deliberate: `defaulted` still owes money, so a delinquency report must
     * include it, but it is not what an operator means by an active loan, and
     * the loans screen gives it no tab. Do not collapse the two.
     *
     * This list also carried `current` and `past_due` until they were removed.
     * Neither is a member of the `loans.status` enum, so no row can hold one and
     * the entries only made this constant assert statuses that cannot exist.
     * Do NOT add them back — not here and not to the enum. The screen's Current
     * tab points at `ongoing`, and past due is not a status at all: it is an
     * `ongoing` loan holding an overdue amortization schedule, which is a
     * schedule-derived filter and is filed as a follow-up. Adding the enum
     * member instead would let a loan be stamped `past_due` and then be
     * invisible to everything that reasons about `ongoing` — which is most of
     * this codebase, RepaymentService::processRepayment() included.
     *
     * That schedule-derived filter has since shipped as
     * self::VIRTUAL_STATUS_PAST_DUE / self::scopePastDue(), which is where a
     * Past Due tab must go. It still is not — and must never become — a member
     * of this constant or of the enum. Note that scope filters on
     * self::COLLECTIBLE_STATUSES rather than on this list: a defaulted loan has
     * no tab of its own and would otherwise be reachable only under All.
     */
    public const ACTIVE_STATUSES = ['released', 'ongoing'];

    /**
     * Virtual `status` value standing for the whole of self::ACTIVE_STATUSES.
     *
     * Not a stored status and never written to a row — it exists only as a
     * query-string shorthand, so that what the Active Loans card counts and
     * what the tab it opens filters on are one definition. Callers should send
     * this rather than spelling the statuses out: the set has already changed
     * once, and a hardcoded list on the client goes stale silently.
     */
    public const VIRTUAL_STATUS_ACTIVE = 'active';

    /**
     * Virtual `status` value standing for self::scopePastDue().
     *
     * Like VIRTUAL_STATUS_ACTIVE this is a query-string shorthand that is never
     * written to a row. Unlike it, `past_due` does not expand to a list of
     * statuses at all: it is derived from `amortization_schedules`, so it
     * resolves to a subquery rather than to a `whereIn` on `loans.status`.
     *
     * Both `?status=past_due` and `meta.stats.past_due` are built from
     * self::scopePastDue(), so the tab and the badge above it cannot drift.
     *
     * It is NOT a subset of VIRTUAL_STATUS_ACTIVE: past due reaches `defaulted`
     * loans, which are collectible but not active.
     */
    public const VIRTUAL_STATUS_PAST_DUE = 'past_due';

    protected $fillable = [
        'loan_account_number',
        'external_loan_no',
        'imported_arrears_baseline',
        'borrower_id',
        'loan_product_id',
        'source_loan_id',
        'branch_id',
        'interest_rate',
        'interest_method',
        'term',
        'frequency',
        'principal_amount',
        'purpose',
        'start_date',
        'maturity_date',
        'deductions',
        'total_deductions',
        'net_proceeds',
        'scb_amount',
        'penalty_rate',
        'grace_period_days',
        'policy_exception',
        'policy_exception_details',
        'status',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'rejection_remarks',
        'rejected_by',
        'rejected_at',
        'released_by',
        'released_at',
        'created_by',
        'account_officer_id',
        'auto_pay',
        'cbs_reference',
        'auto_pay_enabled_at',
        'auto_pay_enabled_by',
        'insurance_premium_pct',
        'insurance_premium_amount',
        'insurance_payment_type',
        'insurance_partial_amount',
        'insurance_remaining_balance',
        'restructured_at',
        'restructured_balance',
        'write_off_amount',
        'restructure_outstanding',
        'restructure_principal',
        'restructure_shortfall',
        'restructure_remarks',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'principal_amount' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_proceeds' => 'decimal:2',
            'scb_amount' => 'decimal:2',
            'penalty_rate' => 'decimal:4',
            'policy_exception' => 'boolean',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'imported_arrears_baseline' => 'date',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'rejected_at' => 'datetime',
            'deductions' => 'array',
            'auto_pay' => 'boolean',
            'auto_pay_enabled_at' => 'datetime',
            'insurance_premium_pct' => 'decimal:2',
            'insurance_premium_amount' => 'decimal:2',
            'insurance_partial_amount' => 'decimal:2',
            'insurance_remaining_balance' => 'decimal:2',
            'restructured_at' => 'datetime',
            'restructured_balance' => 'decimal:2',
            'write_off_amount' => 'decimal:2',
            'restructure_outstanding' => 'decimal:2',
            'restructure_principal' => 'decimal:2',
            'restructure_shortfall' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Loan $loan) {
            $last = static::query()->orderByDesc('id')->lockForUpdate()->first();

            // Parsed, not cast — the same guard as Borrower::booted(), for the
            // same reason. `(int) substr($code, 3) + 1` answers 1 for anything
            // it cannot read, and LA-000001 already exists, so one malformed
            // application_number would make every loan create fail on the unique
            // index from then on. SequenceCode stops on the bad row and names it
            // rather than colliding forever.
            $loan->application_number = $last === null
                ? SequenceCode::first(self::CODE_PREFIX)
                : SequenceCode::after(self::CODE_PREFIX, $last->application_number, "loans.id {$last->id}");
        });
    }

    protected function isEditable(): Attribute
    {
        return Attribute::get(fn () => in_array($this->status, ['draft', 'for_review']));
    }

    protected function isReleasable(): Attribute
    {
        return Attribute::get(fn () => $this->status === 'approved');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The loan this one was restructured out of — its outstanding balance is
     * what funded this loan's principal.
     */
    public function sourceLoan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_loan_id');
    }

    /**
     * Restructure applications derived from this loan. Plural because a
     * rejected or voided attempt stays on record and a later one can follow;
     * only one may be open (draft/for_review/approved) at a time, and only the
     * released one closes this loan.
     */
    public function restructuredInto(): HasMany
    {
        return $this->hasMany(self::class, 'source_loan_id');
    }

    public function coMakers(): BelongsToMany
    {
        return $this->belongsToMany(CoMaker::class, 'co_maker_loan')->withTimestamps();
    }

    public function collaterals(): BelongsToMany
    {
        return $this->belongsToMany(Collateral::class, 'loan_collaterals')
            ->withPivot(['snapshot_value', 'attached_at'])
            ->withTimestamps();
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function accountOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_officer_id');
    }

    public function autoPayEnabledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auto_pay_enabled_by');
    }

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class)->orderBy('period_number');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class)->orderBy('payment_date');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LoanAdjustment::class)->latest();
    }

    /**
     * Debits and credits recorded against this loan, oldest first — a ledger
     * only makes sense read forwards.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LoanLedgerEntry::class)->orderBy('entry_date')->orderBy('id');
    }

    /**
     * Whether this loan is eligible for the Extend Loan action.
     *
     * Extension is limited to one-month-term loans whatever the product.
     * `term` is a period count whose unit follows `frequency` and is only
     * denominated in months for these two — see
     * LoanService::computeMaturityDate — so a daily loan with term 30 is
     * thirty daily periods, not a one-month loan.
     */
    public function isOneMonthTerm(): bool
    {
        return in_array($this->frequency, ['monthly', 'upon_maturity'], true)
            && $this->term === 1;
    }

    /**
     * Whether this loan was created by restructuring another one, i.e. its
     * principal came from a source loan's outstanding balance rather than from
     * fresh money. Exposed as `is_restructure` on LoanResource.
     */
    public function isRestructure(): bool
    {
        return $this->source_loan_id !== null;
    }

    /**
     * Whether this loan was migrated in from a cooperative's existing book by
     * the CSV importer rather than originated here. Exposed as `is_imported`
     * on LoanResource, where the UI uses it to label a schedule as
     * reconstructed rather than generated from the loan's own terms.
     *
     * True on EITHER import marker. `external_loan_no` is the coop's own
     * reference for the loan and `imported_arrears_baseline` is the date its
     * pre-import arrears stop; nothing but the importer ever writes either, but
     * a coop's file need not supply a reference number for every row, and a
     * loan imported with no arrears at all need carry no baseline. Requiring
     * both would silently mislabel those loans as natively originated.
     *
     * This is a LABEL, not the penalty rule. Nothing in the penalty or default
     * path may branch on it: those ask
     * AmortizationSchedule::isPenalisable()/penalisableSql() about one schedule
     * against the baseline, which is null-safe by construction and so cannot
     * disagree with this method about which loans it covers.
     */
    public function isImported(): bool
    {
        return $this->external_loan_no !== null
            || $this->imported_arrears_baseline !== null;
    }

    /**
     * How many times this loan has been rolled forward via the Extend Loan
     * action (LoanAdjustmentService::extendLoan()), as distinct from `term`
     * — the term the loan was agreed at, which stays fixed regardless of how
     * many times it has extended.
     *
     * Reads the count eager-loaded via withCount()/loadCount() on
     * 'adjustments as extension_count' when the caller supplied one — that is
     * what keeps a paginated loans list at one query instead of one COUNT per
     * row (see LoanController::index()). Falls back to a live count
     * otherwise, so the attribute is still correct on a bare model.
     */
    protected function extensionCount(): Attribute
    {
        return Attribute::get(function (mixed $value): int {
            if ($value !== null) {
                return (int) $value;
            }

            return $this->adjustments()->where('adjustment_type', 'extension')->count();
        });
    }

    /**
     * The one definition of what this loan still owes: principal remaining on
     * its schedules (floored at zero per period) plus any insurance premium
     * that was not collected at release.
     *
     * Every caller — the API resource, the reports, the exports — must read
     * this rather than recomputing, which is how four different "outstanding
     * balance" figures ended up on the same dashboard. Aggregate/grouped SQL
     * gets the identical rule from AmortizationSchedule::remainingPrincipalSql().
     *
     * Uses the eager-loaded schedules when the caller supplied them, so a
     * paginated list costs no extra query per row; falls back to one aggregate
     * query otherwise, so the value is still correct on a bare model instead of
     * silently reading 0.
     */
    protected function outstandingBalance(): Attribute
    {
        return Attribute::get(function (): float {
            $principalBalance = $this->relationLoaded('amortizationSchedules')
                ? $this->amortizationSchedules->sum(
                    fn ($schedule) => max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid)
                )
                : (float) ($this->amortizationSchedules()
                    ->reorder()
                    ->selectRaw('SUM('.AmortizationSchedule::remainingPrincipalSql().') as balance')
                    ->value('balance') ?? 0);

            return round((float) $principalBalance + (float) $this->insurance_remaining_balance, 2);
        });
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeForBranch($query, int $branchId)
    {
        // Qualified: the loans list joins `borrowers` to sort by borrower name,
        // and that table has a `branch_id` of its own.
        return $query->where('loans.branch_id', $branchId);
    }

    /**
     * Loans an operator has to chase: money out the door, still owing, and
     * holding at least one unpaid schedule that is LATE — past its due date and
     * past the grace period the loan's paperwork promised.
     *
     * Past due is not a `loans.status` and must never become one — see
     * self::ACTIVE_STATUSES for why. It is a property of the loan's
     * amortization schedule, so it lives here as a scope and reaches the API
     * as self::VIRTUAL_STATUS_PAST_DUE.
     *
     * Lateness itself is NOT defined here. It is
     * AmortizationSchedule::pastGraceSql(), shared with
     * RepaymentService::applyPenalties() and the loans:apply-penalties command,
     * so that the tab, the `overdue` stamp and the money charged for being late
     * cannot disagree about what late means. Do not inline the comparison.
     *
     * Membership matches ReportService::duePastDueQuery() on statuses —
     * self::COLLECTIBLE_STATUSES, `defaulted` included. The loans screen gives
     * `defaulted` no tab of its own, so before this it was reachable only under
     * All; surfacing it here gives collections one place to look and puts the
     * list and the report on the same set of loans.
     *
     * ONE deliberate difference from that report remains, and it must survive
     * any later attempt to unify the two:
     *
     *   This asks whether a schedule is LATE. The report asks whether it is
     *   DUE — `due_date <= today`, no grace — because its bucket is "Due AND
     *   Past Due" combined and it exists to show what is owed. An installment
     *   falling due today is due, not late, and one inside its grace window is
     *   due but not yet late; counting either here would report arrears early.
     *   The report's own `days_overdue` reads 0 for exactly those rows.
     *
     * This reads the due date rather than the schedule's `overdue` stamp, so it
     * is correct on the day a schedule turns late rather than whenever
     * something last re-stamped it.
     */
    public function scopePastDue($query)
    {
        return $query
            ->whereIn('loans.status', self::COLLECTIBLE_STATUSES)
            ->whereHas('amortizationSchedules', fn ($q) => $q
                ->whereIn('amortization_schedules.status', AmortizationSchedule::UNPAID_STATUSES)
                ->whereRaw(AmortizationSchedule::pastGraceSql(), [today()->toDateString()]));
    }

    /**
     * Filter by a single status, a comma-separated list, or an array of them.
     *
     * The loans list needs the list form: its Active tab is several statuses at
     * once (self::ACTIVE_STATUSES) and has to render from ONE request, not one
     * per status. A single value still behaves exactly as it did.
     *
     * Two virtual values are accepted alongside the real statuses:
     *
     * - `active` (self::VIRTUAL_STATUS_ACTIVE) expands to
     *   self::ACTIVE_STATUSES, so a caller may send it or spell the statuses
     *   out and get the same rows — either way the page total agrees with
     *   `meta.stats.active`.
     * - `past_due` (self::VIRTUAL_STATUS_PAST_DUE) resolves to
     *   self::scopePastDue() and agrees with `meta.stats.past_due` the same
     *   way.
     *
     * Prefer the shorthands: both sets are defined in one place here, and a
     * list pinned in the client cannot follow them.
     *
     * Note that only `active` can be flattened into the `whereIn` below.
     * `past_due` is schedule-derived, so it is a subquery, not a status — it
     * has to be branched on before the `whereIn` rather than mapped into it.
     * A list mixing the two stays an OR, which is what a comma already means
     * here, so `status=completed,past_due` is "completed OR past due".
     *
     * Values that are neither a status nor a virtual value are deliberately NOT
     * rejected, they simply match nothing. That is what keeps a client still
     * sending the retired `current` working — an empty result for it, rather
     * than a 422 that takes the whole page down with it.
     *
     * @param  string|array<int, string>  $status
     */
    public function scopeForStatus($query, string|array $status)
    {
        $requested = collect(is_array($status) ? $status : explode(',', $status))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values();

        // `?status=` or `?status=,` means "no status filter", not "match
        // nothing" — whereIn([]) would hand back an empty page instead.
        if ($requested->isEmpty()) {
            return $query;
        }

        $wantsPastDue = $requested->contains(self::VIRTUAL_STATUS_PAST_DUE);

        $statuses = $requested
            ->reject(fn (string $value) => $value === self::VIRTUAL_STATUS_PAST_DUE)
            ->flatMap(fn (string $value) => $value === self::VIRTUAL_STATUS_ACTIVE
                ? self::ACTIVE_STATUSES
                : [$value])
            ->unique()
            ->values();

        if (! $wantsPastDue) {
            return $query->whereIn('loans.status', $statuses->all());
        }

        if ($statuses->isEmpty()) {
            return $query->pastDue();
        }

        return $query->where(fn ($q) => $q
            ->whereIn('loans.status', $statuses->all())
            ->orWhere(fn ($inner) => $inner->pastDue()));
    }
}
