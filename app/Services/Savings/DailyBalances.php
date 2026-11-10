<?php

namespace App\Services\Savings;

use Illuminate\Support\Facades\DB;

/**
 * The value-dated balances interest is figured on (design §5), read in two
 * queries per chunk of accounts: one opening sum (rows value-dated before
 * the start) and the changes grouped by account and value date up to the
 * end. Credits and debits are summed separately, because Money::toCentavos
 * refuses a negative, and netted in centavos.
 */
class DailyBalances
{
    /**
     * @param  iterable<int>  $accountIds
     * @return array<int, array{opening: int, deltas: array<string, int>}>
     */
    public function load(iterable $accountIds, string $start, string $end): array
    {
        $ids = collect($accountIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        $balances = [];

        foreach ($ids as $id) {
            $balances[$id] = ['opening' => 0, 'deltas' => []];
        }

        if ($ids === []) {
            return $balances;
        }

        $openings = DB::table('savings_transactions')
            ->whereIn('account_id', $ids)
            ->where('value_date', '<', $start)
            ->groupBy('account_id')
            ->select('account_id', DB::raw('SUM(credit) AS credits'), DB::raw('SUM(debit) AS debits'))
            ->get();

        foreach ($openings as $row) {
            $balances[(int) $row->account_id]['opening'] = SavingsMath::centavos($row->credits) - SavingsMath::centavos($row->debits);
        }

        $changes = DB::table('savings_transactions')
            ->whereIn('account_id', $ids)
            ->whereBetween('value_date', [$start, $end])
            ->groupBy('account_id', 'value_date')
            ->select('account_id', 'value_date', DB::raw('SUM(credit) AS credits'), DB::raw('SUM(debit) AS debits'))
            ->get();

        foreach ($changes as $row) {
            $balances[(int) $row->account_id]['deltas'][(string) $row->value_date] = SavingsMath::centavos($row->credits) - SavingsMath::centavos($row->debits);
        }

        return $balances;
    }
}
