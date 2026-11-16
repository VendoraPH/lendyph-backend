<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Linda's periods are Manila calendar dates (`config('app.timezone')`):
 * named periods, month names and date ranges, with a future end cut back to
 * today and a span over 10 years refused.
 */
class LindaPeriodTest extends LindaTestCase
{
    /**
     * Sunday 2026-11-08 in Manila. At 00:30 the UTC date is still November 7.
     *
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function namedPeriodsOnSundayNovember8(): array
    {
        $cases = [];

        foreach (['00:30', '23:30'] as $time) {
            foreach ([
                ['today', 'today', '2026-11-08', '2026-11-08', 'November 8, 2026'],
                ['yesterday', 'yesterday', '2026-11-07', '2026-11-07', 'November 7, 2026'],
                ['this week', 'this_week', '2026-11-02', '2026-11-08', 'November 2 to 8, 2026'],
                ['last week', 'last_week', '2026-10-26', '2026-11-01', 'October 26 to November 1, 2026'],
                ['this month', 'this_month', '2026-11-01', '2026-11-08', 'November 1 to 8, 2026'],
                ['last month', 'last_month', '2026-10-01', '2026-10-31', 'October 1 to 31, 2026'],
                ['this quarter', 'this_quarter', '2026-10-01', '2026-11-08', 'October 1 to November 8, 2026'],
                ['this year', 'this_year', '2026-01-01', '2026-11-08', 'January 1 to November 8, 2026'],
            ] as [$phrase, $key, $from, $to, $label]) {
                $cases["{$phrase} at {$time}"] = ["2026-11-08 {$time}", $phrase, $key, $from, $to, $label];
            }
        }

        return $cases;
    }

    #[DataProvider('namedPeriodsOnSundayNovember8')]
    public function test_named_periods_use_the_manila_date(string $now, string $phrase, string $key, string $from, string $to, string $label): void
    {
        $this->atManila($now);

        $this->assertSame(
            ['from' => $from, 'to' => $to, 'label' => $label, 'key' => $key],
            LindaPeriod::parse($phrase)?->toArray(),
        );
        $this->assertSame(
            ['from' => $from, 'to' => $to, 'label' => $label, 'key' => $key],
            LindaPeriod::named($key)->toArray(),
        );
    }

    public function test_the_app_timezone_is_manila(): void
    {
        $this->assertSame('Asia/Manila', LindaPeriod::timezone());
    }

    public function test_weeks_start_on_monday(): void
    {
        $this->atManila('2026-11-09 00:30');

        $this->assertSame(['2026-11-09', '2026-11-09'], $this->range('this week'));
        $this->assertSame(['2026-11-02', '2026-11-08'], $this->range('last week'));
        $this->assertSame('November 9, 2026', LindaPeriod::parse('this week')?->label);

        $this->atManila('2026-11-08 23:30');

        $this->assertSame(['2026-11-02', '2026-11-08'], $this->range('this week'));
    }

    public function test_month_ends(): void
    {
        $this->atManila('2026-03-31 23:30');

        $this->assertSame(['2026-02-01', '2026-02-28'], $this->range('last month'));
        $this->assertSame(['2026-03-01', '2026-03-31'], $this->range('this month'));
        $this->assertSame(['2026-01-01', '2026-03-31'], $this->range('this quarter'));

        $this->atManila('2028-03-01 00:30');

        $this->assertSame(['2028-02-01', '2028-02-29'], $this->range('last month'));
        $this->assertSame(['2028-02-29', '2028-02-29'], $this->range('yesterday'));
    }

    public function test_new_years_day_reaches_back_into_last_year(): void
    {
        $this->atManila('2026-01-01 00:30');

        $this->assertSame(['2025-12-31', '2025-12-31'], $this->range('yesterday'));
        $this->assertSame(['2025-12-01', '2025-12-31'], $this->range('last month'));
        $this->assertSame(['2025-12-22', '2025-12-28'], $this->range('last week'));
        $this->assertSame(['2025-12-29', '2026-01-01'], $this->range('this week'));
        $this->assertSame(['2026-01-01', '2026-01-01'], $this->range('this quarter'));
        $this->assertSame(['2026-01-01', '2026-01-01'], $this->range('this year'));
        $this->assertSame('December 29, 2025 to January 1, 2026', LindaPeriod::parse('this week')?->label);
    }

    public function test_a_month_name_means_the_latest_one_that_has_started(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertSame(['2026-01-01', '2026-01-31'], $this->range('january'));
        $this->assertSame(['2026-11-01', '2026-11-08'], $this->range('november'));
        $this->assertSame(['2025-12-01', '2025-12-31'], $this->range('december'));
        $this->assertSame(['2026-02-01', '2026-02-28'], $this->range('Feb'));
        $this->assertSame(['2026-09-01', '2026-09-30'], $this->range('sept'));
        $this->assertSame(['2025-01-01', '2025-01-31'], $this->range('january 2025'));
        $this->assertSame('January 1 to 31, 2026', LindaPeriod::parse('january')?->label);
        $this->assertNull(LindaPeriod::parse('january')?->key);

        $this->atManila('2026-01-15 00:30');

        $this->assertSame(['2026-01-01', '2026-01-15'], $this->range('january'));
        $this->assertSame(['2025-12-01', '2025-12-31'], $this->range('december'));
        $this->assertSame(['2025-02-01', '2025-02-28'], $this->range('february'));
    }

    public function test_month_names_in_tagalog_and_may_only_where_it_is_a_month(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertSame(['2026-01-01', '2026-01-31'], $this->range('enero'));
        $this->assertSame(['2026-05-01', '2026-05-31'], $this->range('mayo'));
        $this->assertSame(['2026-05-01', '2026-05-31'], $this->range('in may'));
        $this->assertSame(['2026-05-01', '2026-05-31'], $this->range('noong may'));
        $this->assertSame(['2025-05-01', '2025-05-31'], $this->range('may 2025'));
        $this->assertSame(['2026-05-03', '2026-05-03'], $this->range('may 3'));

        // "May" on its own is Tagalog for "there is", never a month.
        $this->assertNull(LindaPeriod::parse('may'));
        $this->assertNull(LindaPeriod::extract('may overdue ba')['period']);
    }

    public function test_a_future_month_with_its_year_is_refused(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertNull(LindaPeriod::parse('december 2026'));
        $this->assertTrue(LindaPeriod::extract('december 2026')['refused']);
    }

    public function test_ranges_without_a_year(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertSame(['2026-11-01', '2026-11-05'], $this->range('November 1 to 5'));
        $this->assertSame(['2026-11-01', '2026-11-05'], $this->range('nov 1-5'));
        $this->assertSame(['2026-10-15', '2026-11-03'], $this->range('from October 15 until November 3'));
        $this->assertSame(['2026-10-01', '2026-10-15'], $this->range('1 to 15 October'));
        $this->assertSame(['2026-11-03', '2026-11-03'], $this->range('November 3'));
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->range('oktubre 1 hanggang 31'));

        // December 20 hasn't come yet this year, so it is last December's.
        $this->assertSame(['2025-12-20', '2026-01-05'], $this->range('December 20 to January 5'));
        $this->assertSame('December 20, 2025 to January 5, 2026', LindaPeriod::parse('December 20 to January 5')?->label);
    }

    public function test_ranges_with_a_year(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertSame(['2026-11-01', '2026-11-08'], $this->range('November 1 to 8, 2026'));
        $this->assertSame('November 1 to 8, 2026', LindaPeriod::parse('November 1 to 8, 2026')?->label);
        $this->assertSame(['2025-10-01', '2025-10-15'], $this->range('from Oct 1 until Oct 15, 2025'));
        $this->assertSame(['2025-12-20', '2026-01-05'], $this->range('December 20, 2025 to January 5, 2026'));
        $this->assertSame(['2025-12-20', '2026-01-05'], $this->range('December 20 to January 5, 2026'));
        $this->assertSame(['2024-03-01', '2024-03-31'], $this->range('March 1 2024 to March 31'));
        $this->assertSame(['2026-10-01', '2026-10-15'], $this->range('2026-10-01 to 2026-10-15'));
        $this->assertSame(['2026-10-01', '2026-10-15'], $this->range('between 1 and 15 October 2026'));
        $this->assertNull(LindaPeriod::parse('November 1 to 8, 2026')?->key);
    }

    public function test_a_future_end_is_cut_back_to_today(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertSame(['2026-11-01', '2026-11-08'], $this->range('November 1 to 30'));
        $this->assertSame('November 1 to 8, 2026', LindaPeriod::parse('November 1 to 30')?->label);
        $this->assertSame(['2026-10-01', '2026-11-08'], $this->range('October 1 to December 31, 2026'));
    }

    public function test_a_period_that_has_not_started_is_refused(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertNull(LindaPeriod::parse('December 1 to 5, 2026'));
        $this->assertTrue(LindaPeriod::extract('december 1 to 5 2026')['refused']);
    }

    public function test_a_span_over_ten_years_is_refused(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertNull(LindaPeriod::parse('January 1, 2016 to November 8, 2026'));
        $this->assertNull(LindaPeriod::parse('January 1, 2016 to January 1, 2026'));
        $this->assertTrue(LindaPeriod::extract('january 1 2016 to november 8 2026')['refused']);

        $this->assertSame(['2016-01-01', '2025-12-31'], $this->range('January 1, 2016 to December 31, 2025'));
        // The end is cut back to today first, which brings this one under 10 years.
        $this->assertSame(['2017-01-01', '2026-11-08'], $this->range('January 1, 2017 to December 31, 2030'));
    }

    public function test_impossible_dates_and_reversed_ranges_are_refused(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertNull(LindaPeriod::parse('February 30'));
        $this->assertNull(LindaPeriod::parse('November 5 to 1, 2026'));
        $this->assertNull(LindaPeriod::between(
            CarbonImmutable::parse('2026-11-05'),
            CarbonImmutable::parse('2026-11-01'),
        ));
    }

    public function test_between_cuts_and_refuses_like_the_parser(): void
    {
        $this->atManila('2026-11-08 00:30');

        $this->assertSame(
            ['from' => '2026-11-01', 'to' => '2026-11-08', 'label' => 'November 1 to 8, 2026', 'key' => null],
            LindaPeriod::between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-31'))?->toArray(),
        );
        $this->assertNull(LindaPeriod::between(CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-10')));
        $this->assertNull(LindaPeriod::between(CarbonImmutable::parse('2010-01-01'), CarbonImmutable::parse('2026-01-01')));
    }

    public function test_tagalog_named_periods(): void
    {
        $this->atManila('2026-11-08 00:30');

        $this->assertSame('today', LindaPeriod::parse('ngayong araw')?->key);
        $this->assertSame('yesterday', LindaPeriod::parse('kahapon')?->key);
        $this->assertSame('this_week', LindaPeriod::parse('ngayong linggo')?->key);
        $this->assertSame('last_week', LindaPeriod::parse('noong nakaraang linggo')?->key);
        $this->assertSame('this_month', LindaPeriod::parse('ngayong buwan')?->key);
        $this->assertSame('last_month', LindaPeriod::parse('nakaraang buwan')?->key);
        $this->assertSame('last_month', LindaPeriod::parse('noong isang buwan')?->key);
        $this->assertSame('this_year', LindaPeriod::parse('ngayong taon')?->key);
        $this->assertSame('this_quarter', LindaPeriod::parse('ngayong quarter')?->key);
    }

    public function test_extract_returns_the_words_around_the_period(): void
    {
        $this->atManila('2026-11-08 12:00');

        $found = LindaPeriod::extract('how much have we collected this month so far');

        $this->assertSame('this_month', $found['period']?->key);
        $this->assertSame('how much have we collected so far', $found['rest']);
        $this->assertFalse($found['refused']);

        $none = LindaPeriod::extract('how many active loans do we have');

        $this->assertNull($none['period']);
        $this->assertSame('how many active loans do we have', $none['rest']);
        $this->assertFalse($none['refused']);
    }

    public function test_parse_needs_the_whole_text_to_be_a_period(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertNull(LindaPeriod::parse('collections last month'));
        $this->assertNull(LindaPeriod::parse(''));
        $this->assertSame('last_month', LindaPeriod::parse('  Last Month? ')?->key);
    }

    public function test_an_unknown_named_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LindaPeriod::named('next_month');
    }

    /**
     * @return array{string, string}|null
     */
    private function range(string $text): ?array
    {
        $period = LindaPeriod::parse($text);

        return $period === null ? null : [$period->from->toDateString(), $period->to->toDateString()];
    }
}
