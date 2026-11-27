<?php

namespace App\Services\Savings\Interest;

use Carbon\CarbonImmutable;

/**
 * A product's crediting periods (design §5): calendar months, quarters,
 * halves or years, so every run of a product covers the same stretch in
 * every branch and the next period always starts the day after the last
 * posted one ends.
 */
final class InterestPeriod
{
    /** Months per crediting period. */
    public const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12];

    private const WORDS = ['monthly' => 'monthly', 'quarterly' => 'quarterly', 'semi_annual' => 'semi-annually', 'annual' => 'annually'];

    /** Whether `$date` is the first day of a crediting period. */
    public static function isStart(string $crediting, string $date): bool
    {
        $day = CarbonImmutable::parse($date);

        return $day->day === 1 && ($day->month - 1) % self::months($crediting) === 0;
    }

    /** The last day of the period that starts on `$start`. */
    public static function endOf(string $crediting, string $start): string
    {
        return CarbonImmutable::parse($start)->addMonthsNoOverflow(self::months($crediting))->subDay()->toDateString();
    }

    /** The first day of the period holding `$date`. */
    public static function startOf(string $crediting, string $date): string
    {
        $day = CarbonImmutable::parse($date);
        $months = self::months($crediting);
        $month = intdiv($day->month - 1, $months) * $months + 1;

        return $day->setDate($day->year, $month, 1)->toDateString();
    }

    /** "Interest on Regular Savings is credited quarterly, so a period starts on Jan 1, Apr 1, Jul 1 or Oct 1." */
    public static function startsInWords(string $crediting): string
    {
        $months = self::months($crediting);
        $starts = [];

        for ($month = 1; $month <= 12; $month += $months) {
            $starts[] = CarbonImmutable::create(2026, $month, 1)->format('M j');
        }

        if (count($starts) === 1) {
            return 'credited '.self::WORDS[$crediting].', so a period starts on Jan 1';
        }

        $last = array_pop($starts);

        return 'credited '.self::WORDS[$crediting].', so a period starts on '.($crediting === 'monthly' ? 'the 1st of a month' : implode(', ', $starts).' or '.$last);
    }

    private static function months(string $crediting): int
    {
        return self::MONTHS[$crediting] ?? 3;
    }
}
