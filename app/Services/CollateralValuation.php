<?php

namespace App\Services;

use App\Models\Collateral;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * What each collateral is worth as security, as the Collateral Register shows it.
 *
 * This is the rule the register used to apply in the browser, moved to the
 * server unchanged, so the figure no longer depends on the client reading one
 * share capital ledger per member:
 *
 *  - A collateral whose type is not `share_capital` is worth its `amount`.
 *  - A `share_capital` collateral is worth its member's share capital balance,
 *    ShareCapitalLedger::balancesFor(): the whole ledger, possibly negative,
 *    and 0 for a member with no entries. Every share capital collateral of one
 *    member carries that member's full balance.
 *  - Unless the viewer cannot `share_capital:view`. Then a share capital
 *    collateral is `value_unknown` with an `effective_value` of 0, which is
 *    what the browser showed that user once its ledger read was refused. The
 *    register must not become a way round the permission that guards the
 *    balance.
 */
final class CollateralValuation
{
    /**
     * Value every collateral in the list, reading balances in at most one query.
     *
     * The balances come from a single grouped aggregate over the distinct
     * members holding a share capital collateral here, so the cost is flat
     * however many rows the list has. No query runs when none of them is share
     * capital, or when the viewer may not see balances.
     *
     * @param  EloquentCollection<int, Collateral>  $collaterals
     * @return array<int, array{effective_value: float, value_unknown: bool}> keyed by collateral id
     */
    public static function forCollaterals(EloquentCollection $collaterals, User $viewer): array
    {
        $collaterals->loadMissing('collateralType');

        $shareCapital = $collaterals->filter(
            fn (Collateral $collateral): bool => self::isShareCapital($collateral)
        );

        $canSeeBalances = $shareCapital->isNotEmpty() && $viewer->can('share_capital:view');

        $balances = $canSeeBalances
            ? ShareCapitalLedger::balancesFor(
                $shareCapital->map(fn (Collateral $collateral): int => (int) $collateral->borrower_id)->all()
            )
            : [];

        $valuations = [];

        foreach ($collaterals as $collateral) {
            $valuations[$collateral->id] = match (true) {
                ! self::isShareCapital($collateral) => [
                    'effective_value' => (float) $collateral->amount,
                    'value_unknown' => false,
                ],
                $canSeeBalances => [
                    'effective_value' => $balances[(int) $collateral->borrower_id],
                    'value_unknown' => false,
                ],
                default => [
                    'effective_value' => 0.0,
                    'value_unknown' => true,
                ],
            };
        }

        return $valuations;
    }

    /**
     * A collateral with no type falls through to its `amount`, as it did in
     * the browser.
     */
    private static function isShareCapital(Collateral $collateral): bool
    {
        return $collateral->collateralType?->source === 'share_capital';
    }
}
