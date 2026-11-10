<?php

namespace App\Services\Savings\TimeDeposits;

use App\Models\SavingsProduct;
use App\Models\SavingsTaxRule;
use App\Models\TimeDeposit;
use App\Services\Savings\InterestCalculator;
use App\Services\Savings\RateBook;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\TaxBook;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A time deposit's figures (design §5), the one calculation behind the quote,
 * the placement, a rollover and a pre-termination, so the quote always equals
 * what is placed:
 *
 * - gross interest = roundHalfUp(P × r × term, 365 × 10⁶): Actual/365 Fixed,
 *   at the rate in force on the placement date for that term;
 * - withholding tax = the member rate in force on that date, half up to the
 *   centavo; net = gross − tax; maturity amount = P + net;
 * - the pre-termination rate is the regular rate of the product's
 *   `pretermination_product_id`, in force on the placement date (snapshotted;
 *   none when the product names no regular product).
 *
 * Writes nothing. Amounts are centavos inside and two-decimal strings out.
 */
class Quote
{
    public function __construct(
        private readonly RateBook $rates,
        private readonly TaxBook $taxes,
        private readonly InterestCalculator $interest,
    ) {}

    /**
     * A placement's figures, after the product's own rules: a time deposit
     * product that is turned on, one of its terms, a rate in force for that
     * term, and (except on a rollover) at least its minimum principal.
     *
     * @return array{principal: int, annual_rate: string, pretermination_rate: string|null, withholding_tax_rate: string, term_days: int,
     *               placement_date: string, maturity_date: string, gross: int, tax: int, net: int, maturity: int}
     *
     * @throws ValidationException
     */
    public function figures(SavingsProduct $product, int $principal, int $termDays, string $placementDate, bool $rollover = false): array
    {
        if (! $product->isTimeDeposit()) {
            $this->refuse('product_id', "{$product->name} is a regular savings product, not a time deposit.");
        }

        if (! $product->is_active) {
            $this->refuse('product_id', $rollover
                ? "{$product->name} is turned off, so the placement can't be rolled over. Pay it out instead."
                : "{$product->name} is turned off and takes no new placements.");
        }

        $terms = array_map('intval', $product->term_options_days ?? []);

        if (! in_array($termDays, $terms, true)) {
            $this->refuse('term_days', "{$product->name} offers terms of ".$this->termList($terms).", not {$termDays}.");
        }

        if ($principal <= 0) {
            $this->refuse('principal', 'The principal must be more than ₱0.00.');
        }

        $minimum = SavingsMath::centavos($product->minimum_opening_balance);

        if (! $rollover && $principal < $minimum) {
            $this->refuse('principal', "{$product->name} takes at least ".$this->peso($minimum).'.');
        }

        $date = CarbonImmutable::parse($placementDate)->startOfDay();
        $rate = $this->rates->rateOn($product->id, $termDays, $date->toDateString());

        if ($rate === null) {
            $this->refuse('term_days', "{$product->name} has no rate in force for {$termDays} days on {$date->format('M j, Y')}. Add one in Savings → Products.");
        }

        $annualRate = SavingsMath::rateString(SavingsMath::rateUnits((string) $rate));
        $taxRate = $this->taxes->rateOn(SavingsTaxRule::HOLDER_MEMBER, $date->toDateString());
        $preterminationRate = $product->pretermination_product_id === null
            ? null
            : $this->rates->rateOn($product->pretermination_product_id, null, $date->toDateString());

        $gross = $this->interest->simple($principal, $annualRate, $termDays);
        $tax = SavingsMath::share($gross, $taxRate);
        $net = $gross - $tax;

        return [
            'principal' => $principal,
            'annual_rate' => $annualRate,
            'pretermination_rate' => $preterminationRate === null ? null : SavingsMath::rateString(SavingsMath::rateUnits((string) $preterminationRate)),
            'withholding_tax_rate' => $taxRate,
            'term_days' => $termDays,
            'placement_date' => $date->toDateString(),
            'maturity_date' => $date->addDays($termDays)->toDateString(),
            'gross' => $gross,
            'tax' => $tax,
            'net' => $net,
            'maturity' => SavingsMath::add($principal, $net),
        ];
    }

    /**
     * The quote as the screens read it (`TimeDepositQuote`).
     *
     * @param  array{annual_rate: string, withholding_tax_rate: string, term_days: int, maturity_date: string, gross: int, tax: int, net: int, maturity: int}  $figures
     * @return array{annual_rate: string, withholding_tax_rate: string, term_days: int, maturity_date: string, gross_interest: string, withholding_tax: string, net_interest: string, maturity_amount: string}
     */
    public function present(array $figures): array
    {
        return [
            'annual_rate' => $figures['annual_rate'],
            'withholding_tax_rate' => $figures['withholding_tax_rate'],
            'term_days' => $figures['term_days'],
            'maturity_date' => $figures['maturity_date'],
            'gross_interest' => SavingsMath::pesos($figures['gross']),
            'withholding_tax' => SavingsMath::pesos($figures['tax']),
            'net_interest' => SavingsMath::pesos($figures['net']),
            'maturity_amount' => SavingsMath::pesos($figures['maturity']),
        ];
    }

    /**
     * What breaking a placement early on $date pays (design §5, §10.2): the
     * snapshotted pre-termination rate (0% when there is none) for the days
     * held, less the snapshotted withholding tax, with no penalty in v1.
     *
     * @return array{date: string, days_held: int, rate: string, gross: int, tax: int, penalty: int, payout: int}
     *
     * @throws ValidationException when $date is before the placement or on or after maturity
     */
    public function pretermination(TimeDeposit $deposit, string $date): array
    {
        $on = CarbonImmutable::parse($date)->startOfDay();
        $placed = CarbonImmutable::parse($deposit->placement_date->toDateString());
        $matures = CarbonImmutable::parse($deposit->maturity_date->toDateString());

        if ($on->greaterThanOrEqualTo($matures)) {
            $this->refuse('date', "{$deposit->certificate_number} matures on {$matures->format('M j, Y')}, so it can't be pre-terminated on or after that. Settle it instead.");
        }

        if ($on->lessThan($placed)) {
            $this->refuse('date', "{$deposit->certificate_number} was placed on {$placed->format('M j, Y')}; it can't be pre-terminated before that.");
        }

        $days = (int) $placed->diffInDays($on);
        $rate = SavingsMath::rateString(SavingsMath::rateUnits((string) ($deposit->pretermination_rate ?? '0')));
        $principal = SavingsMath::centavos($deposit->principal);
        $gross = $this->interest->simple($principal, $rate, $days);
        $tax = SavingsMath::share($gross, (string) $deposit->withholding_tax_rate);
        $penalty = 0;

        return [
            'date' => $on->toDateString(),
            'days_held' => $days,
            'rate' => $rate,
            'gross' => $gross,
            'tax' => $tax,
            'penalty' => $penalty,
            'payout' => $principal + $gross - $tax - $penalty,
        ];
    }

    /**
     * The pre-termination quote as the screens read it (`PreterminationQuote`).
     *
     * @param  array{date: string, days_held: int, rate: string, gross: int, tax: int, penalty: int, payout: int}  $figures
     * @return array<string, int|string>
     */
    public function presentPretermination(array $figures): array
    {
        return [
            'date' => $figures['date'],
            'days_held' => $figures['days_held'],
            'rate' => $figures['rate'],
            'gross_interest' => SavingsMath::pesos($figures['gross']),
            'withholding_tax' => SavingsMath::pesos($figures['tax']),
            'penalty' => SavingsMath::pesos($figures['penalty']),
            'payout' => SavingsMath::pesos($figures['payout']),
        ];
    }

    /** @param list<int> $terms */
    private function termList(array $terms): string
    {
        sort($terms);

        if ($terms === []) {
            return 'no terms yet';
        }

        if (count($terms) === 1) {
            return "{$terms[0]} days";
        }

        $last = array_pop($terms);

        return implode(', ', $terms)." and {$last} days";
    }

    private function peso(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
    }

    /** @throws ValidationException */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
