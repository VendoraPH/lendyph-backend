<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An executive dashboard period: one of the spec's presets (§4) or a custom
 * range, with its comparison period, worked out on the server in Manila time
 * so the browser's own time zone never moves a day.
 *
 * - Every range is whole days, both ends included.
 * - "This …" periods run to today: a figure for days that haven't happened
 *   yet would be a forecast.
 * - The comparison is #614's rule: the same number of days ending the day
 *   before the period starts, except that a whole calendar month compares
 *   with the whole month before it, whatever their lengths.
 * - `as_of` is the end date, the date a balance figure is read on.
 */
final class DashboardPeriod
{
    public const TIMEZONE = 'Asia/Manila';

    /** The presets, in the filter bar's order, with their labels. */
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 Days',
        'last_30_days' => 'Last 30 Days',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'this_quarter' => 'This Quarter',
        'last_quarter' => 'Last Quarter',
        'this_year' => 'This Year',
        'last_year' => 'Last Year',
        'custom' => 'Custom Date Range',
    ];

    /** The widest period, as the reports cap theirs (ReportController::MAX_SPAN_YEARS). */
    public const MAX_SPAN_YEARS = 10;

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly CarbonImmutable $previousFrom,
        public readonly CarbonImmutable $previousTo,
        public readonly CarbonImmutable $asOf,
    ) {}

    /**
     * A preset counted from today in Manila, or a custom range from `$from`
     * and `$to` (YYYY-MM-DD). A preset ignores the dates sent with it.
     *
     * @throws InvalidArgumentException in words, when the period can't be read
     */
    public static function resolve(string $preset, ?string $from = null, ?string $to = null): self
    {
        if (! array_key_exists($preset, self::PRESETS)) {
            throw new InvalidArgumentException('Pick one of the listed periods.');
        }

        $today = self::today();

        [$start, $end] = $preset === 'custom'
            ? self::custom($from, $to, $today)
            : self::range($preset, $today);

        [$previousFrom, $previousTo] = self::comparison($start, $end);

        return new self($preset, $start, $end, $previousFrom, $previousTo, $end);
    }

    /** Today in Manila, at midnight. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /** The period's length in days, both ends included. */
    public function days(): int
    {
        return self::daysBetween($this->from, $this->to);
    }

    /** The comparison period's length in days, both ends included. */
    public function previousDays(): int
    {
        return self::daysBetween($this->previousFrom, $this->previousTo);
    }

    /**
     * @return array{preset: string, label: string, from: string, to: string, days: int, previous: array{from: string, to: string, days: int}, as_of: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'label' => self::PRESETS[$this->preset],
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
            'previous' => [
                'from' => $this->previousFrom->toDateString(),
                'to' => $this->previousTo->toDateString(),
                'days' => $this->previousDays(),
            ],
            'as_of' => $this->asOf->toDateString(),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function range(string $preset, CarbonImmutable $today): array
    {
        $quarterStart = $today->firstOfQuarter();

        return match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$quarterStart, $today],
            'last_quarter' => [$quarterStart->subMonthsNoOverflow(3), $quarterStart->subDay()],
            'this_year' => [$today->startOfYear(), $today],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()],
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function custom(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $start = self::date($from);
        $end = self::date($to);

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('A custom period needs a start date and an end date.');
        }

        if ($end->lt($start)) {
            throw new InvalidArgumentException('The end date can\'t be before the start date.');
        }

        if ($end->gt($today)) {
            throw new InvalidArgumentException('The end date can\'t be after today.');
        }

        if ($start->lt($end->subYearsNoOverflow(self::MAX_SPAN_YEARS))) {
            throw new InvalidArgumentException('A period can cover at most '.self::MAX_SPAN_YEARS.' years.');
        }

        return [$start, $end];
    }

    /**
     * #614's rule: a whole calendar month compares with the whole month
     * before it; any other period with the same number of days ending the day
     * before it starts.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function comparison(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $wholeMonth = $from->day === 1
            && $from->isSameMonth($to)
            && $to->day === $to->daysInMonth;

        if ($wholeMonth) {
            $previous = $from->subMonthNoOverflow();

            return [$previous->startOfMonth(), $previous->endOfMonth()->startOfDay()];
        }

        return [$from->subDays(self::daysBetween($from, $to)), $from->subDay()];
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
    }

    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $from->diffInDays($to) + 1;
    }
}
