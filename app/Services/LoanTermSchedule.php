<?php

namespace App\Services;

use App\Enums\InterestRateFrequency;
use App\Enums\TermUnit;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
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
 * 14, a semi-monthly period 15, a month 30 and a year 360 days (a rate
 * period only, so a yearly rate is 12 times the monthly one). A length that
 * does not split evenly ends in a shorter final instalment, charged only for
 * its days.
 *
 * A months term paid monthly or at maturity steps by CALENDAR months instead,
 * each instalment charged as one full month, exactly as every loan was before
 * `term_unit` existed. Those due dates keep the start's day of the month,
 * capped at the last day of a shorter month: 31 Jan -> 28 Feb (29 in a leap
 * year) -> 31 Mar -> 30 Apr. Each is computed from the start and the
 * instalment number, never from the previous due date, so the last one is the
 * maturity date. A start on day 1-28 is never capped, and gets the dates it
 * always did.
 *
 * A schedule that continues from an existing row (an extension, or a rebuild
 * from the last paid row) keeps the LOAN's anchor day. A row an overflowing
 * month step pushed into the first days of the next month continues at that
 * month's anchored date: 3 Mar, stored for February by the older schedule of
 * a loan started on the 31st, is followed by 31 Mar, not 30 Apr. Every other
 * row continues in the following month.
 */
final class LoanTermSchedule
{
    public const DAYS_PER_MONTH = 30;

    public const DAYS_PER_YEAR = 360;

    /**
     * Days in one period of each frequency, on the 30-day-month convention.
     * `annual` is a rate period only: no payment frequency is yearly.
     */
    public const PERIOD_DAYS = [
        'daily' => 1,
        'weekly' => 7,
        'bi_weekly' => 14,
        'semi_monthly' => 15,
        'monthly' => self::DAYS_PER_MONTH,
        'annual' => self::DAYS_PER_YEAR,
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
     * The date `$months` calendar months after `$from`'s month, on
     * `$anchorDay` or on that month's last day if it is shorter.
     *
     * The primitive every calendar-month due date is made from. Month
     * arithmetic on `$from` itself would overflow (31 Jan + 1 month is 3 Mar)
     * or, clamped, forget the anchor for good (31 Jan -> 28 Feb -> 28 Mar).
     */
    public static function calendarMonthDueDate(Carbon $from, int $months, int $anchorDay): Carbon
    {
        $month = $from->copy()->day(1)->addMonths($months);

        return $month->day(min($anchorDay, $month->daysInMonth));
    }

    /**
     * The `$n`-th anchored date after the row dated `$from` (`$n` >= 1): on
     * `$anchorDay`, or on a shorter month's last day.
     *
     * A row an overflowing month step pushed into the first days of the next
     * month continues at that month's anchored date; every other row
     * continues in the following month. The older schedule chained
     * overflowing steps, so a loan anchored on the 29th-31st could land on
     * day 1-3 of the month after a shorter one (31 Jan -> 3 Mar) and stay on
     * that day from then on. That day-1-3-with-anchor-29-31 signature is
     * exact: an anchored schedule never puts a row there. Every other row,
     * whether a start, a capped 28 Feb or an imported maturity date, continues
     * in the following month.
     */
    public static function nthAnchoredDateAfter(Carbon $from, int $n, int $anchorDay): Carbon
    {
        $overflowedIntoThisMonth = $anchorDay > 28 && $from->day <= 3;

        return self::calendarMonthDueDate($from, $overflowedIntoThisMonth ? $n - 1 : $n, $anchorDay);
    }

    /**
     * The first anchored date after the row dated `$from` that falls strictly
     * after the day `$after`, and how many anchored cycles past `$from` it is.
     *
     * At least one cycle: a row due on or after `$after` still moves to its
     * next anchored date. A row long past `$after` moves as many cycles as it
     * takes to land after it, so an overdue loan extended today is never left
     * due on a date already gone: a loan anchored on the 27th, due 27 May and
     * extended on 2 Nov, moves six cycles to 27 Nov.
     *
     * @return array{due_date: Carbon, cycles: int}
     */
    public static function firstAnchoredDateAfter(Carbon $from, Carbon $after, int $anchorDay): array
    {
        $afterDay = $after->toDateString();
        $cycles = 1;
        $dueDate = self::nthAnchoredDateAfter($from, $cycles, $anchorDay);

        while ($dueDate->toDateString() <= $afterDay) {
            $cycles++;
            $dueDate = self::nthAnchoredDateAfter($from, $cycles, $anchorDay);
        }

        return ['due_date' => $dueDate, 'cycles' => $cycles];
    }

    /**
     * Each instalment's due date and the number of days it covers.
     *
     * An upon-maturity loan outside the calendar-month case is one instalment
     * covering the whole term.
     *
     * Calendar-month instalment `$i` is the `$i`-th date after `$start` on
     * `$anchorDay`, the start's own day by default. A schedule rebuilt from
     * the last paid row passes the loan's original day, so rebuilding from a
     * capped 28 Feb row carries on at the 31st rather than the 28th, and from
     * an overflowed 1 Mar row of a loan anchored on the 29th at 29 Mar.
     *
     * @return list<array{due_date: Carbon, days: int}>
     */
    public static function instalments(Carbon $start, int $term, string $termUnit, string $frequency, ?int $anchorDay = null): array
    {
        if (self::stepsByCalendarMonth($termUnit, $frequency)) {
            $instalments = [];

            for ($i = 1; $i <= $term; $i++) {
                $instalments[] = [
                    'due_date' => self::nthAnchoredDateAfter($start, $i, $anchorDay ?? $start->day),
                    'days' => self::DAYS_PER_MONTH,
                ];
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
     * The calendar-month case is the `$term`-th anchored date after the start,
     * exactly the last date instalments() gives for the same arguments.
     */
    public static function maturityDate(Carbon $start, int $term, string $termUnit, string $frequency, ?int $anchorDay = null): Carbon
    {
        if (self::stepsByCalendarMonth($termUnit, $frequency)) {
            return self::nthAnchoredDateAfter($start, $term, $anchorDay ?? $start->day);
        }

        return $start->copy()->addDays(self::termDays($term, $termUnit));
    }

    /**
     * The interest, as a fraction of principal, that `$days` days accrue at a
     * rate quoted per `$rateFrequency`.
     *
     * Exactly `$ratePercent / 100` when the days equal one rate period, so a
     * monthly rate on a monthly instalment is charged as it always was.
     *
     * A rate per year is worked out exactly, rate × days ÷ 36,000, and only
     * the result becomes a float, so 18% a year on 30 days is 0.015, the
     * figure 1.5% a month gives. The other periods keep the arithmetic every
     * existing loan was charged with.
     *
     * Since the follow-ups batch, only the diminishing instalment amount (the
     * PMT) and loans released before it (`legacy`) use this factor; each
     * period's interest is otherwise interestFor().
     */
    public static function rateForDays(float $ratePercent, string $rateFrequency, int $days): float
    {
        if ($rateFrequency === InterestRateFrequency::Annual->value) {
            return BigRational::of((string) $ratePercent)
                ->multipliedBy($days)
                ->dividedBy(100 * self::DAYS_PER_YEAR)
                ->toFloat();
        }

        $rateDays = self::PERIOD_DAYS[$rateFrequency];
        $factor = $days === $rateDays ? 1 : $days / $rateDays;

        return $ratePercent / 100 * $factor;
    }

    /**
     * The interest on `$amount` pesos for `$days` days at `$ratePercent` per
     * `$rateFrequency`, as a 2-decimal string: amount × rate × days ÷ (100 ×
     * the rate period's days), worked out exactly and rounded half up to the
     * centavo, so ₱48,334.50 at 1% a month for 10 days (exactly ₱161.115) is
     * ₱161.12. A year is 360 days, as in rateForDays().
     *
     * The interest of every loan released from the follow-ups batch on, and
     * of every draft and preview (Loan::roundsInterestExactly()). A loan
     * released before keeps the float factor of rateForDays().
     */
    public static function interestFor(string $amount, string $ratePercent, string $rateFrequency, int $days): string
    {
        return (string) BigRational::of($amount)
            ->multipliedBy($ratePercent)
            ->multipliedBy($days)
            ->dividedBy(100 * self::PERIOD_DAYS[$rateFrequency])
            ->toScale(2, RoundingMode::HalfUp);
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
