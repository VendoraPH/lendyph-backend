<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\ShareCapitalLedger;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one rule for which branch a money row counts in (Phase 6 decision 16,
 * D4): the branch whose books it posted in. Reports, the GCash scope and later
 * the dashboard and AMLA read it here, so no figure re-derives it.
 *
 * - A share capital row and a member's GCash row carry `branch_id`, stamped
 *   when they are written ({@see self::shareCapitalPostingBranch()},
 *   {@see self::gcashPostingBranch()}) from the same source AutomaticPoster
 *   gives their journal. A member who moves branch later takes no past row
 *   along.
 * - A row written before the column existed, and not yet proven by
 *   `branches:backfill-attribution`, has none. It counts in its member's
 *   branch, as every report did before; `branches:attribution-report` lists
 *   it. A walk-in GCash row with no branch is head office's and has no
 *   member to fall back on.
 */
final class BranchAttribution
{
    /**
     * The branch a new share capital row posts in: the loan's for a release
     * credit (`loan_id`) or a payment's build-up (`repayment_id`), whose
     * journals post in the loan's branch; the savings account's for a
     * transfer from savings; otherwise the member's, as
     * AutomaticPoster::shareCapitalEntry() posts it.
     */
    public static function shareCapitalPostingBranch(ShareCapitalLedger $row): ?int
    {
        $branchId = match (true) {
            $row->repayment_id !== null => DB::table('repayments')
                ->join('loans', 'loans.id', '=', 'repayments.loan_id')
                ->where('repayments.id', $row->repayment_id)
                ->value('loans.branch_id'),
            $row->loan_id !== null => DB::table('loans')->where('id', $row->loan_id)->value('branch_id'),
            $row->savings_transaction_id !== null => DB::table('savings_transactions')
                ->join('savings_accounts', 'savings_accounts.id', '=', 'savings_transactions.account_id')
                ->where('savings_transactions.id', $row->savings_transaction_id)
                ->value('savings_accounts.branch_id'),
            default => self::memberBranch($row->borrower_id),
        };

        return $branchId === null ? null : (int) $branchId;
    }

    /**
     * The branch a GCash row posts in: its own when it has one (a walk-in's
     * recorded branch, or a member's row stamped when it was written), else
     * the member's. Null for a walk-in done at head office.
     */
    public static function gcashPostingBranch(GCashTransaction $tx): ?int
    {
        if ($tx->branch_id !== null) {
            return (int) $tx->branch_id;
        }

        return $tx->borrower_id === null ? null : self::memberBranch($tx->borrower_id);
    }

    /** The branch a GCash row counts in, as a model, for a list row. */
    public static function gcashBranch(GCashTransaction $tx): ?Branch
    {
        if ($tx->branch_id !== null) {
            return $tx->branch;
        }

        return $tx->borrower_id === null ? null : $tx->borrower?->branch;
    }

    /**
     * SQL for the branch a share capital row counts in. `$borrowers` must be
     * joined to the row's member (a left join keeps rows whose member is
     * gone, which then read null).
     */
    public static function shareCapitalBranchSql(string $ledger = 'share_capital_ledger', string $borrowers = 'borrowers'): string
    {
        return "COALESCE({$ledger}.branch_id, {$borrowers}.branch_id)";
    }

    /**
     * SQL for the branch a GCash row counts in: its own (a walk-in's recorded
     * branch, or a member's row stamped when written), else its member's. A
     * walk-in done at head office reads null. `$borrowers` must be left
     * joined to the row's member.
     */
    public static function gcashBranchSql(string $gcash = 'gcash_transactions', string $borrowers = 'borrowers'): string
    {
        return "COALESCE({$gcash}.branch_id, {$borrowers}.branch_id)";
    }

    /**
     * Narrow a share capital query to rows that count in one of $branchIds,
     * as a closure for `tap()`. Unchanged when $branchIds is null (every
     * branch). Needs no join.
     *
     * @param  list<int>|null  $branchIds
     */
    public static function shareCapitalInBranches(?array $branchIds, string $ledger = 'share_capital_ledger'): Closure
    {
        return fn (QueryBuilder|EloquentBuilder $query) => $branchIds === null
            ? $query
            : $query->where(fn ($row) => $row
                ->whereIn("{$ledger}.branch_id", $branchIds)
                ->orWhere(fn ($unattributed) => $unattributed
                    ->whereNull("{$ledger}.branch_id")
                    ->whereIn("{$ledger}.borrower_id", DB::table('borrowers')->whereIn('branch_id', $branchIds)->select('id'))));
    }

    /**
     * Narrow a GCash query to rows that count in one of $branchIds: rows
     * carrying one of them, and member rows with no branch whose member is
     * in one. A walk-in with no branch (head office) is in none.
     *
     * @param  EloquentBuilder<GCashTransaction>  $query
     * @param  list<int>  $branchIds
     * @return EloquentBuilder<GCashTransaction>
     */
    public static function gcashInBranches(EloquentBuilder $query, array $branchIds): EloquentBuilder
    {
        return $query->where(fn (EloquentBuilder $row) => $row
            ->whereIn('gcash_transactions.branch_id', $branchIds)
            ->orWhere(fn (EloquentBuilder $unattributed) => $unattributed
                ->whereNull('gcash_transactions.branch_id')
                ->whereHas('borrower', fn (EloquentBuilder $member) => $member->whereIn('borrowers.branch_id', $branchIds))));
    }

    private static function memberBranch(?int $borrowerId): ?int
    {
        if ($borrowerId === null) {
            return null;
        }

        $branchId = DB::table('borrowers')->where('id', $borrowerId)->value('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
