<?php

use App\Services\BranchAttributionBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Past share capital entries and members' GCash transactions take the branch
 * their posted journal is in (Phase 6 MB-BE3, D4; decision 6), once, on
 * deploy.
 *
 * Runs BranchAttributionBackfill::run() itself, the same code as
 * `branches:backfill-attribution`, so `php artisan
 * branches:backfill-attribution --dry-run` lists on a database exactly what
 * this will change there, writing nothing. A row is written only when its
 * journal proves a branch, guarded on still having none, with one audit row
 * each as a system change. Everything else is left as it is and logged, never
 * given the member's branch; `branches:attribution-report` lists it. A second
 * run changes nothing.
 *
 * down() does nothing: the branch is the one the books hold, and audit rows
 * are never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = app(BranchAttributionBackfill::class)->run(false);
        $summary = BranchAttributionBackfill::summary($result);

        Log::info($summary, [
            'share_capital_ids' => array_column($result['share_capital']['updated'], 'id'),
            'gcash_ids' => array_column($result['gcash']['updated'], 'id'),
        ]);

        foreach (['share_capital', 'gcash'] as $kind) {
            if ($result[$kind]['skipped'] !== []) {
                Log::warning("Branch attribution left {$kind} rows as they are; see branches:attribution-report.", [
                    'rows' => $result[$kind]['skipped'],
                ]);
            }
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    public function down(): void {}
};
