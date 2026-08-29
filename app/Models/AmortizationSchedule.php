<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class AmortizationSchedule extends Model
{
    use HasFactory;

    /**
     * Statuses a schedule can be in while it still owes money.
     *
     * Anything outside this list is settled and must never appear in a
     * due/past-due, aging, or outstanding figure.
     */
    public const UNPAID_STATUSES = ['pending', 'partial', 'overdue'];

    /**
     * SQL for the principal a single schedule row still owes.
     *
     * Floored at zero per ROW on purpose: an overpayment recorded against one
     * period must not net off what another period still owes, which is exactly
     * how `SUM(principal_due) - SUM(principal_paid)` used to understate the
     * portfolio. Every aggregate outstanding figure must go through these.
     */
    public static function remainingPrincipalSql(): string
    {
        return 'GREATEST(principal_due - principal_paid, 0)';
    }

    public static function remainingInterestSql(): string
    {
        return 'GREATEST(interest_due - interest_paid, 0)';
    }

    public static function remainingPenaltySql(): string
    {
        return 'GREATEST(penalty_amount - penalty_paid, 0)';
    }

    /**
     * Principal + interest + penalty still owed on a single schedule row.
     *
     * Parenthesised so a caller can safely wrap or scale the whole expression.
     */
    public static function remainingTotalSql(): string
    {
        return '('.self::remainingPrincipalSql()
            .' + '.self::remainingInterestSql()
            .' + '.self::remainingPenaltySql().')';
    }

    /**
     * The one definition of LATE: past the due date AND past the grace period
     * the loan contractually grants.
     *
     * `grace_period_days` is copied from the product onto the loan at creation
     * (LoanService::createLoan()), printed on the promissory note and the
     * disclosure statement, and was for a long time honoured by nothing — every
     * caller compared a bare `due_date` against today, so a borrower inside the
     * grace window the paperwork promised them was penalised and labelled
     * overdue anyway. This method and self::pastGraceCutoff() are the only two
     * places that comparison may be written. Five hand-rolled copies of a date
     * comparison is exactly how the phantom `past_due` status happened.
     *
     * Note what this deliberately does NOT govern. Grace changes when a
     * borrower is PENALISED and when they are called LATE. It does not change
     * whether the money is OWED — so the Due/Past Due report
     * (ReportService::duePastDueQuery()) and auto-pay collection
     * (AutoPayService) still work off a bare `due_date`, and must. The
     * codebase already draws that line: see the report's `days_overdue`, which
     * reads 0 for a row it still lists.
     *
     * Takes ONE binding, the as-of date. Both `amortization_schedules` and
     * `loans` must be in scope where this lands — inside a `whereHas` on either
     * side of the relation, or after an explicit join.
     *
     * Written as `due_date < DATE_SUB(?, ...)` rather than the algebraically
     * identical `DATE_ADD(due_date, ...) < ?` on purpose. This form leaves
     * `due_date` bare on the left and puts the arithmetic on the right, where
     * `loans.grace_period_days` is a constant per correlated probe — so it
     * still resolves to an index range on `amortization_schedules`. Wrapping
     * the column instead would forfeit that index on every row.
     *
     * A null `grace_period_days` is zero grace, which is the pre-existing
     * behaviour for every loan that has none.
     */
    public static function pastGraceSql(): string
    {
        return '`amortization_schedules`.`due_date` < DATE_SUB(?, INTERVAL COALESCE(`loans`.`grace_period_days`, 0) DAY)';
    }

    /**
     * The date a schedule must fall strictly before to be late — the PHP mirror
     * of self::pastGraceSql(), for callers that already hold the loan and so do
     * not need `loans` in the query at all.
     *
     * Shifting the cutoff rather than the column is what keeps those callers on
     * a bare, indexable `due_date` comparison.
     *
     * Null and 0 grace both return $asOf untouched, so a loan without a grace
     * period behaves exactly as it did before grace was honoured. The as-of
     * value is passed through at whatever precision it arrived with —
     * applyPenalties() is called with a payment timestamp as well as with
     * Carbon::today(), and normalising it here would silently move the
     * same-day boundary.
     */
    public static function pastGraceCutoff(?int $graceDays, Carbon $asOf): Carbon
    {
        return $asOf->copy()->subDays(max(0, (int) $graceDays));
    }

    /**
     * SQL for whether a schedule may be PENALISED at all — the pre-import
     * arrears exclusion.
     *
     * A loan migrated in from a cooperative's existing book arrives part-way
     * through its life, with due dates already months in the past, so it is
     * immediately overdue by every measure here. `loans.imported_arrears_baseline`
     * is the date the coop's own bookkeeping stops and ours starts: whatever
     * penalties they charged before it are already inside the balances they
     * handed over, and charging again double-bills a real member overnight.
     * A schedule due STRICTLY BEFORE the baseline is therefore a pre-import
     * arrear — not penalised, not stamped `overdue`, and not counted toward
     * defaulting.
     *
     * Note what this deliberately does NOT govern, exactly as with
     * self::pastGraceSql() above. The baseline changes when a borrower is
     * PENALISED and when they are called LATE. It does not change whether the
     * money is OWED — so Loan::scopePastDue(), the Due/Past Due report
     * (ReportService::duePastDueQuery()), the aging report and
     * LoanResource's `overdue_amount` all still see these schedules, and must.
     * The coop has to chase that money; it just may not be charged for it
     * twice. Do not penalise is not do not show.
     *
     * Written `IS NULL OR …` so every loan that was NOT imported — the entire
     * existing book, and everything originated here afterwards — is unaffected
     * by construction rather than by a caller remembering to branch.
     *
     * Takes NO bindings, unlike self::pastGraceSql(). Both
     * `amortization_schedules` and `loans` must be in scope where this lands —
     * inside a `whereHas` on either side of the relation, or after an explicit
     * join.
     *
     * `due_date` is left bare on the left of the comparison for the same
     * indexability reason pastGraceSql() gives: the baseline is a constant per
     * correlated probe, so this stays a range on
     * `amortization_schedules.due_date` instead of forfeiting the index.
     */
    public static function penalisableSql(): string
    {
        return '(`loans`.`imported_arrears_baseline` IS NULL '
            .'OR `amortization_schedules`.`due_date` >= `loans`.`imported_arrears_baseline`)';
    }

    /**
     * Whether one schedule may be penalised — the PHP mirror of
     * self::penalisableSql(), for callers that already hold the loan and so do
     * not need `loans` in the query at all.
     *
     * A null baseline is a loan that was not imported, which is every loan the
     * system originated itself, and returns true — so this is safe to call
     * unconditionally rather than behind an `isImported()` check.
     *
     * Both operands are DATE columns on the database side, so both are
     * compared at day precision here to keep the twin genuinely equivalent to
     * the SQL. That differs from self::pastGraceCutoff(), which passes its
     * as-of value through at whatever precision it arrived with — there the
     * as-of is a caller-supplied payment timestamp, not a stored DATE, and
     * normalising it would move the same-day boundary. Neither argument is
     * mutated.
     */
    public static function isPenalisable(?Carbon $baseline, Carbon $dueDate): bool
    {
        return $baseline === null
            || $dueDate->copy()->startOfDay()->gte($baseline->copy()->startOfDay());
    }

    /**
     * The already-loaded schedules that are LATE — still owing, and past both
     * the due date and the loan's grace period.
     *
     * The in-memory twin of self::pastGraceSql(), for the two callers that
     * present a borrower's arrears back to them from a collection they already
     * hold: LoanResource's `overdue_amount` on every row of the loans list, and
     * RepaymentService::getLoanSummary()'s `overdue_amount` /
     * `overdue_schedules_count`.
     *
     * It exists as one method rather than as the same one-line filter written
     * twice because those two figures are read side by side and must agree —
     * and because both used to be a bare `due_date < today`, which put money on
     * the screen labelled overdue for a loan the Past Due tab correctly said
     * was not yet late. A user seeing "₱5,000 overdue" on a row that no arrears
     * filter returns cannot tell which of the two is lying.
     *
     * Filters on status as well as date so a caller cannot pass a raw schedule
     * collection and quietly count settled rows as arrears.
     *
     * @param  Collection<int, self>  $schedules
     * @return Collection<int, self>
     */
    public static function lateUnpaid(Collection $schedules, ?int $graceDays, Carbon $asOf): Collection
    {
        $cutoff = self::pastGraceCutoff($graceDays, $asOf);

        return $schedules->filter(
            fn (self $schedule) => in_array($schedule->status, self::UNPAID_STATUSES, true)
                && $schedule->due_date->lt($cutoff)
        );
    }

    protected $fillable = [
        'loan_id',
        'period_number',
        'due_date',
        'principal_due',
        'interest_due',
        'total_due',
        'remaining_balance',
        'status',
        'principal_paid',
        'interest_paid',
        'penalty_amount',
        'penalty_paid',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal_due' => 'decimal:2',
            'interest_due' => 'decimal:2',
            'total_due' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'penalty_paid' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
