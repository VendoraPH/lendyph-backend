<?php

namespace App\Services;

use App\Models\GCashTransaction;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Give every Cash In recorded as paid the `paid_at` it should have had.
 *
 * Until 2026-10-03 GCashService::createTransaction() saved a Cash In that was
 * not left pending as `paid` without setting `paid_at`; only markPaid(), which
 * settles a pending one later, stamped it. Such a transaction was paid at the
 * counter when it was recorded, so its `created_at` is when it was paid.
 *
 * It writes only those rows (`cash_in`, `paid`, `paid_at` NULL), one audit row
 * each as a system change (no user) against the transaction, and leaves alone,
 * with the reason, any row where `created_at` cannot be trusted to be the
 * payment time: one with no `created_at`, and one that names who marked it
 * paid, which only markPaid() does and which means it was paid later. A fixed
 * row no longer matches, so running it again changes nothing.
 *
 * The 2026_10_03_120000 migration does the same on deploy, written out with
 * the query builder so that it depends on no application class; keep the two
 * in step (BackfillGCashCashInPaidAtTest compares them).
 * `php artisan gcash:backfill-cash-in-paid-at --dry-run` shows what it will
 * change on a database first.
 */
class GCashCashInPaidAtBackfill
{
    public const AUDIT_ACTION = 'paid_at_backfilled';

    /**
     * Fix every row that can be fixed, or with `$dryRun` only say which.
     *
     * @return array{updated: list<array{id: int, reference_no: string, paid_at: string}>, skipped: list<array{id: int, reference_no: string, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $result = ['updated' => [], 'skipped' => []];

        $this->candidates()->chunkById(500, function (Collection $rows) use ($dryRun, &$result): void {
            $changes = [];

            foreach ($rows as $row) {
                $reason = $this->skipReason($row);

                if ($reason !== null) {
                    $result['skipped'][] = ['id' => (int) $row->id, 'reference_no' => $row->reference_no, 'reason' => $reason];

                    continue;
                }

                $changes[] = $row;
            }

            $written = $dryRun ? $changes : DB::transaction(fn (): array => $this->write($changes));

            foreach ($written as $row) {
                $result['updated'][] = ['id' => (int) $row->id, 'reference_no' => $row->reference_no, 'paid_at' => $row->created_at];
            }
        });

        return $result;
    }

    /**
     * The one line the migration prints to the deploy log.
     *
     * @param  array{updated: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        return sprintf(
            'gcash cash_in paid_at backfill: %d updated, %d skipped',
            count($result['updated']),
            count($result['skipped']),
        );
    }

    private function candidates(): Builder
    {
        return DB::table('gcash_transactions')
            ->select(['id', 'reference_no', 'created_at', 'paid_by_user_id'])
            ->where('type', 'cash_in')
            ->where('status', 'paid')
            ->whereNull('paid_at');
    }

    private function skipReason(object $row): ?string
    {
        return match (true) {
            $row->created_at === null => 'created_at is unknown',
            $row->paid_by_user_id !== null => 'it names who marked it paid, so it was paid after it was recorded',
            default => null,
        };
    }

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
                'auditable_type' => (new GCashTransaction)->getMorphClass(),
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
}
