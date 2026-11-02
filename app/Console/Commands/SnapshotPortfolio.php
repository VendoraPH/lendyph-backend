<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Services\LoanListTotals;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Records one day's portfolio arrears in `portfolio_daily_snapshots`
 * (decision 2d, Arvin, 2026-11-02). Scheduled daily at 23:55 Asia/Manila in
 * routes/console.php, so each row is the day as it ended.
 *
 * Over the collectible loans (released, ongoing, defaulted):
 * - `overdue_loans_count` and `overdue_amount`: LoanListTotals' grace rule,
 *   the same figures as the dashboard's overdue card and GET /loans
 *   `meta.totals` (unpaid periods past the due date plus the loan's grace).
 * - `total_outstanding`: LoanListTotals' everything owed (principal,
 *   interest, penalty and insurance premium owed).
 * - `par_1_amount`, `par_30_amount`, `par_90_amount`: the outstanding
 *   principal of the loans whose oldest unpaid period is more than 1, 30 or
 *   90 days past its due date. Measured from the bare due date, grace
 *   ignored, the way ReportService::loanBalanceSummary() strikes its PAR 30:
 *   PAR is a prudential ratio reported the same way everywhere.
 *
 * Idempotent: it upserts on `snapshot_date`, so a re-run of a day replaces
 * its figures and never adds a second row. `--date` sets the as-of date for
 * every date comparison; payment state is always the database's current one,
 * so a backdated run is not a history and nothing is backfilled.
 */
#[Signature('portfolio:snapshot {--date= : The day to record (Y-m-d), default today in the app timezone}')]
#[Description('Record the day\'s portfolio arrears (overdue count and amount, total outstanding, PAR 1/30/90) for the dashboard sparkline')]
class SnapshotPortfolio extends Command
{
    /** @var array<string, int> */
    private const PAR_DAYS = ['par_1_amount' => 1, 'par_30_amount' => 30, 'par_90_amount' => 90];

    public function handle(LoanListTotals $listTotals): int
    {
        $date = $this->option('date');

        if ($date !== null && ! $this->isDate((string) $date)) {
            $this->error('--date must be a date in Y-m-d form.');

            return self::INVALID;
        }

        $asOf = $date === null ? Carbon::today() : Carbon::createFromFormat('!Y-m-d', (string) $date);

        $totals = $listTotals->for(
            Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES),
            $asOf,
        );

        $figures = [
            'overdue_loans_count' => $totals['overdue_count'],
            'overdue_amount' => $totals['overdue_amount'],
            'total_outstanding' => $totals['total_outstanding'],
            ...$this->parAmounts($asOf),
        ];

        PortfolioDailySnapshot::query()->upsert(
            [['snapshot_date' => $asOf->toDateString(), ...$figures, 'created_at' => now()]],
            ['snapshot_date'],
            array_keys($figures),
        );

        $this->info(sprintf(
            'Portfolio snapshot for %s: %d overdue loans, overdue ₱%s, outstanding ₱%s.',
            $asOf->toDateString(),
            $figures['overdue_loans_count'],
            number_format($figures['overdue_amount'], 2),
            number_format($figures['total_outstanding'], 2),
        ));

        return self::SUCCESS;
    }

    /**
     * PAR 1, 30 and 90 in one grouped query: per collectible loan, its oldest
     * unpaid due date and its principal still unpaid, then each bucket sums
     * the principal of the loans whose oldest unpaid period is due before
     * the as-of date less the bucket's days.
     *
     * @return array<string, float>
     */
    private function parAmounts(Carbon $asOf): array
    {
        $unpaid = implode(', ', array_fill(0, count(AmortizationSchedule::UNPAID_STATUSES), '?'));

        $perLoan = DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->groupBy('amortization_schedules.loan_id')
            ->selectRaw(
                "MIN(CASE WHEN amortization_schedules.status IN ({$unpaid}) THEN amortization_schedules.due_date END) as oldest_unpaid,
                SUM(".AmortizationSchedule::remainingPrincipalSql().') as principal',
                AmortizationSchedule::UNPAID_STATUSES,
            );

        $select = [];
        $bindings = [];
        foreach (self::PAR_DAYS as $column => $days) {
            $select[] = "COALESCE(SUM(CASE WHEN per_loan.oldest_unpaid < ? THEN per_loan.principal ELSE 0 END), 0) as {$column}";
            $bindings[] = $asOf->copy()->subDays($days)->toDateString();
        }

        $row = DB::query()
            ->fromSub($perLoan, 'per_loan')
            ->selectRaw(implode(', ', $select), $bindings)
            ->first();

        return array_map(
            fn (string $column): float => round((float) ($row->{$column} ?? 0), 2),
            array_combine(array_keys(self::PAR_DAYS), array_keys(self::PAR_DAYS)),
        );
    }

    private function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
