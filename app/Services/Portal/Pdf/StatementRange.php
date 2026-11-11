<?php

namespace App\Services\Portal\Pdf;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The dates a member may ask a statement for (portal design §3): plain
 * YYYY-MM-DD days, neither after today (Manila), `from` no later than `to`,
 * and at most 5 years apart, checked after a missing day is filled in. Checked only after the record was found,
 * so another member's record answers 404 first.
 */
final class StatementRange
{
    public const MAX_YEARS = 5;

    /**
     * The statement's days, a missing one filled in first: `to` is today
     * (Manila), `from` the later of `$earliest` (an account's opening day)
     * and 5 years before `to`, never after `to`. Then both are checked: neither after today,
     * `from` on or before `to`, and at most 5 years apart.
     *
     * @return array{from: string, to: string}
     *
     * @throws ValidationException
     */
    public static function from(Request $request, bool $required, ?string $earliest = null): array
    {
        $today = now()->toDateString();
        $presence = $required ? 'required' : 'nullable';

        $dates = $request->validate([
            'from' => [$presence, 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => [$presence, 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ], [
            'from.required' => 'Choose the first day of the statement.',
            'to.required' => 'Choose the last day of the statement.',
            'from.date_format' => 'The first day must be a date (YYYY-MM-DD).',
            'to.date_format' => 'The last day must be a date (YYYY-MM-DD).',
            'from.before_or_equal' => 'The first day can\'t be after today.',
            'to.before_or_equal' => 'The last day can\'t be after today.',
        ]);

        $to = $dates['to'] ?? $today;
        $limit = CarbonImmutable::parse($to)->subYears(self::MAX_YEARS)->toDateString();
        $from = $dates['from'] ?? min(max($earliest ?? $limit, $limit), $to);

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The first day must be on or before the last day.']);
        }

        if ($from < $limit) {
            throw ValidationException::withMessages(['from' => 'A statement covers at most '.self::MAX_YEARS.' years.']);
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * A certificate's day: required, no later than today (Manila).
     *
     * @throws ValidationException
     */
    public static function asOf(Request $request): string
    {
        return $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
        ], [
            'as_of.required' => 'Choose the day the certificate is for.',
            'as_of.date_format' => 'The day must be a date (YYYY-MM-DD).',
            'as_of.before_or_equal' => 'The day can\'t be after today.',
        ])['as_of'];
    }
}
