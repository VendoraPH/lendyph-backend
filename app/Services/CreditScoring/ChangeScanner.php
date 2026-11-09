<?php

namespace App\Services\CreditScoring;

use App\Models\CreditScoringCursor;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds members whose data changed since the last scan and flags them for
 * the minute job, from a watermark on `updated_at` per watched table:
 * payments, loans, schedule rows (penalties and lateness), members and
 * application details.
 *
 * Each scan reads rows updated after the watermark and up to a few seconds
 * ago (LAG_SECONDS), so a row written in the same second as the scan is
 * picked up by the next one rather than skipped. The first scan of a table
 * only sets its watermark: the nightly run scores everyone anyway.
 *
 * Read only on the watched tables; it writes only the cursors and the
 * flagged members.
 */
final class ChangeScanner
{
    public const LAG_SECONDS = 5;

    /**
     * Flag every member changed since the last scan.
     *
     * @return int how many members were flagged
     */
    public function scan(?Carbon $now = null): int
    {
        $upper = ($now ?? Carbon::now())->copy()->subSeconds(self::LAG_SECONDS);
        $flagged = [];

        foreach ($this->sources() as $source => [$query, $column, $borrowerColumn, $reason]) {
            $cursor = CreditScoringCursor::query()->firstOrCreate(['source' => $source]);

            if ($cursor->watermark === null) {
                $cursor->update(['watermark' => $upper]);

                continue;
            }

            if ($upper->lte($cursor->watermark)) {
                continue;
            }

            $ids = $query()
                ->where($column, '>', $cursor->watermark)
                ->where($column, '<=', $upper)
                ->distinct()
                ->pluck($borrowerColumn);

            foreach ($ids as $id) {
                $flagged[(int) $id] ??= $reason;
            }

            $cursor->update(['watermark' => $upper]);
        }

        $this->flag($flagged, $now ?? Carbon::now());

        return count($flagged);
    }

    /**
     * @param  array<int, string>  $reasons  borrower id => reason
     */
    public function flag(array $reasons, Carbon $at): void
    {
        $existing = DB::table('borrowers')->whereIn('id', array_keys($reasons))->pluck('id')->all();

        foreach (array_chunk($existing, 500) as $chunk) {
            DB::table('credit_score_dirty_members')->upsert(
                array_map(fn ($id) => [
                    'borrower_id' => $id,
                    'reason' => $reasons[$id],
                    'flagged_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ], $chunk),
                ['borrower_id'],
                ['reason', 'flagged_at', 'updated_at'],
            );
        }
    }

    /**
     * @return array<string, array{0: callable(): Builder, 1: string, 2: string, 3: string}>
     */
    private function sources(): array
    {
        $viaLoan = fn (string $table) => fn () => DB::table($table)->join('loans', 'loans.id', '=', "{$table}.loan_id");

        return [
            'repayments' => [$viaLoan('repayments'), 'repayments.updated_at', 'loans.borrower_id', 'Payment recorded or changed'],
            'loans' => [fn () => DB::table('loans'), 'loans.updated_at', 'loans.borrower_id', 'Loan changed'],
            'amortization_schedules' => [$viaLoan('amortization_schedules'), 'amortization_schedules.updated_at', 'loans.borrower_id', 'Schedule changed'],
            'borrowers' => [fn () => DB::table('borrowers'), 'borrowers.updated_at', 'borrowers.id', 'Member details changed'],
            'credit_application_profiles' => [$viaLoan('credit_application_profiles'), 'credit_application_profiles.updated_at', 'loans.borrower_id', 'Application details changed'],
        ];
    }
}
