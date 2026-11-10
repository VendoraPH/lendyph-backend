<?php

namespace App\Services\Cda;

use App\Models\AmortizationSchedule;
use App\Models\CdaReportingPeriod;
use App\Services\Accounting\AllowanceCalculator;
use App\Services\Accounting\LoanAgingBuilder;
use App\Services\Accounting\Money;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The year-end capture of the loan book (CDA design §7). Loan balances can't
 * be read for a past date afterwards (LoanListTotals uses current unpaid
 * figures), so at 23:58 Manila on Dec 31 this keeps, on the year's reporting
 * period (`year_end_capture`):
 * - `allowance`: AllowanceCalculator::forDate(Dec 31), the buckets per
 *   branch with the required allowance, unchanged;
 * - `balances`: the 1200 allowance balance per branch and on journals with
 *   no branch, as of Dec 31 (AllowanceCalculator::balances());
 * - `loans`: each loan's aging row (LoanAgingBuilder::rows()), without the
 *   member's name, plus the interest due and unpaid on Dec 31 (a memo: the
 *   books are cash basis);
 * - `rates` and `totals` (required, booked, gap = required − booked).
 * Money is integer centavos, as AllowanceCalculator gives it.
 *
 * The period is opened (as the system) when there is none. The first capture
 * stands: a second run for the year changes nothing. One system audit row.
 * Only the scheduled run on Dec 31 is `exact`; a backfill on a later day is
 * kept with `exact: false` (C1 review, H2).
 */
final class YearEndCapture
{
    public const TIMEZONE = 'Asia/Manila';

    public function __construct(
        private readonly AllowanceCalculator $allowance,
        private readonly LoanAgingBuilder $aging,
        private readonly PeriodOpener $opener,
    ) {}

    /**
     * Capture the year ending on `$asOf` (a Dec 31).
     *
     * - On that Dec 31 itself (Manila) the capture is exact: the balances read
     *   are the year-end ones.
     * - A later date needs `$backfill`: the loan balances read are the ones
     *   on the capture day, not on Dec 31, so it is kept with `exact: false`
     *   and a basis note saying so.
     * - A Dec 31 still to come is refused.
     *
     * @return array{period: CdaReportingPeriod, captured: bool}
     *
     * @throws InvalidArgumentException for a date that isn't Dec 31, a future one, or a past one without $backfill
     */
    public function capture(string $asOf, bool $backfill = false): array
    {
        if (preg_match('/^(\d{4})-12-31$/', $asOf, $match) !== 1) {
            throw new InvalidArgumentException("The year-end capture is taken on December 31, not {$asOf}.");
        }

        $today = Carbon::now(self::TIMEZONE)->toDateString();

        if ($asOf > $today) {
            throw new InvalidArgumentException("December 31, {$match[1]} hasn't come yet in Manila (today is {$today}); it can't be captured before it ends.");
        }

        $exact = $asOf === $today;

        if (! $exact && ! $backfill) {
            throw new InvalidArgumentException("{$asOf} has passed, so its balances can't be read any more. Run it again with --backfill to keep today's balances, marked as not exact.");
        }

        $period = $this->opener->ensure((int) $match[1]);

        return DB::transaction(function () use ($period, $asOf, $exact, $today): array {
            /** @var CdaReportingPeriod $locked */
            $locked = CdaReportingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($locked->year_end_captured_at !== null) {
                return ['period' => $locked, 'captured' => false];
            }

            $capture = $this->build($asOf, $exact, $today);

            AuditLogService::withoutModelAuditing(fn () => $locked->forceFill([
                'year_end_capture' => $capture,
                'year_end_captured_at' => now(),
            ])->save());

            AuditLogService::log('year_end_captured', $locked, null, [
                'as_of' => $asOf,
                'exact' => $exact,
                'loan_count' => $capture['totals']['loan_count'],
                'required' => $capture['totals']['required'],
                'allowance_booked' => $capture['totals']['allowance_booked'],
                'provision_gap' => $capture['totals']['provision_gap'],
            ], sprintf(
                'Year-end loan capture for %d%s: %d loans, required allowance %s, booked %s.',
                $locked->year,
                $exact ? '' : " (backfilled on {$today}, not exact)",
                $capture['totals']['loan_count'],
                Money::format($capture['totals']['required']),
                Money::format($capture['totals']['allowance_booked']),
            ));

            return ['period' => $locked, 'captured' => true];
        });
    }

    /** @return array<string, mixed> */
    private function build(string $asOf, bool $exact, string $today): array
    {
        $allowance = $this->allowance->forDate($asOf);
        $balances = $this->allowance->balances($asOf);
        $rows = $this->aging->rows($asOf);
        $interest = $this->unpaidInterestDue(array_map(fn (array $row) => (int) $row['loan_id'], $rows), $asOf);

        $loans = array_map(fn (array $row): array => [
            'loan_id' => (int) $row['loan_id'],
            'account_number' => $row['account_number'],
            'member_id' => (int) $row['member']['id'],
            'branch_id' => $row['branch']['id'] ?? null,
            'bucket' => $row['bucket'],
            'days_past_due' => (int) $row['days_past_due'],
            'oldest_late_due_date' => $row['oldest_late_due_date'],
            'is_past_due' => (bool) $row['is_past_due'],
            'outstanding_principal' => (int) $row['outstanding_principal'],
            'total_outstanding' => (int) $row['total_outstanding'],
            'past_due_amount' => (int) $row['past_due_amount'],
            'unpaid_interest_due' => $interest[(int) $row['loan_id']] ?? 0,
        ], $rows);

        $booked = array_sum($balances['branches']) + (int) $balances['unassigned'];
        $required = (int) $allowance['totals']['required'];

        return [
            'as_of' => $asOf,
            'exact' => $exact,
            'captured_at' => now()->toIso8601String(),
            'basis_note' => $exact
                ? AllowanceCalculator::BASIS_NOTE
                : AllowanceCalculator::BASIS_NOTE.'. Backfilled on '.$today.': the loan balances are the ones on '.$today.', not as of '.Carbon::parse($asOf)->format('M j, Y').'; the 1200 balances are as of '.$asOf.'.',
            'rates' => $this->allowance->rates(),
            'allowance' => $allowance,
            'balances' => ['branches' => $balances['branches'], 'unassigned' => (int) $balances['unassigned']],
            'loans' => $loans,
            'totals' => [
                'loan_count' => count($loans),
                'outstanding_principal' => array_sum(array_column($loans, 'outstanding_principal')),
                'unpaid_interest_due' => array_sum(array_column($loans, 'unpaid_interest_due')),
                'required' => $required,
                'allowance_booked' => $booked,
                'provision_gap' => $required - $booked,
            ],
        ];
    }

    /**
     * Interest on periods due on or before the date and still unpaid, per
     * loan, in centavos.
     *
     * @param  list<int>  $loanIds
     * @return array<int, int>
     */
    private function unpaidInterestDue(array $loanIds, string $asOf): array
    {
        if ($loanIds === []) {
            return [];
        }

        return AmortizationSchedule::query()
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereDate('due_date', '<=', $asOf)
            ->groupBy('loan_id')
            ->selectRaw('loan_id, COALESCE(SUM('.AmortizationSchedule::remainingInterestSql().'), 0) as interest')
            ->pluck('interest', 'loan_id')
            ->map(fn ($interest) => (int) Money::toCentavos((string) $interest))
            ->all();
    }
}
