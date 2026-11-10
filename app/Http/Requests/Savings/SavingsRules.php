<?php

namespace App\Http\Requests\Savings;

/**
 * The input shapes savings requests share: money in pesos with at most two
 * decimals (sent as a string or a number), and a rate in percent with at most
 * four decimals, never rounded. Messages say what to send, in words.
 */
final class SavingsRules
{
    /** decimal(12,2): the largest peso amount a savings column holds. */
    public const MAX_PESOS = '9999999999.99';

    /** @return list<string> */
    public static function money(): array
    {
        return ['numeric', 'min:0', 'max:'.self::MAX_PESOS, 'decimal:0,2'];
    }

    /** @return list<string> */
    public static function rate(): array
    {
        return ['numeric', 'min:0', 'max:100', 'decimal:0,4'];
    }

    /**
     * The messages for a money field and a rate field, by name.
     *
     * @param  array<string, string>  $money  field => words
     * @param  array<string, string>  $rates  field => words
     * @return array<string, string>
     */
    public static function messages(array $money = [], array $rates = []): array
    {
        $messages = [];

        foreach ($money as $field => $words) {
            $messages["{$field}.numeric"] = "The {$words} must be an amount in pesos.";
            $messages["{$field}.min"] = "The {$words} can't be below ₱0.00.";
            $messages["{$field}.max"] = "The {$words} can't be above ₱9,999,999,999.99.";
            $messages["{$field}.decimal"] = "The {$words} can have at most two decimals (centavos).";
        }

        foreach ($rates as $field => $words) {
            $messages["{$field}.numeric"] = "The {$words} must be a percent.";
            $messages["{$field}.min"] = "The {$words} can't be below 0%.";
            $messages["{$field}.max"] = "The {$words} can't be above 100%.";
            $messages["{$field}.decimal"] = "The {$words} can have at most four decimals; it is never rounded.";
        }

        return $messages;
    }
}
