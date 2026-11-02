<?php

namespace App\Services;

use App\Models\Collateral;
use App\Models\Loan;
use App\Models\User;

/**
 * How well a loan's pledged collateral covers its principal: the one rule
 * behind the loan form's preview (POST /loans/preview, `collateral`) and the
 * loan page's collaterals card (GET /loans/{id}, `collateral_summary`).
 *
 * - `total_value`: the pledged values added up, in whole centavos;
 * - `security_status`: `unsecured` with no principal or nothing pledged,
 *   `secured` when the total reaches the principal, else
 *   `partially_secured`;
 * - `short_by`: the principal less the total, never below 0, and 0 without
 *   a principal.
 *
 * A pledge's value is the `snapshot_value` it was pledged at, which the form
 * states for each collateral and `loan_collaterals` stores. That column is
 * NOT NULL (default 0), so every saved pledge has one; a ₱0 snapshot counts as
 * ₱0, the value the pledge was recorded at, and never falls back to the
 * collateral's current `amount` or a share capital balance, which would make
 * the card disagree with what the loan was approved against.
 *
 * A share capital pledge is its member's balance, so a saved loan's summary
 * withholds its figures from a viewer without `share_capital:view`
 * (ofLoan()); the preview refuses such a pledge instead (PreviewLoanRequest).
 */
final class CollateralSecurity
{
    /**
     * @param  int  $principal  centavos
     * @param  int  $pledged  centavos, the pledged values already added up
     * @return array{total_value: float|int, security_status: string, short_by: float|int}
     */
    public static function summary(int $principal, int $pledged): array
    {
        $status = match (true) {
            $principal <= 0, $pledged <= 0 => 'unsecured',
            $pledged >= $principal => 'secured',
            default => 'partially_secured',
        };

        return [
            'total_value' => $pledged / 100,
            'security_status' => $status,
            'short_by' => $principal > 0 ? max(0, $principal - $pledged) / 100 : 0.0,
        ];
    }

    /**
     * The summary of a saved loan, from its loaded `collaterals` and their
     * pivot `snapshot_value`, for the user viewing it. The caller loads the
     * relation; nothing is queried here for a viewer who may see balances, so
     * a list that does not load it pays nothing.
     *
     * A share capital pledge is its member's balance. When the loan holds one
     * and the viewer cannot `share_capital:view` (or there is no viewer),
     * `total_value` and `short_by` are null and `value_unknown` is true: with
     * one such pledge the total IS the balance, and with others beside it the
     * balance is the total less amounts the viewer can see. `security_status`
     * stays. `value_unknown` is present only then, so every other summary
     * keeps the shape POST /loans/preview's `collateral` has.
     *
     * @return array{total_value: float|int|null, security_status: string, short_by: float|int|null, value_unknown?: true}
     */
    public static function ofLoan(Loan $loan, ?User $viewer = null): array
    {
        $pledged = 0;

        foreach ($loan->collaterals as $collateral) {
            /** @var Collateral $collateral */
            $pledged += LoanService::toCentavos((float) ($collateral->pivot->snapshot_value ?? 0));
        }

        $summary = self::summary(LoanService::toCentavos((float) ($loan->principal_amount ?? 0)), $pledged);

        if (! self::withholdsBalances($loan, $viewer)) {
            return $summary;
        }

        return [...$summary, 'total_value' => null, 'short_by' => null, 'value_unknown' => true];
    }

    /**
     * Whether the loan holds a share capital pledge the viewer may not see.
     * The types are read in one query, and only for such a viewer.
     */
    private static function withholdsBalances(Loan $loan, ?User $viewer): bool
    {
        if ($loan->collaterals->isEmpty() || $viewer?->can('share_capital:view')) {
            return false;
        }

        $loan->collaterals->loadMissing('collateralType');

        return $loan->collaterals->contains(
            fn (Collateral $collateral): bool => CollateralValuation::isShareCapital($collateral)
        );
    }
}
