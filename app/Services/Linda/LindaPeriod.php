<?php

namespace App\Services\Linda;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A period a Linda question asks about: two Manila calendar dates, both
 * inclusive, with a label to show ("November 1 to 8, 2026") and, for a named
 * period, its key ("this_month").
 *
 * Also the parser that finds one in a question. It reads named periods in
 * English and Tagalog, month names (the latest one that has started; the
 * current month runs to today), single dates, and date ranges with or without
 * a year. A future end is cut back to today. A period that hasn't started,
 * an impossible date and a span over 10 years are refused.
 *
 * Every "today" is the Manila date (`config('app.timezone')`), never UTC's.
 */
final class LindaPeriod
{
    /** The named periods, in the order the plan lists them. */
    public const KEYS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'this_year'];

    /** The longest span asked about, in years. */
    public const MAX_YEARS = 10;

    /** Why a period is refused: it hasn't started yet. */
    public const REFUSED_FUTURE = 'future';

    /** Why a period is refused: it spans more than self::MAX_YEARS years. */
    public const REFUSED_TOO_LONG = 'too_long';

    /** Why a period is refused: an impossible date, or an end before the start. */
    public const REFUSED_INVALID = 'invalid';

    /**
     * Phrases for each named period, in cleaned text (LindaText::clean()).
     *
     * @var array<string, list<string>>
     */
    private const NAMED_PHRASES = [
        'today' => ['today', 'ngayong araw na ito', 'ngayong araw', 'ngayon araw'],
        'yesterday' => ['yesterday', 'kahapon'],
        'this_week' => ['this week', 'week to date', 'ngayong linggong ito', 'ngayong linggo', 'ngayong week'],
        'last_week' => ['last week', 'previous week', 'noong nakaraang linggo', 'nakaraang linggo', 'noong isang linggo', 'nakaraang week'],
        'this_month' => ['this month', 'month to date', 'ngayong buwang ito', 'ngayong buwan', 'ngayong month', 'buwang ito'],
        'last_month' => ['last month', 'previous month', 'noong nakaraang buwan', 'nakaraang buwan', 'noong isang buwan', 'nakaraang month'],
        'this_quarter' => ['this quarter', 'quarter to date', 'ngayong quarter'],
        'this_year' => ['this year', 'year to date', 'ytd', 'ngayong taong ito', 'ngayong taon', 'ngayong year'],
    ];

    /**
     * Month names and abbreviations, English and Tagalog. "May" is left out:
     * on its own it is Tagalog for "there is", so it is a month only where
     * the patterns below say so.
     *
     * @var array<string, int>
     */
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'enero' => 1,
        'february' => 2, 'feb' => 2, 'pebrero' => 2,
        'march' => 3, 'mar' => 3, 'marso' => 3,
        'april' => 4, 'apr' => 4, 'abril' => 4,
        'mayo' => 5,
        'june' => 6, 'jun' => 6, 'hunyo' => 6,
        'july' => 7, 'jul' => 7, 'hulyo' => 7,
        'august' => 8, 'aug' => 8, 'agosto' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'setyembre' => 9, 'septiyembre' => 9,
        'october' => 10, 'oct' => 10, 'oktubre' => 10,
        'november' => 11, 'nov' => 11, 'nobyembre' => 11,
        'december' => 12, 'dec' => 12, 'disyembre' => 12,
    ];

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        public readonly ?string $key = null,
    ) {}

    /** The timezone every Linda date is read in. */
    public static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    /** Today's date in Manila, at midnight. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * A named period, as of today.
     *
     * @throws InvalidArgumentException for a key not in self::KEYS
     */
    public static function named(string $key): self
    {
        $today = self::today();

        [$from, $to] = match ($key) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek(CarbonInterface::MONDAY), $today],
            'last_week' => [
                $today->startOfWeek(CarbonInterface::MONDAY)->subWeek(),
                $today->startOfWeek(CarbonInterface::MONDAY)->subDay(),
            ],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$today->startOfQuarter(), $today],
            'this_year' => [$today->startOfYear(), $today],
            default => throw new InvalidArgumentException("Unknown Linda period [{$key}]."),
        };

        return new self($from, $to, self::label($from, $to), $key);
    }

    /**
     * The period between two calendar dates, both inclusive. Only the dates
     * are read, not the times or timezones they carry.
     *
     * An end after today is cut back to today. Null when the start is after
     * the end, the period hasn't started yet, or it spans more than
     * self::MAX_YEARS years.
     */
    public static function between(CarbonInterface $from, CarbonInterface $to): ?self
    {
        $period = self::checked($from, $to);

        return $period instanceof self ? $period : null;
    }

    /**
     * The period a whole text names ("last month", "November 1 to 8, 2026"),
     * or null when the text is anything more or less than one period, or the
     * period is refused.
     */
    public static function parse(string $text): ?self
    {
        $found = self::extract(LindaText::clean($text));

        return $found['rest'] === '' && ! $found['refused'] ? $found['period'] : null;
    }

    /**
     * Why the period a whole text names is refused (self::REFUSED_*), or null
     * when the text names no period, more than one, or one that is accepted.
     */
    public static function refusal(string $text): ?string
    {
        $found = self::extract(LindaText::clean($text));

        return $found['rest'] === '' ? $found['reason'] : null;
    }

    /**
     * Find one period in a cleaned question (LindaText::clean()).
     *
     * `rest` is the question with the period's words taken out. `refused` is
     * true when the question names a period that can't be answered (not
     * started yet, impossible, or over 10 years); `period` is then null and
     * `reason` says why (self::REFUSED_*).
     *
     * @return array{period: ?self, rest: string, refused: bool, reason: ?string}
     */
    public static function extract(string $clean): array
    {
        foreach (self::patterns() as [$pattern, $resolve]) {
            if (preg_match($pattern, $clean, $found, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $groups = [];
            foreach ($found as $name => [$value, $offset]) {
                if (is_string($name) && $offset !== -1 && $value !== '') {
                    $groups[$name] = $value;
                }
            }

            $rest = trim(preg_replace('/\s+/', ' ', substr_replace($clean, ' ', $found[0][1], strlen($found[0][0]))) ?? '');
            $period = $resolve($groups);

            return $period instanceof self
                ? ['period' => $period, 'rest' => $rest, 'refused' => false, 'reason' => null]
                : ['period' => null, 'rest' => $rest, 'refused' => true, 'reason' => $period];
        }

        return ['period' => null, 'rest' => $clean, 'refused' => false, 'reason' => null];
    }

    /**
     * @return array{from: string, to: string, label: string, key: ?string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label,
            'key' => $this->key,
        ];
    }

    /**
     * Each pattern with the function that turns its named groups into a
     * period. The first pattern found anywhere in the question wins, so
     * ranges come before single dates, and dates before bare month names.
     *
     * @return list<array{string, callable(array<string, string>): (self|string)}>
     */
    private static function patterns(): array
    {
        $month = '(?:'.implode('|', array_keys(self::MONTHS)).'|may)';
        $bareMonth = '(?:'.implode('|', array_keys(self::MONTHS)).')';
        $day = '\d{1,2}(?:st|nd|rd|th)?(?!\d)';
        $year = '\d{4}(?!\d)';
        $iso = '\d{4}-\d{2}-\d{2}';
        $from = '(?:(?<between>between)\s+|(?:from|mula|simula)\s+(?:sa\s+|noong\s+)?)?';
        $to = '\s+(?:(?<and>and|at)|to|until|till|through|thru|hanggang(?:\s+sa)?)\s+';
        $edge = fn (string $body): string => '/(?<![\pL\pN])'.$body.'(?![\pL\pN])/u';

        $range = fn (array $g): self|string => self::rangeOrNull($g, fn () => self::range(
            self::monthNumber($g['m1'] ?? $g['m2']), (int) $g['d1'], isset($g['y1']) ? (int) $g['y1'] : null,
            self::monthNumber($g['m2'] ?? $g['m1']), (int) $g['d2'], isset($g['y2']) ? (int) $g['y2'] : null,
        ));

        $named = [];
        foreach (self::NAMED_PHRASES as $key => $phrases) {
            foreach ($phrases as $phrase) {
                $named[$phrase] = $key;
            }
        }
        uksort($named, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return [
            [
                $edge($from."(?<iso1>{$iso}){$to}(?<iso2>{$iso})"),
                fn (array $g): self|string => self::rangeOrNull($g, fn () => self::isoRange($g['iso1'], $g['iso2'])),
            ],
            [
                $edge($from."(?<m1>{$month})\s+(?<d1>{$day})(?:\s+(?<y1>{$year}))?{$to}(?:(?<m2>{$month})\s+)?(?<d2>{$day})(?:\s+(?<y2>{$year}))?"),
                $range,
            ],
            [
                $edge($from."(?<d1>{$day})(?:\s+(?<m1>{$month}))?(?:\s+(?<y1>{$year}))?{$to}(?<d2>{$day})\s+(?<m2>{$month})(?:\s+(?<y2>{$year}))?"),
                $range,
            ],
            [
                $edge("(?:on\s+|noong\s+)?(?<iso1>{$iso})"),
                fn (array $g): self|string => self::isoRange($g['iso1'], $g['iso1']),
            ],
            [
                $edge("(?:on\s+|noong\s+)?(?<m1>{$month})\s+(?<d1>{$day})(?:\s+(?<y1>{$year}))?"),
                fn (array $g): self|string => self::singleDay(self::monthNumber($g['m1']), (int) $g['d1'], isset($g['y1']) ? (int) $g['y1'] : null),
            ],
            [
                $edge("(?:on\s+|noong\s+)?(?<d1>{$day})\s+(?<m1>{$month})(?:\s+(?<y1>{$year}))?"),
                fn (array $g): self|string => self::singleDay(self::monthNumber($g['m1']), (int) $g['d1'], isset($g['y1']) ? (int) $g['y1'] : null),
            ],
            [
                $edge("(?<m1>{$month})\s+(?<y1>{$year})"),
                fn (array $g): self|string => self::month(self::monthNumber($g['m1']), (int) $g['y1']),
            ],
            [
                $edge('(?<named>'.implode('|', array_map(fn (string $phrase) => preg_quote($phrase, '/'), array_keys($named))).')'),
                fn (array $g): self|string => self::named($named[$g['named']]),
            ],
            [
                $edge("(?:(?:in|noong|nung|during)\s+(?<m1>may)|(?<m2>{$bareMonth}))"),
                fn (array $g): self|string => self::month(self::monthNumber($g['m1'] ?? $g['m2']), null),
            ],
        ];
    }

    /**
     * "and" joins a range only after "between": "between 1 and 15 October".
     *
     * @param  array<string, string>  $groups
     * @param  callable(): (self|string)  $build
     */
    private static function rangeOrNull(array $groups, callable $build): self|string
    {
        if (isset($groups['and']) && ! isset($groups['between'])) {
            return self::REFUSED_INVALID;
        }

        return $build();
    }

    private static function monthNumber(string $name): int
    {
        return $name === 'may' ? 5 : self::MONTHS[$name];
    }

    /**
     * A range written with month names. A missing year is taken from the
     * other end, or else is the latest year in which the start has come. An
     * end in an earlier month runs into the next year ("December 20 to
     * January 5"); an earlier day in the same month is reversed and refused.
     */
    private static function range(int $fromMonth, int $fromDay, ?int $fromYear, int $toMonth, int $toDay, ?int $toYear): self|string
    {
        $endsBeforeStart = $toMonth < $fromMonth;

        if ($toMonth === $fromMonth && $toDay < $fromDay && ($fromYear === null || $toYear === null)) {
            return self::REFUSED_INVALID;
        }

        if ($fromYear === null && $toYear === null) {
            $fromYear = self::latestStartedYear($fromMonth, $fromDay);
        }

        if ($fromYear === null) {
            $fromYear = $endsBeforeStart ? $toYear - 1 : $toYear;
        }

        $toYear ??= $endsBeforeStart ? $fromYear + 1 : $fromYear;

        $from = self::date($fromYear, $fromMonth, $fromDay);
        $to = self::date($toYear, $toMonth, $toDay);

        return $from === null || $to === null ? self::REFUSED_INVALID : self::checked($from, $to);
    }

    private static function isoRange(string $from, string $to): self|string
    {
        $fromDate = self::date(...array_map('intval', explode('-', $from)));
        $toDate = self::date(...array_map('intval', explode('-', $to)));

        return $fromDate === null || $toDate === null ? self::REFUSED_INVALID : self::checked($fromDate, $toDate);
    }

    private static function singleDay(int $month, int $day, ?int $year): self|string
    {
        $date = self::date($year ?? self::latestStartedYear($month, $day), $month, $day);

        return $date === null ? self::REFUSED_INVALID : self::checked($date, $date);
    }

    /**
     * A whole month. Without a year it is the latest one that has started,
     * and the current month runs to today.
     */
    private static function month(int $month, ?int $year): self|string
    {
        $from = self::date($year ?? self::latestStartedYear($month, 1), $month, 1);

        return $from === null ? self::REFUSED_INVALID : self::checked($from, $from->endOfMonth());
    }

    /**
     * The period between two calendar dates (see self::between()), or why it
     * is refused (self::REFUSED_*).
     */
    private static function checked(CarbonInterface $from, CarbonInterface $to): self|string
    {
        $timezone = self::timezone();
        $today = self::today();
        $from = CarbonImmutable::parse($from->toDateString(), $timezone);
        $to = CarbonImmutable::parse($to->toDateString(), $timezone);

        if ($from->gt($to)) {
            return self::REFUSED_INVALID;
        }

        if ($from->gt($today)) {
            return self::REFUSED_FUTURE;
        }

        if ($to->gt($today)) {
            $to = $today;
        }

        if ($to->gte($from->addYears(self::MAX_YEARS))) {
            return self::REFUSED_TOO_LONG;
        }

        return new self($from, $to, self::label($from, $to));
    }

    /** This year, unless that date is still to come, then last year. */
    private static function latestStartedYear(int $month, int $day): int
    {
        $today = self::today();
        $thisYear = $today->year;

        return $month * 100 + $day > $today->month * 100 + $today->day ? $thisYear - 1 : $thisYear;
    }

    private static function date(int $year, int $month, int $day): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::timezone());
    }

    private static function label(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('F j, Y');
        }

        if ($from->year !== $to->year) {
            return $from->format('F j, Y').' to '.$to->format('F j, Y');
        }

        if ($from->month === $to->month) {
            return $from->format('F j').' to '.$to->format('j, Y');
        }

        return $from->format('F j').' to '.$to->format('F j, Y');
    }
}
