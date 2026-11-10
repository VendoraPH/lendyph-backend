<?php

namespace App\Http\Requests\Savings;

use App\Services\Accounting\Money;
use App\Services\Savings\SavingsMath;
use Closure;
use InvalidArgumentException;

/**
 * The input shapes savings requests share: money in pesos with at most two
 * decimals (sent as a string or a number), and a rate in percent with at most
 * four decimals, never rounded. Each also has to read exactly the way the
 * engine reads it (Money::toCentavos, SavingsMath::rateUnits), so a form
 * PHP calls numeric but the engine can't read (".5", "5.", "+5", "1e3") is a
 * 422 in words, never a 500. Messages say what to send, in words.
 */
final class SavingsRules
{
    /** decimal(12,2): the largest peso amount a savings column holds. */
    public const MAX_PESOS = '9999999999.99';

    /**
     * @param  string  $words  the field in words, for the message
     * @return list<string|Closure>
     */
    public static function money(string $words): array
    {
        return ['numeric', 'min:0', 'max:'.self::MAX_PESOS, 'decimal:0,2', self::readable($words, 'in pesos, like 1500.00', static function (mixed $value): bool {
            return (is_string($value) || is_int($value) || is_float($value)) && Money::toCentavos($value) !== null;
        })];
    }

    /**
     * @param  string  $words  the field in words, for the message
     * @return list<string|Closure>
     */
    public static function rate(string $words): array
    {
        return ['numeric', 'min:0', 'max:100', 'decimal:0,4', self::readable($words, 'as a percent, like 2.5', static function (mixed $value): bool {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                return false;
            }

            try {
                SavingsMath::rateUnits((string) $value);
            } catch (InvalidArgumentException) {
                return false;
            }

            return true;
        })];
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

    /**
     * @param  Closure(mixed): bool  $reads
     */
    private static function readable(string $words, string $how, Closure $reads): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($words, $how, $reads): void {
            if (! $reads($value)) {
                $fail("Write the {$words} {$how}.");
            }
        };
    }
}
