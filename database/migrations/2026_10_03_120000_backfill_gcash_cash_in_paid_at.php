<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off GCashCashInPaidAtBackfill and the
     * GCashTransaction model: this migration has to write on its last
     * deployment exactly what it wrote on its first, whatever those classes
     * become. Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDIT_ACTION = 'paid_at_backfilled';

    private const AUDITABLE_TYPE = 'App\Models\GCashTransaction';

    /**
     * Set `paid_at` on the Cash In transactions recorded as paid without one.
     *
     * The same selection, skips and audit rows as GCashCashInPaidAtBackfill,
     * whose docblock explains them, written here with the query builder alone
     * so the migration depends on no application class. BackfillGCashCashInPaidAtTest
     * holds the two to the same outcome.
     * `php artisan gcash:backfill-cash-in-paid-at --dry-run` shows what this
     * will change on a database before it is deployed there.
     *
     * Only rows that are `cash_in`, `paid` and have no `paid_at` are read, and
     * a fixed row no longer matches, so running it again changes nothing. Each
     * row written gets one audit row as a system change (no user), and any row
     * whose `created_at` cannot be trusted as the payment time is left alone and
     * listed with the reason.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $result = ['updated' => [], 'skipped' => []];

        DB::table('gcash_transactions')
            ->select(['id', 'reference_no', 'created_at', 'paid_by_user_id'])
            ->where('type', 'cash_in')
            ->where('status', 'paid')
            ->whereNull('paid_at')
            ->chunkById(500, function (Collection $rows) use (&$result): void {
                $changes = [];

                foreach ($rows as $row) {
                    $reason = match (true) {
                        $row->created_at === null => 'created_at is unknown',
                        $row->paid_by_user_id !== null => 'it names who marked it paid, so it was paid after it was recorded',
                        default => null,
                    };

                    if ($reason !== null) {
                        $result['skipped'][] = ['id' => (int) $row->id, 'reference_no' => $row->reference_no, 'reason' => $reason];

                        continue;
                    }

                    $changes[] = $row;
                }

                foreach (DB::transaction(fn (): array => $this->write($changes)) as $row) {
                    $result['updated'][] = ['id' => (int) $row->id, 'reference_no' => $row->reference_no, 'paid_at' => $row->created_at];
                }
            });

        $summary = sprintf(
            'gcash cash_in paid_at backfill: %d updated, %d skipped',
            count($result['updated']),
            count($result['skipped']),
        );

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

    /**
     * Set `paid_at` on each row and record it. Guarded on the row still being
     * a paid Cash In with no `paid_at`, so a row that changed since it was read
     * is left as it now is.
     *
     * @param  list<object>  $rows
     * @return list<object> the rows actually written
     */
    private function write(array $rows): array
    {
        $written = [];
        $now = now();

        foreach ($rows as $row) {
            $updated = DB::table('gcash_transactions')
                ->where('id', $row->id)
                ->where('type', 'cash_in')
                ->where('status', 'paid')
                ->whereNull('paid_at')
                ->whereNull('paid_by_user_id')
                ->where('created_at', $row->created_at)
                ->update(['paid_at' => $row->created_at, 'updated_at' => $now]);

            if ($updated === 0) {
                continue;
            }

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => self::AUDIT_ACTION,
                'auditable_type' => self::AUDITABLE_TYPE,
                'auditable_id' => $row->id,
                'old_values' => json_encode(['paid_at' => null]),
                'new_values' => json_encode(['paid_at' => Carbon::parse($row->created_at)->toIso8601String()]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Cash In %s was recorded as paid without a paid time. Set it to when it was recorded, %s.',
                    $row->reference_no,
                    $row->created_at,
                ),
                'created_at' => $now,
            ]);

            $written[] = $row;
        }

        return $written;
    }
};
