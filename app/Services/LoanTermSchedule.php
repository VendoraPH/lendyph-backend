<?php

namespace App\Services;

use App\Enums\InterestRateFrequency;
use App\Enums\TermUnit;
use Carbon\Carbon;

/**
 * How a loan's term, payment frequency and quoted rate become instalments.
 *
 * - `term` is a LENGTH in `term_unit` (months or days).
 * - `frequency` decides how that length is split into instalments.
 * - `interest_rate` is quoted per `interest_rate_frequency`, and each
 *   instalment is charged for the days it covers.
 *
 * Periods convert on a 30-day month: a day is 1, a week 7, a bi-weekly period
 * 14, a semi-monthly period 15 and a month 30 days. A length that does not
 * split evenly ends in a shorter final instalment, charged only for its days.
 *
 * A months term paid monthly or at maturity steps by CALENDAR months instead,
 * exactly as every loan did before `term_unit` existed, so those loans come
 * out to the centavo as they always have.
 */
final class LoanTermSchedule
{
    public const DAYS_PER_MONTH = 30;

    /** Days in one period of each frequency, on the 30-day-month convention. */
    public const PERIOD_DAYS = [
        'daily' => 1,
        'weekly' => 7,
        'bi_weekly' => 14,
        'semi_monthly' => 15,
        'monthly' => self::DAYS_PER_MONTH,
    ];

    public static function stepsByCalendarMonth(string $termUnit, string $frequency): bool
    {
        return $termUnit === TermUnit::Months->value
            && in_array($frequency, ['monthly', 'upon_maturity'], true);
    }

    public static function termDays(int $term, string $termUnit): int
    {
        return $termUnit === TermUnit::Days->value ? $term : $term * self::DAYS_PER_MONTH;
    }

    /**
     * Each instalment's due date and the number of days it covers.
     *
     * An upon-maturity loan outside the calendar-month case is one instalment
     * covering the whole term.
     *
     * @return list<array{due_date: Carbon, days: int}>
     */
    public static function instalments(Carbon $start, int $term, string $termUnit, string $frequency): array
    {
        if (self::stepsByCalendarMonth($termUnit, $frequency)) {
            $instalments = [];
            $date = $start->copy();

            for ($i = 1; $i <= $term; $i++) {
                // One month after the previous due date, not $i months after the
                // start — the schedule has always stepped this way.
                $date = $date->copy()->addMonth();
                $instalments[] = ['due_date' => $date, 'days' => self::DAYS_PER_MONTH];
            }

            return $instalments;
        }

        $totalDays = self::termDays($term, $termUnit);

        if ($frequency === 'upon_maturity') {
            return [['due_date' => $start->copy()->addDays($totalDays), 'days' => $totalDays]];
        }

        $periodDays = self::PERIOD_DAYS[$frequency];
        $count = (int) ceil($totalDays / $periodDays);
        $instalments = [];

        for ($i = 1; $i <= $count; $i++) {
            $days = $i < $count ? $periodDays : $totalDays - ($count - 1) * $periodDays;
            $instalments[] = [
                'due_date' => $start->copy()->addDays(min($i * $periodDays, $totalDays)),
                'days' => $days,
            ];
        }

        return $instalments;
    }

    /**
     * The date the last instalment falls due.
     *
     * The calendar-month case is one addMonths($term) from the start, which is
     * what `loans.maturity_date` has always held.
     */
    public static function maturityDate(Carbon $start, int $term, string $termUnit, string $frequency): Carbon
    {
        if (self::stepsByCalendarMonth($termUnit, $frequency)) {
            return $start->copy()->addMonths($term);
        }

        return $start->copy()->addDays(self::termDays($term, $termUnit));
    }

    /**
     * The interest, as a fraction of principal, that `$days` days accrue at a
     * rate quoted per `$rateFrequency`.
     *
     * Exactly `$ratePercent / 100` when the days equal one rate period, so a
     * monthly rate on a monthly instalment is charged as it always was.
     */
    public static function rateForDays(float $ratePercent, string $rateFrequency, int $days): float
    {
        $rateDays = self::PERIOD_DAYS[$rateFrequency];
        $factor = $days === $rateDays ? 1 : $days / $rateDays;

        return $ratePercent / 100 * $factor;
    }

    /**
     * A count of `$frequency` periods as the exact term that reproduces it.
     *
     * For the CSV importer, which derives a period count from the file's dates.
     * Monthly and upon-maturity loans keep a months term. The fixed-length
     * frequencies become a days term that is a whole number of periods, with
     * the rate quoted per period, so the schedule, maturity date and interest
     * come out exactly as the period count describes.
     *
     * @return array{term: int, term_unit: string, interest_rate_frequency: string}
     */
    public static function fromPeriodCount(int $periods, string $frequency): array
    {
        if (in_array($frequency, ['monthly', 'upon_maturity'], true)) {
            return [
                'term' => $periods,
                'term_unit' => TermUnit::Months->value,
                'interest_rate_frequency' => InterestRateFrequency::Monthly->value,
            ];
        }

        return [
            'term' => $periods * self::PERIOD_DAYS[$frequency],
            'term_unit' => TermUnit::Days->value,
            'interest_rate_frequency' => $frequency,
        ];
    }
}
