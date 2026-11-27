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
 * Each scan reads rows updated up to a few seconds ago (LAG_SECONDS), from
 * an overlap window before the watermark (OVERLAP_SECONDS). A transaction
 * stamps `updated_at` when it writes a row, not when it commits, so a long
 * one (an import batch, a bulk penalty run) can commit rows stamped before
 * the last scan; the overlap rescans them. Flagging a member twice is
 * harmless. The cursors move and the members are flagged in one transaction,
 * so a failed scan moves nothing. The first scan of a table only sets its
 * watermark: the nightly run scores everyone anyway.
 *
 * Read only on the watched tables; it writes only the cursors and the
 * flagged members.
 */
final class ChangeScanner
{
    public const LAG_SECONDS = 5;

    public const OVERLAP_SECONDS = 120;

    /**
     * Flag every member changed since the last scan.
     *
     * @return int how many members were flagged
     */
    public function scan(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $upper = $now->copy()->subSeconds(self::LAG_SECONDS);
        $flagged = [];
        $cursors = [];

        foreach ($this->sources() as $source => [$query, $column, $borrowerColumn, $reason]) {
            $cursor = CreditScoringCursor::query()->firstOrCreate(['source' => $source]);
            $cursors[] = $cursor;

            if ($cursor->watermark === null || $upper->lte($cursor->watermark)) {
                continue;
            }

            $ids = $query()
                ->where($column, '>', $cursor->watermark->copy()->subSeconds(self::OVERLAP_SECONDS))
                ->where($column, '<=', $upper)
                ->distinct()
                ->pluck($borrowerColumn);

            foreach ($ids as $id) {
                $flagged[(int) $id] ??= $reason;
            }
        }

        DB::transaction(function () use ($cursors, $upper, $flagged, $now) {
            foreach ($cursors as $cursor) {
                if ($cursor->watermark === null || $upper->gt($cursor->watermark)) {
                    $cursor->update(['watermark' => $upper]);
                }
            }

            $this->flag($flagged, $now);
        });

        return count($flagged);
    }

    /**
     * Flag members for the minute job. A member already flagged keeps the
     * later of their `flagged_at` and `$at`: a newer change moves the flag
     * forward, and a member the minute job is holding back after a failure
     * keeps their retry time, so the overlap rescans never undo a back-off.
     *
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
                ['reason', 'flagged_at' => DB::raw('greatest(`flagged_at`, values(`flagged_at`))'), 'updated_at'],
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
