<?php

namespace Tests\Feature\Phase6\ExecutiveFigures;

use App\Services\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6 EB-A: the executive dashboard's periods, worked out on the server in
 * Manila time (spec §4's presets) with #614's comparison rule: the same number
 * of days ending the day before, or the whole previous month for a whole
 * calendar month. "This …" periods run to today.
 */
class DashboardPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 23:30 UTC on Nov 19 is 07:30 on Nov 20 in Manila.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-19 23:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function presets(): array
    {
        return [
            'today' => ['today', '2026-11-20', '2026-11-20', '2026-11-19', '2026-11-19'],
            'yesterday' => ['yesterday', '2026-11-19', '2026-11-19', '2026-11-18', '2026-11-18'],
            'last 7 days' => ['last_7_days', '2026-11-14', '2026-11-20', '2026-11-07', '2026-11-13'],
            'last 30 days' => ['last_30_days', '2026-10-22', '2026-11-20', '2026-09-22', '2026-10-21'],
            'this month' => ['this_month', '2026-11-01', '2026-11-20', '2026-10-12', '2026-10-31'],
            'last month (a whole month)' => ['last_month', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'],
            'this quarter' => ['this_quarter', '2026-10-01', '2026-11-20', '2026-08-11', '2026-09-30'],
            'last quarter' => ['last_quarter', '2026-07-01', '2026-09-30', '2026-03-31', '2026-06-30'],
            'this year' => ['this_year', '2026-01-01', '2026-11-20', '2025-02-11', '2025-12-31'],
            'last year' => ['last_year', '2025-01-01', '2025-12-31', '2024-01-02', '2024-12-31'],
        ];
    }

    #[DataProvider('presets')]
    public function test_each_preset_and_its_comparison_in_manila_time(string $preset, string $from, string $to, string $previousFrom, string $previousTo): void
    {
        $period = DashboardPeriod::resolve($preset);

        $this->assertSame($preset, $period->preset);
        $this->assertSame($from, $period->from->toDateString());
        $this->assertSame($to, $period->to->toDateString());
        $this->assertSame($previousFrom, $period->previousFrom->toDateString());
        $this->assertSame($previousTo, $period->previousTo->toDateString());
        $this->assertSame($to, $period->asOf->toDateString());

        if ($preset !== 'last_month') {
            $this->assertSame($period->days(), $period->previousDays(), 'The same number of days.');
        }
    }

    public function test_the_period_as_an_array(): void
    {
        $this->assertSame([
            'preset' => 'this_month',
            'label' => 'This Month',
            'from' => '2026-11-01',
            'to' => '2026-11-20',
            'days' => 20,
            'previous' => ['from' => '2026-10-12', 'to' => '2026-10-31', 'days' => 20],
            'as_of' => '2026-11-20',
        ], DashboardPeriod::resolve('this_month')->toArray());
    }

    public function test_a_custom_range_compares_with_the_days_before_it(): void
    {
        $period = DashboardPeriod::resolve('custom', '2026-03-10', '2026-03-16');

        $this->assertSame('2026-03-03', $period->previousFrom->toDateString());
        $this->assertSame('2026-03-09', $period->previousTo->toDateString());
        $this->assertSame('2026-03-16', $period->asOf->toDateString());
    }

    public function test_a_custom_whole_month_compares_with_the_whole_month_before(): void
    {
        $period = DashboardPeriod::resolve('custom', '2026-03-01', '2026-03-31');

        $this->assertSame('2026-02-01', $period->previousFrom->toDateString());
        $this->assertSame('2026-02-28', $period->previousTo->toDateString());
    }

    public function test_a_preset_ignores_the_dates_sent_with_it(): void
    {
        $period = DashboardPeriod::resolve('yesterday', '2020-01-01', '2020-01-31');

        $this->assertSame('2026-11-19', $period->from->toDateString());
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: string}>
     */
    public static function refusals(): array
    {
        return [
            'an unknown preset' => ['this_week', null, null, 'Pick one of the listed periods.'],
            'custom without dates' => ['custom', null, null, 'A custom period needs a start date and an end date.'],
            'an unreadable date' => ['custom', '2026-02-30', '2026-03-01', 'A custom period needs a start date and an end date.'],
            'an end before the start' => ['custom', '2026-03-10', '2026-03-01', 'The end date can\'t be before the start date.'],
            'an end after today' => ['custom', '2026-11-01', '2026-11-21', 'The end date can\'t be after today.'],
            'more than ten years' => ['custom', '2016-11-19', '2026-11-20', 'A period can cover at most 10 years.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_period_that_can_t_be_read_is_refused_in_words(string $preset, ?string $from, ?string $to, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DashboardPeriod::resolve($preset, $from, $to);
    }

    public function test_every_preset_has_a_label(): void
    {
        $this->assertSame(
            ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'custom'],
            array_keys(DashboardPeriod::PRESETS),
        );
    }
}
