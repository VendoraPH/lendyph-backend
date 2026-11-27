<?php

namespace App\Http\Requests\Savings;

use App\Models\Borrower;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The product rule fields (gap round S1) the create and update requests
 * share: limits in pesos (null = none), the free withdrawals a month (null =
 * unlimited) and the excess-withdrawal fee (null = refuse beyond the free
 * count), the allow switches, and the eligible member types and branches
 * (null = all). A time deposit product takes only the eligibility fields.
 * Each minimum is checked against its maximum, the stored one when only one
 * side is sent.
 */
final class SavingsProductRuleFields
{
    /** field => words, for the money limits. */
    public const MONEY = [
        'max_balance' => 'maximum balance',
        'min_deposit' => 'minimum deposit',
        'max_deposit' => 'maximum deposit',
        'min_withdrawal' => 'minimum withdrawal',
        'max_withdrawal' => 'maximum withdrawal',
        'excess_withdrawal_fee' => 'excess-withdrawal fee',
    ];

    public const SWITCHES = ['allow_deposits', 'allow_withdrawals', 'allow_transfers'];

    public const ELIGIBILITY = ['eligible_member_types', 'eligible_branch_ids'];

    /** Minimum field => maximum field. */
    private const PAIRS = ['min_deposit' => 'max_deposit', 'min_withdrawal' => 'max_withdrawal'];

    public const TIME_DEPOSIT_REFUSAL = 'A time deposit product takes no deposit, withdrawal or balance limits; only who may place one.';

    /**
     * @param  bool|null  $timeDeposit  true or false when the kind is known (update); null to read it from `kind` (create)
     * @return array<string, mixed>
     */
    public static function rules(?bool $timeDeposit, ?User $user): array
    {
        $regularOnly = match ($timeDeposit) {
            true => ['prohibited'],
            false => ['sometimes'],
            null => ['prohibited_if:kind,'.SavingsProduct::KIND_TIME_DEPOSIT],
        };

        $rules = [];

        foreach (array_keys(self::MONEY) as $field) {
            $rules[$field] = [...$regularOnly, 'nullable', ...SavingsRules::money(self::MONEY[$field])];
        }

        $rules['free_withdrawals_per_month'] = [...$regularOnly, 'nullable', 'integer', 'min:0', 'max:1000'];

        foreach (self::SWITCHES as $field) {
            $rules[$field] = [...$regularOnly, 'boolean'];
        }

        $rules['eligible_member_types'] = ['sometimes', 'nullable', 'array', 'min:1'];
        $rules['eligible_member_types.*'] = ['string', 'distinct', Rule::in(Borrower::MEMBER_TYPES)];
        $rules['eligible_branch_ids'] = ['sometimes', 'nullable', 'array', 'min:1', 'max:500'];
        $rules['eligible_branch_ids.*'] = ['integer', 'distinct', app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $user)];

        return $rules;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        $messages = SavingsRules::messages(self::MONEY);

        foreach ([...array_keys(self::MONEY), 'free_withdrawals_per_month', ...self::SWITCHES] as $field) {
            $messages["{$field}.prohibited"] = self::TIME_DEPOSIT_REFUSAL;
            $messages["{$field}.prohibited_if"] = self::TIME_DEPOSIT_REFUSAL;
        }

        foreach (self::SWITCHES as $field) {
            $messages["{$field}.boolean"] = 'Say yes or no.';
        }

        return [
            ...$messages,
            'free_withdrawals_per_month.integer' => 'The free withdrawals a month are a whole number, 0 or more (leave it empty for no limit).',
            'free_withdrawals_per_month.min' => 'The free withdrawals a month are a whole number, 0 or more (leave it empty for no limit).',
            'free_withdrawals_per_month.max' => 'The free withdrawals a month can be at most 1,000 (leave it empty for no limit).',
            'eligible_member_types.array' => 'List the member types the product is open to, or leave it empty for all.',
            'eligible_member_types.min' => 'List at least one member type, or leave it empty for all.',
            'eligible_member_types.*.in' => 'A member type is regular or associate.',
            'eligible_member_types.*.string' => 'A member type is regular or associate.',
            'eligible_member_types.*.distinct' => 'Each member type can be listed once.',
            'eligible_branch_ids.array' => 'List the branches that offer the product, or leave it empty for all.',
            'eligible_branch_ids.min' => 'List at least one branch, or leave it empty for all.',
            'eligible_branch_ids.max' => 'List at most 500 branches.',
            'eligible_branch_ids.*.integer' => 'Choose the branches from the list.',
            'eligible_branch_ids.*.distinct' => 'Each branch can be listed once.',
            'eligible_branch_ids.*.exists' => 'That branch does not exist.',
        ];
    }

    /**
     * Each minimum is at most its maximum: the sent value, else the stored
     * one. The error sits on the field that was sent.
     */
    public static function checkPairs(Validator $validator, ?SavingsProduct $stored): void
    {
        $validator->after(function (Validator $validator) use ($stored): void {
            $data = $validator->getData();

            foreach (self::PAIRS as $minField => $maxField) {
                if ($validator->errors()->hasAny([$minField, $maxField])) {
                    continue;
                }

                $min = array_key_exists($minField, $data) ? $data[$minField] : $stored?->{$minField};
                $max = array_key_exists($maxField, $data) ? $data[$maxField] : $stored?->{$maxField};

                if ($min === null || $max === null || ! is_numeric($min) || ! is_numeric($max) || (float) $min <= (float) $max) {
                    continue;
                }

                $minWords = self::MONEY[$minField];
                $maxWords = self::MONEY[$maxField];

                if (array_key_exists($maxField, $data)) {
                    $validator->errors()->add($maxField, "The {$maxWords} can't be below the {$minWords}.");
                } else {
                    $validator->errors()->add($minField, "The {$minWords} can't be above the {$maxWords}.");
                }
            }
        });
    }

    /**
     * The validated rule fields as stored: branch ids as whole numbers, member
     * types in a fixed order.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (is_array($data['eligible_branch_ids'] ?? null)) {
            $ids = array_map('intval', $data['eligible_branch_ids']);
            sort($ids);
            $data['eligible_branch_ids'] = array_values($ids);
        }

        if (is_array($data['eligible_member_types'] ?? null)) {
            $data['eligible_member_types'] = array_values(array_intersect(Borrower::MEMBER_TYPES, $data['eligible_member_types']));
        }

        return $data;
    }
}
