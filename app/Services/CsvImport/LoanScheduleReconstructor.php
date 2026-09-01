<?php

namespace App\Services\CsvImport;

use App\Models\Loan;
use App\Services\LoanService;
use Carbon\Carbon;

/**
 * Rebuilds a loan's amortization schedule from the four figures the coop's
 * export carries: principal, principal balance, total interest, interest
 * balance.
 *
 * Two rules govern everything here.
 *
 * ONE — the arithmetic is integer centavos end to end. Money never becomes a
 * float in this class except across the one unavoidable boundary described at
 * self::principalColumn(), and the residue is FORCED onto the last period so
 * that the sums hold by construction rather than by luck. These figures become
 * cooperative members' real balances; a centavo of drift is a member and a
 * bookkeeper disagreeing.
 *
 * TWO — the two invariants below must hold EXACTLY, because they are what the
 * rest of the application will read back:
 *
 *      SUM(GREATEST(principal_due - principal_paid, 0)) === Loan Balance
 *      SUM(GREATEST(interest_due  - interest_paid,  0)) === Interest Balance
 *
 * The left-hand sides are literally Loan::$outstanding_balance and
 * AmortizationSchedule::remainingInterestSql(). If they do not reproduce the
 * export's figures, the import has quietly restated the member's debt.
 */
class LoanScheduleReconstructor
{
    /**
     * Upper bound on periods. Both `loans.term` and
     * `amortization_schedules.period_number` are unsignedSmallInteger, and 3660
     * daily periods is ten years — past that the input is wrong, not long.
     */
    public const MAX_PERIODS = 3660;

    public function __construct(private readonly LoanService $loans = new LoanService) {}

    public function reconstruct(LoanReconstructionInput $input): ReconstructedSchedule
    {
        $notes = new RowNoteBag;

        if (! $this->inputsAreCoherent($input, $notes)) {
            return ReconstructedSchedule::failed($notes);
        }

        $term = $this->derivePeriodCount($input, $notes);

        if ($term === null) {
            return ReconstructedSchedule::failed($notes);
        }

        // `upon_maturity` is a bullet loan: LoanService::buildAmortizationPreview()
        // emits ONE row for it whatever the term, and `term` carries
        // months-until-maturity instead of a row count. Keeping that split here
        // is what makes an imported bullet loan structurally identical to a
        // released one.
        $rows = $input->frequency === 'upon_maturity' ? 1 : $term;

        $dueDates = $this->dueDates($input, $rows);
        $principalDue = $this->principalColumn($input, $term, $rows, $notes);
        $interestDue = $this->interestColumn($input->interestCentavos, $rows);

        $allocation = $this->allocate(
            $principalDue,
            $interestDue,
            $input->principalCentavos - $input->balanceCentavos,
            $input->interestCentavos - $input->interestBalanceCentavos,
        );

        if ($allocation === null) {
            $notes->fail('__loan', 'allocation_residue', 'The paid amounts implied by this loan\'s balances could not be spread across its schedule without money left over. The row was not imported rather than leaving a schedule that does not reconcile.');

            return ReconstructedSchedule::failed($notes);
        }

        $periods = $this->assemble($input, $dueDates, $principalDue, $interestDue, $allocation);
        $schedule = ReconstructedSchedule::built($term, $input->frequency, $input->maturityDate, $periods, $notes);

        if (! $this->invariantsHold($schedule, $input, $notes)) {
            return ReconstructedSchedule::failed($notes);
        }

        return $schedule;
    }

    /**
     * The preconditions the arithmetic below is only correct under.
     *
     * Re-checked here even though LoanRowNormalizer already checks them: this
     * is a public entry point, and an invariant that depends on the caller
     * having been careful is not an invariant.
     */
    private function inputsAreCoherent(LoanReconstructionInput $input, RowNoteBag $notes): bool
    {
        if ($input->principalCentavos <= 0) {
            $notes->fail('loan_amount', 'amount_not_positive', 'The loan amount must be greater than zero.');
        }

        if ($input->balanceCentavos < 0 || $input->balanceCentavos > $input->principalCentavos) {
            $notes->fail('loan_balance', 'balance_out_of_range', 'The loan balance must be between zero and the loan amount.');
        }

        if ($input->interestCentavos < 0) {
            $notes->fail('interest_amount', 'interest_negative', 'The interest amount cannot be negative.');
        }

        if ($input->interestBalanceCentavos < 0 || $input->interestBalanceCentavos > $input->interestCentavos) {
            $notes->fail('interest_balance', 'interest_balance_out_of_range', 'The interest balance must be between zero and the interest amount.');
        }

        return ! $notes->hasErrors();
    }

    /**
     * Derive `loans.term` from the DATES, not from "Term in Months".
     *
     * "Term in Months" is not `loans.term` and writing it there corrupts every
     * loan that is not monthly. `loans.term` is a PERIOD COUNT whose unit
     * follows `frequency` — see Loan::isOneMonthTerm() and
     * LoanService::computeMaturityDate(), which reads it as days for daily,
     * weeks for weekly, and only as months for monthly and upon_maturity. A
     * six-month WEEKLY loan is 26, and storing 6 would have the app believe the
     * loan matures in six weeks.
     *
     * The walk is done with LoanService::computeMaturityDate() itself, once per
     * candidate k, rather than by repeatedly applying a one-period step. That
     * is deliberate and it is the subtle part:
     *
     *  - computeMaturityDate() is the function the app will use AGAINST this
     *    value later — LoanService::updateLoan() recomputes maturity_date from
     *    (start_date, term, frequency) on every edit. Choosing k any other way
     *    means the maturity date silently moves the first time an operator
     *    opens the loan and saves it.
     *  - Repeated one-month steps do not equal one addMonths(k) call, because
     *    Carbon clamps to the shorter month and never recovers: a loan released
     *    31 January steps to 28 February, then to 28 March, and by month six it
     *    is three days adrift and the period count comes out one too high.
     *    addMonths(k) from the start date goes 31 Jan -> 29 Feb -> 31 Mar and
     *    lands on the real maturity date.
     *
     * For the four fixed-length frequencies the two approaches are identical
     * anyway, since every step is a constant number of days.
     */
    private function derivePeriodCount(LoanReconstructionInput $input, RowNoteBag $notes): ?int
    {
        $start = Carbon::parse($input->dateReleased)->startOfDay();
        $maturity = Carbon::parse($input->maturityDate)->startOfDay();

        if ($maturity->lte($start)) {
            $notes->fail('maturity_date', 'maturity_not_after_release', "The maturity date ({$input->maturityDate}) is not after the date released ({$input->dateReleased}).");

            return null;
        }

        for ($k = 1; $k <= self::MAX_PERIODS; $k++) {
            $periodDate = $this->periodDueDate($input, $k);

            if ($periodDate->lt($maturity)) {
                continue;
            }

            if (! $periodDate->eq($maturity)) {
                $notes->warn('maturity_date', 'maturity_off_schedule', "The maturity date {$input->maturityDate} does not fall exactly on a {$input->frequency} period from {$input->dateReleased} (the nearest is {$periodDate->toDateString()}). The last instalment was dated {$input->maturityDate} as the file says, and the loan recorded as {$k} periods.");
            }

            $this->warnIfTermInMonthsDisagrees($input, $k, $notes);

            return $k;
        }

        $notes->fail('maturity_date', 'term_too_long', 'This loan runs for more periods than the system stores (the limit is '.self::MAX_PERIODS.'). Check the date released and maturity date.');

        return null;
    }

    /**
     * "Term in Months" is not used to build anything, but a wide disagreement
     * with the dates means one of the three cells is wrong, and the operator
     * is the only one who can say which.
     */
    private function warnIfTermInMonthsDisagrees(LoanReconstructionInput $input, int $derived, RowNoteBag $notes): void
    {
        if ($input->termInMonths === null || ! in_array($input->frequency, ['monthly', 'upon_maturity'], true)) {
            return;
        }

        if ($input->termInMonths === $derived) {
            return;
        }

        $notes->warn('term_in_months', 'term_disagrees_with_dates', "The file says {$input->termInMonths} months but the dates {$input->dateReleased} to {$input->maturityDate} are {$derived} monthly periods. The dates were used.");
    }

    private function periodDueDate(LoanReconstructionInput $input, int $period): Carbon
    {
        return $this->loans
            ->computeMaturityDate($input->dateReleased, $period, $input->frequency)
            ->startOfDay();
    }

    /**
     * Period n's due date is the CSV's maturity date VERBATIM.
     *
     * That equality is not cosmetic. `loans.maturity_date` and the last
     * schedule row's `due_date` are read side by side all over the app — the
     * Due/Past Due report, the promissory note, the disclosure statement — and
     * a loan whose final instalment falls a day after its stated maturity looks
     * like a bug to every operator who sees it, forever.
     *
     * @return list<string>
     */
    private function dueDates(LoanReconstructionInput $input, int $rows): array
    {
        $dates = [];

        for ($k = 1; $k < $rows; $k++) {
            $dates[] = $this->periodDueDate($input, $k)->toDateString();
        }

        $dates[] = $input->maturityDate;

        return $dates;
    }

    /**
     * The principal column, taken from the app's own generator.
     *
     * LoanService::buildAmortizationPreview() is called on an UNSAVED Loan so
     * that an imported loan's principal ladder is produced by exactly the code
     * that produces a released loan's — straight, diminishing and bullet all
     * behave as they would have on the day the loan was released, and nothing
     * downstream can tell an imported schedule from a native one.
     *
     * That generator is float-based and is not ours to change, so this is the
     * ONE float boundary in the reconstruction path. It is crossed exactly
     * once, per period, via a fixed 2-decimal string rather than a raw cast,
     * and then closed: the last period is FORCED to
     *
     *      principal_due[n] = P - SUM(principal_due[1..n-1])
     *
     * so SUM(principal_due) === P holds by construction in integer arithmetic,
     * whatever the float column rounded to. Every figure after this point is an
     * integer.
     *
     * @return list<int>
     */
    private function principalColumn(LoanReconstructionInput $input, int $term, int $rows, RowNoteBag $notes): array
    {
        $preview = $this->loans->buildAmortizationPreview($this->stubLoan($input, $term));
        $column = [];
        $usable = count($preview) === $rows;

        if ($usable) {
            foreach ($preview as $period) {
                $centavos = $this->pesosToCentavos((string) number_format((float) ($period['principal_due'] ?? 0), 2, '.', ''));

                if ($centavos < 0) {
                    $usable = false;

                    break;
                }

                $column[] = $centavos;
            }
        }

        if ($usable) {
            $allocatedBeforeLast = array_sum(array_slice($column, 0, $rows - 1));
            $last = $input->principalCentavos - $allocatedBeforeLast;

            if ($last >= 0) {
                $column[$rows - 1] = $last;

                return $column;
            }
        }

        // The generated ladder cannot be reconciled with the principal the file
        // reports — the loan was probably restructured or partially written off
        // outside the system. A flat ladder still satisfies SUM === P, which is
        // the invariant the member's balance depends on, so the loan imports
        // with its shape flagged rather than being refused.
        $notes->warn('__loan', 'principal_ladder_fallback', 'The standard payment schedule for this loan does not add up to the loan amount in the file, so the principal was spread evenly across the periods instead. The total is exact; the per-period split may not match the original paperwork.');

        return $this->flatLadder($input->principalCentavos, $rows);
    }

    /**
     * Interest is taken from the FILE and the app's interest formula is
     * deliberately ignored.
     *
     * This is an explicit decision by the coop, and it is the right one: the
     * member's passbook says what it says, and recomputing interest from rate
     * and term would produce a figure that is arguably more correct and
     * definitely different from the one they have been paying against.
     *
     *      i[k] = floor(I / n) for k < n,  i[n] = I - (n-1) * floor(I / n)
     *
     * so SUM(interest_due) === I exactly, with the remainder landing on the
     * final period.
     *
     * @return list<int>
     */
    public function interestColumn(int $interestCentavos, int $rows): array
    {
        return $this->flatLadder($interestCentavos, $rows);
    }

    /**
     * @return list<int>
     */
    private function flatLadder(int $total, int $rows): array
    {
        $base = intdiv($total, $rows);
        $column = array_fill(0, $rows - 1, $base);
        $column[] = $total - $base * ($rows - 1);

        return $column;
    }

    /**
     * Spread what has been paid over the schedule OLDEST FIRST.
     *
     * Principal and interest are allocated independently, and they will often
     * run out on different periods — whenever IB/I differs from B/P, which is
     * most loans that have ever been re-aged or had a payment applied
     * interest-first. That is not a bug to be smoothed over; it is the
     * arithmetic consequence of trusting two figures the coop reports
     * separately.
     *
     * Returns null when either allocation leaves a residue, which means the
     * schedule cannot absorb the payments the balances imply. The caller must
     * abort the row: a schedule that does not reconcile is worse than a loan
     * that failed to import, because only one of the two is visible.
     *
     * @param  list<int>  $principalDue
     * @param  list<int>  $interestDue
     * @return array{principal_paid: list<int>, interest_paid: list<int>}|null
     */
    public function allocate(array $principalDue, array $interestDue, int $remainingPrincipal, int $remainingInterest): ?array
    {
        if ($remainingPrincipal < 0 || $remainingInterest < 0) {
            return null;
        }

        $principalPaid = [];
        $interestPaid = [];

        foreach ($principalDue as $index => $due) {
            $applied = min($due, $remainingPrincipal);
            $principalPaid[] = $applied;
            $remainingPrincipal -= $applied;

            $interestApplied = min($interestDue[$index] ?? 0, $remainingInterest);
            $interestPaid[] = $interestApplied;
            $remainingInterest -= $interestApplied;
        }

        if ($remainingPrincipal !== 0 || $remainingInterest !== 0) {
            return null;
        }

        return ['principal_paid' => $principalPaid, 'interest_paid' => $interestPaid];
    }

    /**
     * @param  list<string>  $dueDates
     * @param  list<int>  $principalDue
     * @param  list<int>  $interestDue
     * @param  array{principal_paid: list<int>, interest_paid: list<int>}  $allocation
     * @return list<ReconstructedPeriod>
     */
    private function assemble(
        LoanReconstructionInput $input,
        array $dueDates,
        array $principalDue,
        array $interestDue,
        array $allocation,
    ): array {
        $periods = [];
        $principalScheduledSoFar = 0;

        foreach ($principalDue as $index => $due) {
            $principalScheduledSoFar += $due;
            $principalPaid = $allocation['principal_paid'][$index];
            $interestPaid = $allocation['interest_paid'][$index];

            $periods[] = new ReconstructedPeriod(
                periodNumber: $index + 1,
                dueDate: $dueDates[$index],
                principalDue: $due,
                interestDue: $interestDue[$index],
                principalPaid: $principalPaid,
                interestPaid: $interestPaid,
                remainingBalance: max(0, $input->principalCentavos - $principalScheduledSoFar),
                status: $this->statusFor($due, $interestDue[$index], $principalPaid, $interestPaid),
            );
        }

        return $periods;
    }

    /**
     * A period is `paid` only when BOTH columns are settled.
     *
     * The case that matters is principal fully paid but interest still short.
     * Marking that row `paid` takes it out of
     * AmortizationSchedule::UNPAID_STATUSES, and the moment it leaves that list
     * the Due/Past Due report, AutoPayService and RepaymentService all stop
     * seeing interest the borrower genuinely still owes — the money does not
     * appear as forgiven, it simply becomes invisible, on a row that still
     * holds interest_due > interest_paid. It must be `partial`.
     */
    private function statusFor(int $principalDue, int $interestDue, int $principalPaid, int $interestPaid): string
    {
        if ($principalPaid >= $principalDue && $interestPaid >= $interestDue) {
            return 'paid';
        }

        return ($principalPaid > 0 || $interestPaid > 0) ? 'partial' : 'pending';
    }

    /**
     * The final gate: the rebuilt schedule must read back as the file's own
     * figures, to the centavo, through the same expressions the application
     * uses.
     */
    private function invariantsHold(ReconstructedSchedule $schedule, LoanReconstructionInput $input, RowNoteBag $notes): bool
    {
        $checks = [
            ['principal_due', $schedule->totalPrincipalDue(), $input->principalCentavos, 'scheduled principal', 'the loan amount'],
            ['interest_due', $schedule->totalInterestDue(), $input->interestCentavos, 'scheduled interest', 'the interest amount'],
            ['loan_balance', $schedule->outstandingPrincipal(), $input->balanceCentavos, 'the principal left outstanding', 'the loan balance'],
            ['interest_balance', $schedule->outstandingInterest(), $input->interestBalanceCentavos, 'the interest left outstanding', 'the interest balance'],
        ];

        foreach ($checks as [$field, $actual, $expected, $whatWeBuilt, $whatTheFileSays]) {
            if ($actual === $expected) {
                continue;
            }

            $notes->fail($field, 'invariant_violation', sprintf(
                'The rebuilt schedule does not reconcile: %s comes to %s but %s is %s. The row was not imported.',
                $whatWeBuilt,
                number_format($actual / 100, 2),
                $whatTheFileSays,
                number_format($expected / 100, 2),
            ));
        }

        return ! $notes->hasErrors();
    }

    /**
     * An unsaved Loan carrying only what buildAmortizationPreview() reads.
     *
     * Never saved, never touched by a model event; it exists so the app's own
     * generator can be called without duplicating it.
     */
    private function stubLoan(LoanReconstructionInput $input, int $term): Loan
    {
        return new Loan([
            'principal_amount' => sprintf('%d.%02d', intdiv($input->principalCentavos, 100), $input->principalCentavos % 100),
            'interest_rate' => $input->interestRate,
            'interest_method' => $input->interestMethod,
            'frequency' => $input->frequency,
            'term' => $term,
            'start_date' => $input->dateReleased,
            'maturity_date' => $input->maturityDate,
        ]);
    }

    /**
     * Exact "1234.56" -> 123456. String arithmetic only; no float ever holds
     * this value.
     */
    private function pesosToCentavos(string $pesos): int
    {
        $negative = str_starts_with($pesos, '-');
        $pesos = ltrim($pesos, '-');

        [$whole, $fraction] = array_pad(explode('.', $pesos, 2), 2, '0');
        $centavos = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$centavos : $centavos;
    }
}
