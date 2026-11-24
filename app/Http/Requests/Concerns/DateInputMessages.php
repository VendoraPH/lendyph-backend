<?php

namespace App\Http\Requests\Concerns;

/**
 * How dates are written on the way in (Finish run round 2, decision 1).
 *
 * Laravel's `date` rule accepts a number (20261124) and bare digits
 * ("20261124"); a model's date cast then reads a number as a Unix timestamp
 * (1970-08-23). So every calendar date carries `date_format:Y-m-d` next to
 * `date`, and every date-time carries `date_format:` with DATE_TIME_FORMATS,
 * the written forms a client sends: a date picker's day, a datetime-local
 * value with or without seconds, and ISO 8601 with an offset or "Z" (with or
 * without fractions of a second). None of them is only digits.
 * DateRulesNeedDateFormatTest fails on a `date` rule with no `date_format`.
 *
 * The messages map both `date` and `date_format` to one sentence, so a value
 * that fails both shows it once.
 *
 * A static class rather than a trait: controllers validating inline use it too.
 */
final class DateInputMessages
{
    /** The written date-time forms, for `'date_format:'.DateInputMessages::DATE_TIME_FORMATS`. */
    public const DATE_TIME_FORMATS = 'Y-m-d,Y-m-d H:i,Y-m-d H:i:s,Y-m-d\TH:i,Y-m-d\TH:i:s,'
        .'Y-m-d\TH:i:sP,Y-m-d\TH:i:sO,Y-m-d\TH:i:sp,'
        .'Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.vO,Y-m-d\TH:i:s.vp,'
        .'Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s.uO,Y-m-d\TH:i:s.up';

    /**
     * The calendar date messages, as #359 words them.
     *
     * @param  array<string, string>  $fields  field => its name in words ("payment date")
     * @return array<string, string>
     */
    public static function calendar(array $fields): array
    {
        return self::messages($fields, fn (string $words) => "The {$words} must be a date written as year-month-day, such as 2026-11-24.");
    }

    /**
     * The date-time messages.
     *
     * @param  array<string, string>  $fields  field => its name in words ("end date")
     * @return array<string, string>
     */
    public static function dateTime(array $fields): array
    {
        return self::messages($fields, fn (string $words) => "The {$words} must be a date written as year-month-day, such as 2026-11-24, with a time if needed, such as 2026-11-24T09:30.");
    }

    /**
     * @param  array<string, string>  $fields
     * @param  callable(string): string  $sentence
     * @return array<string, string>
     */
    private static function messages(array $fields, callable $sentence): array
    {
        $messages = [];

        foreach ($fields as $field => $words) {
            $messages["{$field}.date"] = $sentence($words);
            $messages["{$field}.date_format"] = $sentence($words);
        }

        return $messages;
    }
}
