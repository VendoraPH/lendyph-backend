<?php

namespace App\Console\Commands;

use App\Services\TimezoneShift;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only blast-radius report for the UTC → Asia/Manila historical shift.
 *
 * Run this before deploying the migration to see exactly how many rows it would
 * move, per table. It never writes anything.
 */
#[Signature('timezone:shift-impact')]
#[Description('Report how many rows the historical UTC to Asia/Manila timestamp shift would move, per table')]
class ReportTimezoneShiftImpact extends Command
{
    public function handle(): int
    {
        $shift = new TimezoneShift(DB::connection());
        $cutover = $shift->cutover();

        // Historical rows stay far in the past after the shift, so the counts
        // below never fall to zero once it has run. Without this an operator
        // would reasonably conclude the migration never happened and re-run it.
        if ($this->reportExistingShift()) {
            return self::SUCCESS;
        }

        $this->info('Dry run — nothing is written.');
        $this->line("Cutover boundary (UTC): {$cutover->format('Y-m-d H:i:s')}");
        $this->line('Offset applied to rows at or before it: +'.TimezoneShift::OFFSET_MINUTES.' minutes');
        $this->newLine();

        $counts = $shift->impact($cutover);
        $affected = array_filter($counts);

        if ($affected === []) {
            $this->info('No rows predate the cutover. The migration would be a no-op.');

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Rows that would move', 'Columns'],
            collect($affected)
                ->map(fn (int $rows, string $table) => [
                    $table,
                    number_format($rows),
                    implode(', ', TimezoneShift::COLUMNS[$table]),
                ])
                ->values()
                ->all(),
        );

        $this->line('Total rows: '.number_format(array_sum($affected)));
        $this->newLine();

        $skipped = array_keys(array_diff_key($counts, $affected));

        if ($skipped !== []) {
            $this->line('Empty / unaffected tables: '.implode(', ', $skipped));
        }

        $this->newLine();
        $this->warn('Never shifted (calendar dates, credentials, epoch-integer infra tables):');

        foreach (TimezoneShift::EXCLUDED_COLUMNS as $table => $columns) {
            $this->line("  {$table}: ".implode(', ', $columns));
        }

        $this->line('  every DATE column (payment_date, due_date, start_date, maturity_date, ...)');
        $this->line('  sessions, jobs, job_batches, cache, cache_locks (epoch integers)');

        return self::SUCCESS;
    }

    /**
     * Report an already-recorded shift, if there is one.
     *
     * @return bool whether the shift has already been attempted
     */
    private function reportExistingShift(): bool
    {
        if (! Schema::hasTable('timezone_shifts')) {
            return false;
        }

        $marker = DB::table('timezone_shifts')->first();

        if ($marker === null) {
            return false;
        }

        if ($marker->status === 'completed') {
            $this->info("Already applied at {$marker->completed_at}.");
            $this->line("  Offset:        +{$marker->offset_minutes} minutes");
            $this->line("  Cutover (UTC): {$marker->cutover_at}");
            $this->line('  Cells moved:   '.number_format((int) $marker->cells_updated));

            if ($marker->details !== null) {
                $this->newLine();
                $this->table(
                    ['Table', 'Cells moved'],
                    collect(json_decode($marker->details, true) ?? [])
                        ->map(fn (int $cells, string $table) => [$table, number_format($cells)])
                        ->values()
                        ->all(),
                );
            }

            $this->newLine();
            $this->line('Nothing further to do. Do not re-run the migration.');

            return true;
        }

        $this->error("A shift started at {$marker->started_at} and never completed.");
        $this->line('The database is in a mixed state — some tables moved, some did not.');
        $this->line('Reconcile `timezone_shifts` by hand; do NOT re-run the migration.');

        return true;
    }
}
