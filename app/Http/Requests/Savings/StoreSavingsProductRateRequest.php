<?php

namespace App\Http\Requests\Savings;

use App\Models\SavingsProduct;
use App\Services\Accounting\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/products/{product}/rates` (savings:settings and branches:view_all): a new rate from
 * a date, today or later. A regular product's rate has no term; a time
 * deposit product's names one of the terms it offers. Rates are only ever
 * appended: there is no route to change or remove one.
 *
 * Either one rate (`annual_rate`, the base tier, as before tiers) or, for a
 * regular product only, a ladder for the one `effective_from` (`tiers`, gap
 * round S2): `{min_balance, annual_rate}` rows, the base tier (no minimum
 * balance) first and exactly once, then minimum balances above ₱0.00 in
 * ascending order with no repeats. Rates are 0–100% with at most four
 * decimals, never rounded. A time deposit product takes no tiers. A tier
 * without a `min_balance` is the base tier. A post replaces the date's whole
 * ladder (RateBook).
 */
class StoreSavingsProductRateRequest extends FormRequest
{
    use AuthorizesCompanyWideSavings;

    /** The most tiers one ladder holds. */
    public const MAX_TIERS = 20;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SavingsProduct $product */
        $product = $this->route('product');

        return [
            'annual_rate' => ['required_without:tiers', 'prohibits:tiers', ...SavingsRules::rate('interest rate')],
            'min_balance' => ['prohibited'],
            'term_days' => $product->isTimeDeposit()
                ? ['required', 'integer', Rule::in(array_map('intval', $product->term_options_days ?? []))]
                : ['prohibited'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
            ...$this->tierRules($product),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['tiers.*.min_balance' => 'minimum balance'], ['annual_rate' => 'interest rate', 'tiers.*.annual_rate' => 'interest rate']),
            'annual_rate.required_without' => 'Enter the interest rate.',
            'annual_rate.prohibits' => 'Send one interest rate or a list of tiers, not both.',
            'min_balance.prohibited' => 'A minimum balance belongs to a tier: send the rates as a list of tiers.',
            'term_days.required' => 'Choose which term this rate is for.',
            'term_days.integer' => 'The term is a whole number of days.',
            'term_days.in' => 'The product doesn\'t offer that term. Add the term to the product first.',
            'term_days.prohibited' => 'A regular savings rate has no term.',
            'effective_from.required' => 'Enter the date the rate starts.',
            'effective_from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'effective_from.after_or_equal' => 'A new rate can start today or later, never in the past, so interest already figured never changes.',
            'tiers.prohibited' => 'A time deposit rate has one rate per term, with no balance tiers.',
            'tiers.array' => 'Send the tiers as a list.',
            'tiers.min' => 'Add at least the base rate.',
            'tiers.max' => 'A rate can have at most '.self::MAX_TIERS.' tiers.',
            'tiers.*.array' => 'Each tier has a minimum balance and an interest rate.',
            'tiers.*.annual_rate.required' => 'Enter each tier\'s interest rate.',
            'tiers.*.min_balance.gt' => 'A tier\'s minimum balance must be above ₱0.00. Leave it empty for the base rate.',
        ];
    }

    /** @return array<string, mixed> */
    private function tierRules(SavingsProduct $product): array
    {
        if ($product->isTimeDeposit()) {
            return ['tiers' => ['prohibited']];
        }

        return [
            'tiers' => ['sometimes', 'array', 'list', 'min:1', 'max:'.self::MAX_TIERS, $this->ladderRule()],
            'tiers.*' => ['array:min_balance,annual_rate'],
            'tiers.*.min_balance' => ['nullable', ...SavingsRules::money('minimum balance'), 'gt:0'],
            'tiers.*.annual_rate' => ['required', ...SavingsRules::rate('interest rate')],
        ];
    }

    /**
     * Exactly one base tier, listed first, then minimum balances strictly
     * rising. A tier whose minimum balance can't be read is left to its own
     * field's message.
     */
    private function ladderRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $minimums = [];

            foreach ($value as $tier) {
                $minimum = is_array($tier) ? ($tier['min_balance'] ?? null) : null;

                if ($minimum === null) {
                    $minimums[] = null;

                    continue;
                }

                $centavos = is_string($minimum) || is_int($minimum) || is_float($minimum) ? Money::toCentavos($minimum) : null;

                if ($centavos === null) {
                    return;
                }

                $minimums[] = $centavos;
            }

            $bases = count(array_filter($minimums, fn (?int $minimum) => $minimum === null));

            if ($bases !== 1 || $minimums[0] !== null) {
                $fail('List the base rate (no minimum balance) first, exactly once, then the tiers above it.');

                return;
            }

            $rising = array_slice($minimums, 1);

            if (count(array_unique($rising)) !== count($rising)) {
                $fail('Each minimum balance can appear only once.');

                return;
            }

            for ($i = 1; $i < count($rising); $i++) {
                if ($rising[$i] <= $rising[$i - 1]) {
                    $fail('List the tiers from the lowest minimum balance to the highest.');

                    return;
                }
            }
        };
    }
}
