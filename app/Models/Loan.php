<?php

namespace App\Models;

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

    protected $fillable = [
        'loan_account_number',
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
            $nextNum = $last ? (int) substr($last->application_number, 3) + 1 : 1;
            $loan->application_number = 'LA-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
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
        return $query->where('branch_id', $branchId);
    }

    public function scopeForStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
