<?php

namespace App\Services\Amla\Foundation;

use App\Models\AmlaCalendarEntry;
use App\Models\AmlaCalendarYear;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The AMLA working-day calendar (AMLA design §4.15). A working day is a Monday
 * to Friday that holds no entry in force: no holiday, AMLC non-reporting day
 * or facility suspension. Entries and year confirmations come only from
 * approved change requests. Dates are Manila calendar dates (YYYY-MM-DD).
 */
final class WorkingDayCalendar
{
    public const TIMEZONE = 'Asia/Manila';

    /** How far addWorkingDays() looks before it gives up: ten years of days. */
    private const MAX_DAYS = 3660;

    /** Each look-ahead reads this many days of entries in one query. */
    private const WINDOW_DAYS = 62;

    public function isWorkingDay(string $date): bool
    {
        $day = CarbonImmutable::parse($date, self::TIMEZONE);

        return ! $day->isWeekend() && ! AmlaCalendarEntry::query()->active()->whereDate('date', $day->toDateString())->exists();
    }

    /**
     * The `$count`th working day after `$from`, counting from the next day:
     * Tuesday plus 1 is Wednesday, Friday plus 1 is Monday.
     */
    public function addWorkingDays(string $from, int $count): string
    {
        $day = CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay();
        $closed = [];
        $loadedUntil = $day;
        $counted = 0;

        for ($step = 1; $step <= self::MAX_DAYS; $step++) {
            $day = $day->addDay();

            if ($day->greaterThan($loadedUntil)) {
                $loadedUntil = $day->addDays(self::WINDOW_DAYS - 1);
                $closed += $this->closedDates($day->toDateString(), $loadedUntil->toDateString());
            }

            if (! $day->isWeekend() && ! isset($closed[$day->toDateString()])) {
                $counted++;

                if ($counted === $count) {
                    return $day->toDateString();
                }
            }
        }

        throw new RuntimeException("No {$count} working days found within ten years after {$from}.");
    }

    /**
     * The years from `$fromYear` to `$toYear` whose calendar isn't confirmed.
     *
     * @return list<int>
     */
    public function unconfirmedYears(int $fromYear, int $toYear): array
    {
        $confirmed = AmlaCalendarYear::query()->whereBetween('year', [$fromYear, $toYear])->pluck('year')->map(fn ($year) => (int) $year)->all();

        return array_values(array_diff(range($fromYear, $toYear), $confirmed));
    }

    /**
     * Dates holding an entry in force, between two dates inclusive.
     *
     * @return array<string, true>
     */
    private function closedDates(string $from, string $to): array
    {
        return AmlaCalendarEntry::query()->active()
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [CarbonImmutable::parse($date)->toDateString() => true])
            ->all();
    }
}
