<?php

namespace App\Services\Branches;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingReconciliation;
use App\Models\Branch;
use App\Models\InterbranchTransfer;
use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\Accounting\ReconciliationMatcher;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\BranchAccess;
use Illuminate\Support\Collection;

/**
 * What is in transit between branches, and the §15 branch counts (Phase 6
 * MB-BE5), both scoped to the caller's branches.
 *
 * The in-transit report lists each transfer approved and not yet received,
 * oldest first, with its age (a transfer approved but never received leaves
 * 1190 open, so its age is the flag), and each branch's 1190 Interbranch
 * Clearing balance from the trial balance's own aggregate. The branch rows
 * add up to `total_clearing_balance`. For `branches:view_all`, a balance on
 * journals with no branch reads as its own "No branch" row, and `difference`
 * (clearing total less in-transit total) is ₱0.00 while nothing but
 * transfers posts to 1190; a branch user sees only part of each pair, so
 * theirs is null.
 */
class InterbranchInTransitReport
{
    public const NO_BRANCH = 'No branch';

    public function __construct(
        private TrialBalanceBuilder $trialBalance,
        private BranchAccess $access,
        private ReconciliationMatcher $matcher,
    ) {}

    /**
     * @return array{
     *     as_of: string,
     *     clearing_account: array{id: int, code: string, name: string}|null,
     *     transfers: Collection<int, InterbranchTransfer>,
     *     branches: list<array{branch_id: int|null, branch_name: string, clearing_balance: int}>,
     *     total_clearing_balance: int,
     *     total_in_transit: int,
     *     difference: int|null,
     * }
     */
    public function build(User $user): array
    {
        $visible = $this->access->for($user);

        $transfers = InterbranchTransfer::query()
            ->visibleTo($user)
            ->where('status', InterbranchTransfer::STATUS_IN_TRANSIT)
            ->withDetails()
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        $clearingId = AccountingAccountMapping::query()->where('role', InterbranchTransfers::CLEARING_ROLE)->value('accounting_account_id');
        $clearing = $clearingId === null ? null : AccountingAccount::query()->find($clearingId, ['id', 'code', 'name']);

        $parties = $transfers->flatMap(fn (InterbranchTransfer $t): array => [$t->source_branch_id, $t->destination_branch_id])->unique()->all();
        $rows = [];

        $branches = Branch::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($branches as $branch) {
            $balance = $clearing === null ? 0 : ($this->trialBalance->signedBalances(null, $branch->id, [$clearing->id])[$clearing->id] ?? 0);

            if ($balance !== 0 || in_array($branch->id, $parties, true)) {
                $rows[] = ['branch_id' => $branch->id, 'branch_name' => $branch->name, 'clearing_balance' => $balance];
            }
        }

        if ($visible === null && $clearing !== null) {
            $all = $this->trialBalance->signedBalances(null, null, [$clearing->id])[$clearing->id] ?? 0;
            $noBranch = $all - Money::sum(array_column($rows, 'clearing_balance'));

            if ($noBranch !== 0) {
                $rows[] = ['branch_id' => null, 'branch_name' => self::NO_BRANCH, 'clearing_balance' => $noBranch];
            }
        }

        $totalClearing = Money::sum(array_column($rows, 'clearing_balance'));
        $totalInTransit = Money::sum($transfers->pluck('amount')->all());

        return [
            'as_of' => now()->toDateString(),
            'clearing_account' => $clearing === null ? null : ['id' => $clearing->id, 'code' => $clearing->code, 'name' => $clearing->name],
            'transfers' => $transfers,
            'branches' => $rows,
            'total_clearing_balance' => $totalClearing,
            'total_in_transit' => $totalInTransit,
            'difference' => $visible === null ? $totalClearing - $totalInTransit : null,
        ];
    }

    /**
     * The §15 cards, each in the caller's branches and null without its
     * source's permission: transfers waiting for approval and in transit
     * (`interbranch_transfers:view`), suspended branches (the branch list
     * every staff user reads), and branches with a reconciliation that isn't
     * Reconciled by the Reconciliation screen's own rule
     * (`accounting:reconcile`; "all branches" reconciliations name no branch
     * and aren't counted).
     *
     * @return array{pending_transfers: int|null, in_transit_transfers: int|null, suspended_branches: int, unreconciled_branches: int|null}
     */
    public function counts(User $user): array
    {
        $transfers = $user->can('interbranch_transfers:view')
            ? InterbranchTransfer::query()->visibleTo($user)
                ->whereIn('status', [InterbranchTransfer::STATUS_PENDING, InterbranchTransfer::STATUS_IN_TRANSIT])
                ->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            : null;

        $suspended = $this->access->constrain(Branch::query(), $user, 'id')
            ->where('status', Branch::STATUS_SUSPENDED)
            ->count();

        return [
            'pending_transfers' => $transfers === null ? null : (int) ($transfers[InterbranchTransfer::STATUS_PENDING] ?? 0),
            'in_transit_transfers' => $transfers === null ? null : (int) ($transfers[InterbranchTransfer::STATUS_IN_TRANSIT] ?? 0),
            'suspended_branches' => $suspended,
            'unreconciled_branches' => $user->can('accounting:reconcile') ? $this->unreconciledBranches($user) : null,
        ];
    }

    private function unreconciledBranches(User $user): int
    {
        $reconciliations = AccountingReconciliation::query()->visibleTo($user)->whereNotNull('branch_id')->get();

        return collect($this->matcher->presentMany($reconciliations))
            ->reject(fn (array $row): bool => (bool) $row['is_reconciled'])
            ->pluck('branch_id')
            ->unique()
            ->count();
    }
}
