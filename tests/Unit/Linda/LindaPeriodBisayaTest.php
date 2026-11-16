<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaPeriod;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Bisaya/Cebuano periods beside the English and Tagalog ones: named periods,
 * month names, ranges and date prefixes, all read as Manila dates.
 */
class LindaPeriodBisayaTest extends LindaTestCase
{
    /**
     * Sunday, November 8, 2026, 00:30 in Manila: still November 7 in UTC.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function namedPeriods(): array
    {
        return [
            'karong adlawa' => ['karong adlawa', 'today', '2026-11-08', '2026-11-08'],
            'karon nga adlaw' => ['karon nga adlaw', 'today', '2026-11-08', '2026-11-08'],
            'karon' => ['karon', 'today', '2026-11-08', '2026-11-08'],
            'karon dayon' => ['karon dayon', 'today', '2026-11-08', '2026-11-08'],
            'gahapon' => ['gahapon', 'yesterday', '2026-11-07', '2026-11-07'],
            'kagahapon' => ['kagahapon', 'yesterday', '2026-11-07', '2026-11-07'],
            'karong semanaha' => ['karong semanaha', 'this_week', '2026-11-02', '2026-11-08'],
            'karon nga semana' => ['karon nga semana', 'this_week', '2026-11-02', '2026-11-08'],
            'kining semanaha' => ['kining semanaha', 'this_week', '2026-11-02', '2026-11-08'],
            'niaging semana' => ['niaging semana', 'last_week', '2026-10-26', '2026-11-01'],
            'miaging semana' => ['miaging semana', 'last_week', '2026-10-26', '2026-11-01'],
            'sa niaging semana' => ['sa niaging semana', 'last_week', '2026-10-26', '2026-11-01'],
            'karong bulana' => ['karong bulana', 'this_month', '2026-11-01', '2026-11-08'],
            'karon nga bulan' => ['karon nga bulan', 'this_month', '2026-11-01', '2026-11-08'],
            'kining bulana' => ['kining bulana', 'this_month', '2026-11-01', '2026-11-08'],
            'niaging bulan' => ['niaging bulan', 'last_month', '2026-10-01', '2026-10-31'],
            'miaging bulan' => ['miaging bulan', 'last_month', '2026-10-01', '2026-10-31'],
            'sa niaging bulan' => ['sa niaging bulan', 'last_month', '2026-10-01', '2026-10-31'],
            'karong tuiga' => ['karong tuiga', 'this_year', '2026-01-01', '2026-11-08'],
            'karon nga tuig' => ['karon nga tuig', 'this_year', '2026-01-01', '2026-11-08'],
            'kining tuiga' => ['kining tuiga', 'this_year', '2026-01-01', '2026-11-08'],
            'karong quarter' => ['karong quarter', 'this_quarter', '2026-10-01', '2026-11-08'],
        ];
    }

    #[DataProvider('namedPeriods')]
    public function test_bisaya_named_periods_use_the_manila_date(string $phrase, string $key, string $from, string $to): void
    {
        $this->atManila('2026-11-08 00:30');

        $period = LindaPeriod::parse($phrase);

        $this->assertSame($key, $period?->key, $phrase);
        $this->assertSame([$from, $to], [$period->from->toDateString(), $period->to->toDateString()]);
    }

    public function test_new_years_day_reaches_back_into_last_year_in_bisaya(): void
    {
        $this->atManila('2027-01-01 00:30');

        $this->assertSame(['2026-12-31', '2026-12-31'], self::dates(LindaPeriod::parse('gahapon')));
        $this->assertSame(['2026-12-01', '2026-12-31'], self::dates(LindaPeriod::parse('niaging bulan')));
        $this->assertSame(['2027-01-01', '2027-01-01'], self::dates(LindaPeriod::parse('karong tuiga')));
        $this->assertSame(['2026-12-28', '2027-01-01'], self::dates(LindaPeriod::parse('karong semanaha')));
    }

    public function test_month_names_and_their_spellings(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertSame(['2026-02-01', '2026-02-28'], self::dates(LindaPeriod::parse('Pebrero')));
        $this->assertSame(['2026-02-01', '2026-02-28'], self::dates(LindaPeriod::parse('Febrero')));
        $this->assertSame(['2026-06-01', '2026-06-30'], self::dates(LindaPeriod::parse('Hunyo')));
        $this->assertSame(['2026-07-01', '2026-07-31'], self::dates(LindaPeriod::parse('Hulyo')));
        $this->assertSame(['2026-09-01', '2026-09-30'], self::dates(LindaPeriod::parse('Septyembre')));
        $this->assertSame(['2026-11-01', '2026-11-08'], self::dates(LindaPeriod::parse('Nobyembre')));
        $this->assertSame(['2025-12-01', '2025-12-31'], self::dates(LindaPeriod::parse('Disyembre')));
        $this->assertSame(['2026-10-05', '2026-10-05'], self::dates(LindaPeriod::parse('niadtong Oktubre 5')));
        $this->assertSame(['2026-10-05', '2026-10-05'], self::dates(LindaPeriod::parse('sa Oktubre 5, 2026')));
    }

    public function test_bisaya_ranges(): void
    {
        $this->atManila('2026-11-08 12:00');

        $this->assertSame(['2026-10-01', '2026-10-10'], self::dates(LindaPeriod::parse('gikan sa Oktubre 1 hangtod sa 10')));
        $this->assertSame(['2026-10-01', '2026-10-10'], self::dates(LindaPeriod::parse('gikan Oktubre 1 hangtod Oktubre 10')));
        $this->assertSame(['2026-10-01', '2026-10-15'], self::dates(LindaPeriod::parse('taliwala sa Oktubre 1 ug 15')));
        $this->assertSame(['2026-11-01', '2026-11-05'], self::dates(LindaPeriod::parse('gikan sa 2026-11-01 hangtod sa 2026-11-05')));
        // A range that starts in the future is refused, in any language.
        $this->assertSame(LindaPeriod::REFUSED_FUTURE, LindaPeriod::refusal('gikan sa Disyembre 1 hangtod 5, 2030'));
        // "ug" joins a range only after "taliwala".
        $this->assertNull(LindaPeriod::parse('Oktubre 1 ug 15'));
    }

    public function test_extract_takes_sa_with_a_bisaya_period_so_it_is_not_read_as_a_branch(): void
    {
        $this->atManila('2026-11-08 12:00');

        $found = LindaPeriod::extract('pila ang nakolekta sa niaging semana');

        $this->assertSame('last_week', $found['period']?->key);
        $this->assertSame('pila ang nakolekta', $found['rest']);
    }

    /**
     * @return array{string, string}|null
     */
    private static function dates(?LindaPeriod $period): ?array
    {
        return $period === null ? null : [$period->from->toDateString(), $period->to->toDateString()];
    }
}
