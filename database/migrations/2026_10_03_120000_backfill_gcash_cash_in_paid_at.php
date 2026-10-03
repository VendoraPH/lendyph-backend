<?php

use App\Services\GCashCashInPaidAtBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Set `paid_at` on the Cash In transactions recorded as paid without one.
     *
     * The logic, and what it refuses to guess, is GCashCashInPaidAtBackfill's.
     * `php artisan gcash:backfill-cash-in-paid-at --dry-run` shows what this
     * will change on a database before it is deployed there.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $result = app(GCashCashInPaidAtBackfill::class)->run(dryRun: false);
        $summary = GCashCashInPaidAtBackfill::summary($result);

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Cash In transactions recorded as paid without a paid time were left unchanged.', [
                'rows' => $result['skipped'],
            ]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo. Clearing `paid_at` again would restore the bug's state,
     * and each change is on record in the audit log.
     */
    public function down(): void {}
};
