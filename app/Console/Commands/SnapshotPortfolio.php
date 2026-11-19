<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PortfolioDailySnapshot;
use App\Models\PortfolioSnapshotLine;
use App\Models\PortfolioSnapshotRun;
use App\Services\Dashboard\PortfolioSnapshotLines;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records one day's portfolio arrears in `portfolio_daily_snapshots`
 * (decision 2d, Arvin, 2026-11-02), with one line per branch × loan product
 * in `portfolio_snapshot_lines` and the run in `portfolio_snapshot_runs`
 * (Phase 6 EB-B). Scheduled daily at 23:55 Asia/Manila in routes/console.php,
 * so each row is the day as it ended.
 *
 * Over the collectible loans (released, ongoing, defaulted), by
 * PortfolioSnapshotLines (the reports' own services):
 * - `overdue_loans_count` and `overdue_amount`: LoanListTotals' grace rule,
 *   the same figures as the dashboard's overdue card and GET /loans
 *   `meta.totals` (unpaid periods past the due date plus the loan's grace).
 * - `total_outstanding`: LoanListTotals' everything owed (principal,
 *   interest, penalty and insurance premium owed).
 * - `outstanding_principal`: LoanListTotals' principal still unpaid, the
 *   denominator of the day's PAR ratio (PAR n ÷ outstanding principal).
 * - `par_1/30/60/90_amount` and `_loans`: PortfolioRisk, the outstanding
 *   principal of the loans whose oldest unpaid period is past due by 1 or
 *   more days, or more than 30, 60 or 90 days, and how many. Measured from
 *   the bare due date, grace ignored, the way the Portfolio Summary strikes
 *   its PAR.
 * - `loan_count` and `active_loan_count` (released and ongoing).
 *
 * The lines must add up to the organisation row, figure by figure. When they
 * don't, the row is kept without lines (`line_count` null), the run is logged
 * as a mismatch with the figures that differed, and the command fails, so
 * the scheduler's output shows it. A run that throws writes nothing and is
 * logged as failed.
 *
 * History is never rewritten. A run for today upserts, so the schedule can
 * re-run safely, and replaces the day's lines. `--date` may name a past day
 * only: a day that already has a row is refused unless `--force` is passed,
 * and a future day is always refused. A past day is a replay: every date
 * comparison uses that day, but payment state is the database's current one,
 * and the command says so. Nothing is backfilled on its own.
 */
#[Signature('portfolio:snapshot
    {--date= : A past day to record (Y-m-d); default today in the app timezone}
    {--force : Replace a past day that already has a row}')]
#[Description('Record the day\'s portfolio figures (overdue, outstanding, PAR 1/30/60/90, loan counts), organisation-wide and per branch and loan product, for the dashboards')]
class SnapshotPortfolio extends Command
{
    public const MISMATCH_MESSAGE = 'The branch and product lines did not add up to the day\'s portfolio, so they were not kept.';

    public function handle(PortfolioSnapshotLines $snapshot): int
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

        $startedAt = now();

        try {
            $figures = $snapshot->organisation($asOf);
            $lines = $snapshot->build($asOf);
            $differences = $snapshot->differences($figures, $lines);
            $kept = $differences === [];

            DB::transaction(function () use ($day, $figures, $lines, $kept): void {
                PortfolioDailySnapshot::query()->upsert(
                    [['snapshot_date' => $day, ...$figures, 'line_count' => $kept ? count($lines) : null, 'created_at' => now()]],
                    ['snapshot_date'],
                    [...array_keys($figures), 'line_count'],
                );

                PortfolioSnapshotLine::query()->where('snapshot_date', $day)->delete();

                if ($kept) {
                    $createdAt = now();
                    foreach (array_chunk($lines, 500) as $chunk) {
                        PortfolioSnapshotLine::query()->insert(array_map(
                            fn (array $line): array => ['snapshot_date' => $day, ...$line, 'created_at' => $createdAt],
                            $chunk,
                        ));
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->logRun($day, $isReplay, PortfolioSnapshotRun::STATUS_FAILED, $startedAt, null, null, 'The snapshot could not be recorded: '.$exception->getMessage());
            $this->error("The snapshot for {$day} could not be recorded: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if (! $kept) {
            $this->logRun($day, $isReplay, PortfolioSnapshotRun::STATUS_MISMATCH, $startedAt, null, $differences, self::MISMATCH_MESSAGE);
            $this->error("{$day}: ".self::MISMATCH_MESSAGE.' Differing: '.implode(', ', array_keys($differences)).'.');

            return self::FAILURE;
        }

        $this->logRun($day, $isReplay, PortfolioSnapshotRun::STATUS_SUCCEEDED, $startedAt, count($lines), null, null);

        if ($isReplay) {
            $this->warn("Replay: {$day} was recorded with today's payment state, not as that day ended. Payments made since then are counted as already paid on {$day}.");
        }

        $this->info(sprintf(
            'Portfolio snapshot for %s: %d overdue loans, overdue ₱%s, outstanding ₱%s, %d branch and product lines.',
            $day,
            $figures['overdue_loans_count'],
            number_format((float) $figures['overdue_amount'], 2),
            number_format((float) $figures['total_outstanding'], 2),
            count($lines),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $checks
     */
    private function logRun(string $day, bool $isReplay, string $status, Carbon $startedAt, ?int $lineCount, ?array $checks, ?string $message): void
    {
        PortfolioSnapshotRun::query()->create([
            'snapshot_date' => $day,
            'is_replay' => $isReplay,
            'status' => $status,
            'line_count' => $lineCount,
            'checks' => $checks,
            'message' => $message === null ? null : mb_substr($message, 0, 500),
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    private function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
