<?php

namespace App\Services\Savings;

use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Interest earned since the last crediting and not yet credited (design §5),
 * for display and for the interest credited at close: the same calculation
 * as an interest run, from the day after `interest_credited_through` (or the
 * opening date) to the day before `$asOf`. It never posts anything by itself,
 * which keeps interest on a cash basis.
 */
class AccruedInterest
{
    public function __construct(
        private readonly DailyBalances $balances,
        private readonly RateBook $rates,
        private readonly TaxBook $taxes,
        private readonly InterestCalculator $calculator,
    ) {}

    /**
     * @return array{from: string, to: string, days: int, average_daily_balance: int, gross: int, tax: int, net: int, skip_reason: string|null}
     */
    public function for(SavingsAccount $account, ?string $asOf = null): array
    {
        return $this->forMany(collect([$account]), $asOf)[$account->id];
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<int, array{from: string, to: string, days: int, average_daily_balance: int, gross: int, tax: int, net: int, skip_reason: string|null}>
     */
    public function forMany(Collection $accounts, ?string $asOf = null): array
    {
        $to = CarbonImmutable::parse($asOf ?? now()->toDateString())->subDay()->toDateString();
        $products = SavingsProduct::query()->whereIn('id', $accounts->pluck('product_id')->unique()->all())->get()->keyBy('id');
        $taxRate = $this->taxes->rateOn(SavingsTaxRule::HOLDER_MEMBER, $to);
        $result = [];

        foreach ($accounts->groupBy(fn (SavingsAccount $account) => $this->startOf($account)) as $from => $group) {
            $loaded = $this->balances->load($group->pluck('id'), $from, $to);

            foreach ($group as $account) {
                $product = $products[$account->product_id];
                $figures = $this->calculator->calculate(
                    $loaded[$account->id]['opening'],
                    $loaded[$account->id]['deltas'],
                    $from,
                    $to,
                    $this->rates->segments($product->id, null, $from, $to),
                    SavingsMath::centavos($product->minimum_adb_for_interest),
                    $taxRate,
                );

                $result[$account->id] = [
                    'from' => $from,
                    'to' => $to,
                    'days' => $figures['days'],
                    'average_daily_balance' => $figures['average_daily_balance'],
                    'gross' => $figures['gross'],
                    'tax' => $figures['tax'],
                    'net' => $figures['net'],
                    'skip_reason' => $figures['skip_reason'],
                ];
            }
        }

        return $result;
    }

    /** The first day not yet credited. */
    public function startOf(SavingsAccount $account): string
    {
        $opened = CarbonImmutable::parse($account->opened_on);

        return $account->interest_credited_through === null
            ? $opened->toDateString()
            : CarbonImmutable::parse($account->interest_credited_through)->addDay()->max($opened)->toDateString();
    }
}
