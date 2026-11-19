<?php

namespace App\Services;

use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\SavingsTransaction;
use App\Models\ShareCapitalLedger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Give past share capital rows and members' GCash rows the branch their posted
 * journal proves (Phase 6 MB-BE3, D4; decision 6 amends decision 8).
 *
 * New rows are stamped when written (BranchAttribution). Rows written before
 * that have none. Each one's journal is the one AutomaticPoster keyed on it:
 *
 * - a share capital entry paid in or out on its own: its own `share_capital`
 *   journal;
 * - a payment's build-up, or its void: the payment's `loan_collection`
 *   journal (a void reverses it in the same branch);
 * - a credit withheld at release: the loan's `loan_release` journal;
 * - a transfer from savings: the savings row's `savings` journal;
 * - a member's GCash Cash In or Cash Out: its `cash_in` or `cash_out`
 *   journal.
 *
 * Only the original journal counts (not a reversal), posted or since
 * reversed: either way it says which branch's books the money went into.
 *
 * A row is written only when that journal exists and names a branch. It is
 * skipped, with the reason, when there is no posted journal (books were not
 * kept then, an imported loan, a Cash In still pending), when the journal has
 * no branch, or when its journals disagree. It never falls back to the
 * member's branch; the reports do that at read time instead, and
 * `branches:attribution-report` lists what is left.
 *
 * Each write is guarded on the row still having no branch, so a second run
 * changes nothing, and writes one audit row as a system change (no user).
 * The 2026_11_19_300100 migration runs it once on deploy;
 * `php artisan branches:backfill-attribution --dry-run` shows first what it
 * will change.
 */
class BranchAttributionBackfill
{
    public const AUDIT_ACTION = 'branch_attributed';

    public const NO_JOURNAL = 'no posted journal proves its branch';

    public const JOURNAL_HAS_NO_BRANCH = 'its posted journal has no branch';

    public const JOURNALS_DISAGREE = 'its posted journals are in different branches';

    private const CHUNK = 500;

    /**
     * Attribute every row a journal proves, or with `$dryRun` only say which.
     *
     * @return array{
     *     share_capital: array{updated: list<array{id: int, reference: string, branch_id: int, journal_no: ?string}>, skipped: list<array{id: int, reference: string, reason: string}>},
     *     gcash: array{updated: list<array{id: int, reference: string, branch_id: int, journal_no: ?string}>, skipped: list<array{id: int, reference: string, reason: string}>},
     * }
     */
    public function run(bool $dryRun): array
    {
        return [
            'share_capital' => $this->attribute($this->shareCapitalCandidates(), 'share_capital_ledger', 'reference', ShareCapitalLedger::class, $dryRun),
            'gcash' => $this->attribute($this->gcashCandidates(), 'gcash_transactions', 'reference_no', GCashTransaction::class, $dryRun),
        ];
    }

    /**
     * The one line the migration prints to the deploy log.
     *
     * @param  array{share_capital: array{updated: list<mixed>, skipped: list<mixed>}, gcash: array{updated: list<mixed>, skipped: list<mixed>}}  $result
     */
    public static function summary(array $result): string
    {
        return sprintf(
            'branch attribution: share capital %d attributed, %d skipped; member GCash %d attributed, %d skipped',
            count($result['share_capital']['updated']),
            count($result['share_capital']['skipped']),
            count($result['gcash']['updated']),
            count($result['gcash']['skipped']),
        );
    }

    /**
     * Members with no branch, whose unattributed rows count in no branch.
     *
     * @return list<array{id: int, borrower_code: ?string, status: string}>
     */
    public function membersWithNoBranch(): array
    {
        return DB::table('borrowers')
            ->whereNull('branch_id')
            ->orderBy('id')
            ->get(['id', 'borrower_code', 'status'])
            ->map(fn (object $member): array => [
                'id' => (int) $member->id,
                'borrower_code' => $member->borrower_code,
                'status' => (string) $member->status,
            ])->all();
    }

    private function shareCapitalCandidates(): Builder
    {
        return DB::table('share_capital_ledger')
            ->whereNull('branch_id')
            ->select(['id', 'reference', 'repayment_id', 'loan_id', 'savings_transaction_id']);
    }

    private function gcashCandidates(): Builder
    {
        return DB::table('gcash_transactions')
            ->whereNull('branch_id')
            ->whereNotNull('borrower_id')
            ->select(['id', 'reference_no']);
    }

    /**
     * @param  class-string  $model
     * @return array{updated: list<array{id: int, reference: string, branch_id: int, journal_no: ?string}>, skipped: list<array{id: int, reference: string, reason: string}>}
     */
    private function attribute(Builder $candidates, string $table, string $referenceColumn, string $model, bool $dryRun): array
    {
        $result = ['updated' => [], 'skipped' => []];

        $candidates->chunkById(self::CHUNK, function (Collection $rows) use ($table, $referenceColumn, $model, $dryRun, &$result): void {
            $journals = $this->journalsFor($rows, $model);
            $changes = [];

            foreach ($rows as $row) {
                $reference = (string) $row->{$referenceColumn};
                $found = $journals[$this->journalKey($row, $model)] ?? collect();
                $branches = $found->pluck('branch_id')->unique()->values();

                $reason = match (true) {
                    $found->isEmpty() => self::NO_JOURNAL,
                    $branches->count() > 1 => self::JOURNALS_DISAGREE,
                    $branches->first() === null => self::JOURNAL_HAS_NO_BRANCH,
                    default => null,
                };

                if ($reason !== null) {
                    $result['skipped'][] = ['id' => (int) $row->id, 'reference' => $reference, 'reason' => $reason];

                    continue;
                }

                $changes[] = [
                    'id' => (int) $row->id,
                    'reference' => $reference,
                    'branch_id' => (int) $branches->first(),
                    'journal_no' => $found->first()->journal_no,
                ];
            }

            $written = $dryRun ? $changes : DB::transaction(fn (): array => $this->write($table, $model, $changes));
            array_push($result['updated'], ...$written);
        });

        return $result;
    }

    /**
     * The original journal (posted or reversed) of each row in the chunk,
     * keyed as journalKey() keys the row.
     *
     * @param  class-string  $model
     * @return array<string, Collection<int, object>>
     */
    private function journalsFor(Collection $rows, string $model): array
    {
        $wanted = $model === GCashTransaction::class
            ? [[GCashTransaction::class, ['cash_in', 'cash_out'], $rows->pluck('id')]]
            : [
                [ShareCapitalLedger::class, ['share_capital'], $rows->filter(fn ($r) => $r->repayment_id === null && $r->loan_id === null && $r->savings_transaction_id === null)->pluck('id')],
                [Repayment::class, ['loan_collection'], $rows->pluck('repayment_id')->filter()],
                [Loan::class, ['loan_release'], $rows->filter(fn ($r) => $r->repayment_id === null)->pluck('loan_id')->filter()],
                [SavingsTransaction::class, ['savings'], $rows->filter(fn ($r) => $r->repayment_id === null && $r->loan_id === null)->pluck('savings_transaction_id')->filter()],
            ];

        $journals = [];

        foreach ($wanted as [$postableType, $sources, $ids]) {
            if ($ids->isEmpty()) {
                continue;
            }

            DB::table('accounting_journals')
                ->where('postable_type', $postableType)
                ->whereIn('source', $sources)
                ->whereIn('postable_id', $ids->unique()->values()->all())
                ->whereIn('status', ['posted', 'reversed'])
                ->whereNull('reverses_journal_id')
                ->get(['postable_id', 'branch_id', 'journal_no'])
                ->each(function (object $journal) use (&$journals, $postableType): void {
                    $journals["{$postableType}#{$journal->postable_id}"] ??= collect();
                    $journals["{$postableType}#{$journal->postable_id}"]->push($journal);
                });
        }

        return $journals;
    }

    /** The journal a row is proven by, as journalsFor() keys it. */
    private function journalKey(object $row, string $model): string
    {
        if ($model === GCashTransaction::class) {
            return GCashTransaction::class.'#'.$row->id;
        }

        return match (true) {
            $row->repayment_id !== null => Repayment::class.'#'.$row->repayment_id,
            $row->loan_id !== null => Loan::class.'#'.$row->loan_id,
            $row->savings_transaction_id !== null => SavingsTransaction::class.'#'.$row->savings_transaction_id,
            default => ShareCapitalLedger::class.'#'.$row->id,
        };
    }

    /**
     * Set each row's branch and record it, guarded on the row still having
     * none, so a row given one since it was read is left as it now is.
     *
     * @param  class-string  $model
     * @param  list<array{id: int, reference: string, branch_id: int, journal_no: ?string}>  $changes
     * @return list<array{id: int, reference: string, branch_id: int, journal_no: ?string}> the rows actually written
     */
    private function write(string $table, string $model, array $changes): array
    {
        $written = [];
        $now = now();
        $names = DB::table('branches')->whereIn('id', array_column($changes, 'branch_id'))->pluck('name', 'id');
        $what = $model === GCashTransaction::class ? 'GCash transaction' : 'Share capital entry';

        foreach ($changes as $change) {
            $updated = DB::table($table)
                ->where('id', $change['id'])
                ->whereNull('branch_id')
                ->update(['branch_id' => $change['branch_id'], 'updated_at' => $now]);

            if ($updated === 0) {
                continue;
            }

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => self::AUDIT_ACTION,
                'auditable_type' => $model,
                'auditable_id' => $change['id'],
                'old_values' => json_encode(['branch_id' => null]),
                'new_values' => json_encode(['branch_id' => $change['branch_id']]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: %s %s set to %s, the branch its posted journal%s is in.',
                    $what,
                    $change['reference'],
                    $names[$change['branch_id']] ?? "branch #{$change['branch_id']}",
                    $change['journal_no'] === null ? '' : " {$change['journal_no']}",
                ),
                'created_at' => $now,
            ]);

            $written[] = $change;
        }

        return $written;
    }
}
