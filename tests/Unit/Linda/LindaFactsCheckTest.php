<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checks on an open answer (security review of #327): only the pack's
 * figures count as numbers. Dates, titles and labels with digits ("PAR 30",
 * "Maturing in 30 Days", "November 19, 2026") may be copied whole, but their
 * digits never count as figures on their own. Spelled-out numbers, English,
 * Tagalog and Bisaya, always fall back.
 *
 * No database and no application boot.
 */
class LindaFactsCheckTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function facts(): array
    {
        return [
            'as_of' => 'November 19, 2026',
            'branches' => 'all branches',
            'period' => 'November 1 to 19, 2026',
            'topics' => [
                ['topic' => 'portfolio_summary', 'title' => 'As of today, November 19, 2026', 'figures' => [
                    ['label' => 'PAR 30', 'value' => '100%'],
                    ['label' => 'Active Loans', 'value' => '2'],
                    ['label' => 'Snapshot Date', 'value' => 'November 18, 2026'],
                ]],
                ['topic' => 'savings_summary', 'title' => 'As of today, November 19, 2026', 'figures' => [
                    ['label' => 'Maturing in 30 Days', 'value' => '1'],
                    ['label' => 'Total Deposits', 'value' => '₱50,258.87'],
                ]],
                ['topic' => 'collection_summary', 'title' => 'From November 1 to 19, 2026', 'figures' => [
                    ['label' => 'Total Received', 'value' => '₱2,500.00'],
                ]],
            ],
        ];
    }

    /** @return array<string, array{string}> */
    public static function fitting(): array
    {
        return [
            'figures with a label holding digits' => ['PAR 30 is 100% across 2 active loans.'],
            'a label copied in another case' => ['1 time deposit is maturing in 30 days.'],
            'the as-of date and a money figure' => ['As of November 19, 2026, total deposits are ₱50,258.87.'],
            'the period, copied whole' => ['From November 1 to 19, 2026, we received ₱2,500.00.'],
            'a date figure, copied whole' => ['The last snapshot was taken on November 18, 2026.'],
            'formatting aside' => ['We received 2500 pesos.'],
        ];
    }

    #[DataProvider('fitting')]
    public function test_figures_and_whole_dates_and_labels_fit(string $answer): void
    {
        $this->assertTrue(LindaFacts::fits($answer, self::facts()), $answer);
    }

    /** @return array<string, array{string}> */
    public static function notFitting(): array
    {
        return [
            '30 from "PAR 30"' => ['30 members are overdue.'],
            '30 from a label, as a rate' => ['PAR 30 is 30%.'],
            '19 from the dates' => ['Collections are 19% of releases.'],
            '2026 from the dates' => ['In 2026 we received ₱2,500.00.'],
            '18 from a date figure' => ['18 loans were released.'],
            'a date cut short' => ['On November 19 we had 2 active loans.'],
            'a number in no figure' => ['We received ₱2,600.00.'],
            // Spelled out, in English, Tagalog and Bisaya.
            'one' => ['One member is overdue.'],
            'zero' => ['Zero loans were released.'],
            'a third' => ['About a third of the portfolio is at risk.'],
            'a quarter' => ['A quarter of deposits mature soon.'],
            'half' => ['Half of the loans are active.'],
            'dalawa' => ['Dalawa ang aktibong loans.'],
            'tatlo' => ['Tatlo ang miyembro.'],
            'sampu' => ['Sampu ang nagbayad.'],
            'duha' => ['Duha ka loans ang aktibo.'],
            'tulo' => ['Tulo ka miyembro.'],
            'napulo' => ['Napulo ka bayad.'],
            'daan' => ['Usa ka daan ka libo.'],
            'libo' => ['Duha ka libo ang nadawat.'],
        ];
    }

    #[DataProvider('notFitting')]
    public function test_digits_from_dates_labels_and_spelled_numbers_fall_back(string $answer): void
    {
        $this->assertFalse(LindaFacts::fits($answer, self::facts()), $answer);
    }
}
