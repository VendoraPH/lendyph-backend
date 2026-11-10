<?php

namespace App\Services\Savings\TimeDeposits;

use App\Models\SavingsProduct;
use App\Models\SavingsTaxRule;
use App\Models\TimeDeposit;
use App\Services\Savings\InterestCalculator;
use App\Services\Savings\RateBook;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\TaxBook;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use OverflowException;

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
 *   none when the product names no regular product, and refused (422) when
 *   it names one with no rate in force then: never a silent 0%);
 * - a principal too large to work out (the arithmetic would overflow) or a
 *   maturity amount beyond what a savings column holds is refused (422).
 *
 * A rollover is placed on the day it is settled but priced at the rates in
 * force on the old placement's maturity date (`$ratesOn`).
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
     * @param  string|null  $ratesOn  the day whose rates apply, when not the placement date (a rollover's maturity date)
     * @return array{principal: int, annual_rate: string, pretermination_rate: string|null, withholding_tax_rate: string, term_days: int,
     *               placement_date: string, maturity_date: string, gross: int, tax: int, net: int, maturity: int}
     *
     * @throws ValidationException
     */
    public function figures(SavingsProduct $product, int $principal, int $termDays, string $placementDate, bool $rollover = false, ?string $ratesOn = null): array
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
        $priced = CarbonImmutable::parse($ratesOn ?? $placementDate)->startOfDay();
        $rate = $this->rates->rateOn($product->id, $termDays, $priced->toDateString());

        if ($rate === null) {
            $this->refuse('term_days', "{$product->name} has no rate in force for {$termDays} days on {$priced->format('M j, Y')}. Add one in Savings → Products.");
        }

        $annualRate = SavingsMath::rateString(SavingsMath::rateUnits((string) $rate));
        $taxRate = $this->taxes->rateOn(SavingsTaxRule::HOLDER_MEMBER, $priced->toDateString());
        $preterminationRate = $this->preterminationRate($product, $priced);

        try {
            $gross = $this->interest->simple($principal, $annualRate, $termDays);
            $tax = SavingsMath::share($gross, $taxRate);
            $net = $gross - $tax;
            $maturity = SavingsMath::add($principal, $net);
        } catch (OverflowException) {
            $this->refuse('principal', 'That principal is too large to work out its interest. Enter a smaller amount.');
        }

        if ($maturity > SavingsLedger::MAX_CENTAVOS) {
            $this->refuse('principal', 'That principal would mature above ₱9,999,999,999.99, the most a time deposit can hold. Enter a smaller amount.');
        }

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
            'maturity' => $maturity,
        ];
    }

    /**
     * The regular rate an early termination would earn, in force on $date:
     * null when the product names no regular product.
     *
     * @throws ValidationException when it names one with no rate in force on $date
     */
    private function preterminationRate(SavingsProduct $product, CarbonImmutable $date): ?string
    {
        if ($product->pretermination_product_id === null) {
            return null;
        }

        $rate = $this->rates->rateOn($product->pretermination_product_id, null, $date->toDateString());

        if ($rate === null) {
            $regular = SavingsProduct::query()->whereKey($product->pretermination_product_id)->value('name') ?? 'its regular product';
            $owner = str_ends_with($regular, 's') ? "{$regular}'" : "{$regular}'s";

            $this->refuse('product_id', "{$product->name} pays early terminations at {$owner} rate, which has none in force on {$date->format('M j, Y')}. Add one in Savings → Products.");
        }

        return SavingsMath::rateString(SavingsMath::rateUnits((string) $rate));
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

        try {
            $gross = $this->interest->simple($principal, $rate, $days);
            $tax = SavingsMath::share($gross, (string) $deposit->withholding_tax_rate);
        } catch (OverflowException) {
            $this->refuse('date', "{$deposit->certificate_number}'s principal is too large to work out its interest.");
        }

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
