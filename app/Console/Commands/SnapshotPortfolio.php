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
 * - `outstanding_principal`: LoanListTotals' principal still unpaid, the
 *   denominator of the day's PAR ratio (PAR n ÷ outstanding principal).
 * - `par_1_amount`, `par_30_amount`, `par_90_amount`: the outstanding
 *   principal of the loans whose oldest unpaid period is past due by 1 or
 *   more days (due before the as-of date), more than 30 days, or more than
 *   90 days: the common microfinance definitions (lead, 2026-11-02).
 *   Measured from the bare due date, grace ignored, the way
 *   ReportService::loanBalanceSummary() strikes its PAR 30.
 *
 * History is never rewritten. A run for today upserts, so the schedule can
 * re-run safely. `--date` may name a past day only: a day that already has a
 * row is refused unless `--force` is passed, and a future day is always
 * refused. A past day is a replay: every date comparison uses that day, but
 * payment state is the database's current one, and the command says so.
 * Nothing is backfilled on its own.
 */
#[Signature('portfolio:snapshot
    {--date= : A past day to record (Y-m-d); default today in the app timezone}
    {--force : Replace a past day that already has a row}')]
#[Description('Record the day\'s portfolio arrears (overdue count and amount, total and principal outstanding, PAR 1/30/90) for the dashboard sparkline')]
class SnapshotPortfolio extends Command
{
    /**
     * Each PAR bucket's cutoff, in days before the as-of date: a loan is in
     * the bucket when its oldest unpaid period is due strictly before it.
     * PAR 1 is 1 or more days late (cutoff 0, due before the as-of date);
     * PAR 30 and PAR 90 are more than 30 and 90 days late.
     *
     * @var array<string, int>
     */
    private const PAR_CUTOFF_DAYS = ['par_1_amount' => 0, 'par_30_amount' => 30, 'par_90_amount' => 90];

    public function handle(LoanListTotals $listTotals): int
    {
        $date = $this->option('date');

        if ($date !== null && ! $this->isDate((string) $date)) {
            $this->error('--date must be a date in Y-m-d form.');

            return self::INVALID;
        }

        $today = Carbon::today();
        $asOf = $date === null ? $today : Carbon::createFromFormat('!Y-m-d', (string) $date);
        $day = $asOf->toDateString();

        if ($asOf->gt($today)) {
            $this->error("Refused: {$day} is in the future. A snapshot records a day that has happened.");

            return self::FAILURE;
        }

        $isReplay = $asOf->lt($today);

        if ($isReplay && ! $this->option('force') && PortfolioDailySnapshot::query()->where('snapshot_date', $day)->exists()) {
            $this->error("Refused: {$day} already has a snapshot, and history is never rewritten. Pass --force to replace it.");

            return self::FAILURE;
        }

        $totals = $listTotals->for(
            Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES),
            $asOf,
        );

        $figures = [
            'overdue_loans_count' => $totals['overdue_count'],
            'overdue_amount' => $totals['overdue_amount'],
            'total_outstanding' => $totals['total_outstanding'],
            'outstanding_principal' => $totals['outstanding_principal'],
            ...$this->parAmounts($asOf),
        ];

        PortfolioDailySnapshot::query()->upsert(
            [['snapshot_date' => $day, ...$figures, 'created_at' => now()]],
            ['snapshot_date'],
            array_keys($figures),
        );

        if ($isReplay) {
            $this->warn("Replay: {$day} was recorded with today's payment state, not as that day ended. Payments made since then are counted as already paid on {$day}.");
        }

        $this->info(sprintf(
            'Portfolio snapshot for %s: %d overdue loans, overdue ₱%s, outstanding ₱%s.',
            $day,
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
     * the bucket's cutoff.
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
        foreach (self::PAR_CUTOFF_DAYS as $column => $days) {
            $select[] = "COALESCE(SUM(CASE WHEN per_loan.oldest_unpaid < ? THEN per_loan.principal ELSE 0 END), 0) as {$column}";
            $bindings[] = $asOf->copy()->subDays($days)->toDateString();
        }

        $row = DB::query()
            ->fromSub($perLoan, 'per_loan')
            ->selectRaw(implode(', ', $select), $bindings)
            ->first();

        $columns = array_keys(self::PAR_CUTOFF_DAYS);

        return array_map(
            fn (string $column): float => round((float) ($row->{$column} ?? 0), 2),
            array_combine($columns, $columns),
        );
    }

    private function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
