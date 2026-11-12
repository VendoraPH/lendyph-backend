<?php

namespace App\Services\Penalties;

/**
 * Penalty brackets: `[{days_from, days_to, rate}]`, days counted past the due
 * date (the day after it is day 1), `days_to` null for open-ended, and the
 * rate a percent of the period's unpaid principal with up to 4 decimals,
 * never rounded.
 *
 * The one lookup the engine uses (RepaymentService::penaltyCharges()), and
 * the one wording the audit rows and the Ledger use.
 */
final class BracketRate
{
    /**
     * Brackets in day order, with whole-number days and each rate as a
     * number, as a loan stores them.
     *
     * @param  iterable<array{days_from: int|string, days_to: int|string|null, rate: int|float|string}>  $brackets
     * @return list<array{days_from: int, days_to: int|null, rate: float}>
     */
    public static function normalise(iterable $brackets): array
    {
        $rows = [];

        foreach ($brackets as $bracket) {
            $rows[] = [
                'days_from' => (int) $bracket['days_from'],
                'days_to' => $bracket['days_to'] === null ? null : (int) $bracket['days_to'],
                'rate' => (float) $bracket['rate'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['days_from'] <=> $b['days_from']);

        return $rows;
    }

    /**
     * The rate of the bracket covering `$daysPastDue`, or null when none
     * does (no charge on that day).
     *
     * @param  list<array{days_from: int, days_to: int|null, rate: float|int|string}>  $brackets
     */
    public static function rateFor(array $brackets, int $daysPastDue): ?float
    {
        foreach ($brackets as $bracket) {
            if ($daysPastDue >= (int) $bracket['days_from']
                && ($bracket['days_to'] === null || $daysPastDue <= (int) $bracket['days_to'])) {
                return (float) $bracket['rate'];
            }
        }

        return null;
    }

    /**
     * Whether any bracket charges something.
     *
     * @param  list<array{days_from: int, days_to: int|null, rate: float|int|string}>  $brackets
     */
    public static function chargesAnything(array $brackets): bool
    {
        foreach ($brackets as $bracket) {
            if ((float) $bracket['rate'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * "1–5 days: 20%; 6–10 days: 30%; 11 days or more: 50%". `$after` follows
     * each range: " late" reads "1–5 days late: 20%".
     *
     * @param  iterable<array{days_from: int|string, days_to: int|string|null, rate: int|float|string}>  $brackets
     */
    public static function inWords(iterable $brackets, string $after = ''): string
    {
        return implode('; ', array_map(
            fn (array $bracket): string => self::daysInWords($bracket['days_from'], $bracket['days_to']).$after.': '.self::percent($bracket['rate']),
            self::normalise($brackets),
        ));
    }

    /**
     * A rate as a percent with the decimals it has and no more: 20 reads
     * "20%", 2.5 reads "2.5%", 0.1234 reads "0.1234%". Never rounded (rates
     * hold at most 4 decimals).
     */
    public static function percent(int|float|string $rate): string
    {
        $fixed = is_string($rate) && is_numeric($rate) && ! str_contains(strtolower($rate), 'e')
            ? $rate
            : sprintf('%.4F', (float) $rate);

        if (str_contains($fixed, '.')) {
            $fixed = rtrim(rtrim($fixed, '0'), '.');
        }

        return ($fixed === '' || $fixed === '-0' ? '0' : $fixed).'%';
    }

    private static function daysInWords(int $from, ?int $to): string
    {
        return match (true) {
            $to === null => $from === 1 ? '1 day or more' : "{$from} days or more",
            $to === $from => $from === 1 ? '1 day' : "day {$from}",
            default => "{$from}–{$to} days",
        };
    }
}
