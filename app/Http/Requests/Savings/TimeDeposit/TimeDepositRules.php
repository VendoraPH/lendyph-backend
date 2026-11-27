<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use App\Http\Requests\Savings\SavingsRules;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The field rules and messages the time deposit requests share. A member or
 * savings account in a branch the caller can't see fails like a missing one
 * (422).
 */
final class TimeDepositRules
{
    /** @return array<string, list<mixed>> */
    public static function quote(?User $user): array
    {
        return [
            'borrower_id' => ['sometimes', 'integer', self::visible('borrowers', $user)],
            'product_id' => ['required', 'integer', Rule::exists('savings_products', 'id')],
            'principal' => ['required', ...SavingsRules::money('principal'), 'gt:0'],
            'term_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'placement_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return list<mixed> */
    public static function method(): array
    {
        return [Rule::in(SavingsTransaction::METHODS)];
    }

    /** An `exists` rule on a table with its own `branch_id`, narrowed to the caller's branches. */
    public static function visible(string $table, ?User $user): Exists
    {
        return app(BranchAccess::class)->constrainExists(Rule::exists($table, 'id'), $user);
    }

    /** @return array<string, string> */
    public static function quoteMessages(): array
    {
        return [
            ...SavingsRules::messages(['principal' => 'principal']),
            'principal.required' => 'Enter the principal to place.',
            'principal.gt' => 'The principal must be more than ₱0.00.',
            'borrower_id.integer' => 'Pick a member from the list.',
            'borrower_id.exists' => 'That member doesn\'t exist or isn\'t in your branches.',
            'product_id.required' => 'Pick a time deposit product.',
            'product_id.integer' => 'Pick a time deposit product from the list.',
            'product_id.exists' => 'That savings product doesn\'t exist.',
            'term_days.required' => 'Pick a term.',
            'term_days.integer' => 'The term is a whole number of days.',
            'term_days.min' => 'The term is at least 1 day.',
            'term_days.max' => 'The term can\'t be longer than 3,650 days.',
            'placement_date.required' => 'Enter the placement date.',
            'placement_date.date_format' => 'The placement date must be written as YYYY-MM-DD.',
        ];
    }

    /** @return array<string, string> */
    public static function methodMessages(string $field, string $words): array
    {
        return [
            "{$field}.required" => "Say how the {$words}: cash, bank transfer, GCash, Maya or savings.",
            "{$field}.in" => "The {$words} by cash, bank transfer, GCash, Maya or savings only.",
        ];
    }
}
