<?php

namespace App\Services\Savings;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a member can take out now (design §4): the ledger balance, less active
 * holds not yet past `expires_on`, less pending outflow approvals not yet
 * expired (withdrawals and closes, and reversals of a credit), less the
 * product's maintaining balance, never below ₱0.00. The API shows it and the
 * withdrawal guard checks it; both read this one class.
 *
 * Read it under the account's row lock when it guards a write: holds and
 * approvals are only ever written under that lock, so the figure is exact.
 */
class AvailableBalance
{
    /** The available balance in centavos. */
    public function for(SavingsAccount $account): int
    {
        return $this->breakdown($account)['available'];
    }

    /**
     * @return array{ledger: int, holds: int, pending: int, maintaining: int, available: int}
     */
    public function breakdown(SavingsAccount $account): array
    {
        return $this->forMany(collect([$account]))[$account->id];
    }

    /**
     * Breakdowns for many accounts in three queries.
     *
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<int, array{ledger: int, holds: int, pending: int, maintaining: int, available: int}>
     */
    public function forMany(Collection $accounts): array
    {
        $ids = $accounts->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $today = now()->toDateString();

        $holds = DB::table('savings_holds')
            ->whereIn('account_id', $ids)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $today))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(amount) AS total')
            ->pluck('total', 'account_id');

        $pending = DB::table('savings_approvals AS a')
            ->leftJoin('savings_transactions AS t', 't.id', '=', 'a.target_transaction_id')
            ->whereIn('a.account_id', $ids)
            ->where('a.status', 'pending')
            ->where(fn ($q) => $q->whereNull('a.expires_at')->orWhere('a.expires_at', '>', now()))
            ->where(fn ($q) => $q->whereIn('a.kind', SavingsApproval::OUTFLOW_KINDS)
                ->orWhere(fn ($q) => $q->where('a.kind', 'reversal')->where('t.credit', '>', 0)))
            ->groupBy('a.account_id')
            ->selectRaw('a.account_id AS account_id, SUM(a.amount) AS total')
            ->pluck('total', 'account_id');

        $maintaining = SavingsProduct::query()
            ->whereIn('id', $accounts->pluck('product_id')->unique()->all())
            ->pluck('maintaining_balance', 'id');

        $result = [];

        foreach ($accounts as $account) {
            $ledger = SavingsMath::signedCentavos($account->ledger_balance);
            $held = SavingsMath::centavos($holds[$account->id] ?? 0);
            $reserved = SavingsMath::centavos($pending[$account->id] ?? 0);
            $floor = SavingsMath::centavos($maintaining[$account->product_id] ?? 0);

            $result[$account->id] = [
                'ledger' => $ledger,
                'holds' => $held,
                'pending' => $reserved,
                'maintaining' => $floor,
                'available' => max(0, $ledger - $held - $reserved - $floor),
            ];
        }

        return $result;
    }
}
