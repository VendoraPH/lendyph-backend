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
 * 1190 open, so its age is the flag), and each branch's net interbranch
 * position: its balance on the clearing accounts, from the trial balance's
 * own aggregate. That is what it has sent less what it has received, so a
 * received transfer leaves +X in its source and -X in its destination, and
 * only the transfers still in transit are left once the branches are added
 * up. The branch rows add up to `total_clearing_balance`. For `branches:view_all`, a balance on
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
     *     clearing_accounts: list<array{id: int, code: string, name: string}>,
     *     transfers: Collection<int, InterbranchTransfer>,
     *     branches: list<array{branch_id: int|null, branch_name: string, net_interbranch_position: int}>,
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

        $accountIds = $this->clearingAccountIds();
        $accounts = AccountingAccount::query()->whereIn('id', $accountIds)->orderBy('code')->get(['id', 'code', 'name']);

        $parties = $transfers->flatMap(fn (InterbranchTransfer $t): array => [$t->source_branch_id, $t->destination_branch_id])->unique()->all();
        $rows = [];

        $branches = Branch::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($branches as $branch) {
            $position = $this->position($branch->id, $accountIds);

            if ($position !== 0 || in_array($branch->id, $parties, true)) {
                $rows[] = ['branch_id' => $branch->id, 'branch_name' => $branch->name, 'net_interbranch_position' => $position];
            }
        }

        if ($visible === null && $accountIds !== []) {
            $noBranch = $this->position(null, $accountIds) - Money::sum(array_column($rows, 'net_interbranch_position'));

            if ($noBranch !== 0) {
                $rows[] = ['branch_id' => null, 'branch_name' => self::NO_BRANCH, 'net_interbranch_position' => $noBranch];
            }
        }

        $totalClearing = Money::sum(array_column($rows, 'net_interbranch_position'));
        $totalInTransit = Money::sum($transfers->pluck('amount')->all());

        return [
            'as_of' => now()->toDateString(),
            'clearing_accounts' => $accounts->map(fn (AccountingAccount $a): array => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->all(),
            'transfers' => $transfers,
            'branches' => $rows,
            'total_clearing_balance' => $totalClearing,
            'total_in_transit' => $totalInTransit,
            'difference' => $visible === null ? $totalClearing - $totalInTransit : null,
        ];
    }

    /**
     * Every account transfers clear through: the one `interbranch_clearing`
     * maps to now, and each one a transfer stored at approval (a role
     * re-pointed after some transfers posted leaves their pair on the old
     * account).
     *
     * @return list<int>
     */
    private function clearingAccountIds(): array
    {
        return InterbranchTransfer::query()->whereNotNull('clearing_account_id')->distinct()->pluck('clearing_account_id')
            ->push(AccountingAccountMapping::query()->where('role', InterbranchTransfers::CLEARING_ROLE)->value('accounting_account_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A branch's balance on the clearing accounts (all branches for null),
     * by the trial balance's own aggregate.
     *
     * @param  list<int>  $accountIds
     */
    private function position(?int $branchId, array $accountIds): int
    {
        return $accountIds === [] ? 0 : Money::sum(array_values($this->trialBalance->signedBalances(null, $branchId, $accountIds)));
    }

    /**
     * The §15 cards, each in the caller's branches and null without its
     * source's permission: transfers waiting for approval and in transit
     * (`interbranch_transfers:view`), suspended branches (the branch list
     * every staff user reads), and branches with a reconciliation that isn't
     * Reconciled by the Reconciliation screen's own rule
     * (`accounting:reconcile`; "all branches" reconciliations name no branch
     * and aren't counted). Only each branch and account's latest
     * reconciliation counts.
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

    /**
     * Branches whose latest reconciliation of some account (by period end,
     * then id) isn't Reconciled. Only those latest rows reach the matcher; a
     * period superseded by a later one doesn't count.
     */
    private function unreconciledBranches(User $user): int
    {
        $latest = AccountingReconciliation::query()->visibleTo($user)
            ->whereNotNull('branch_id')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->toBase()
            ->get(['id', 'branch_id', 'accounting_account_id'])
            ->unique(fn (object $row): string => $row->branch_id.':'.$row->accounting_account_id)
            ->pluck('id')
            ->all();

        $reconciliations = AccountingReconciliation::query()->whereIn('id', $latest)->get();

        return collect($this->matcher->presentMany($reconciliations))
            ->reject(fn (array $row): bool => (bool) $row['is_reconciled'])
            ->pluck('branch_id')
            ->unique()
            ->count();
    }
}
