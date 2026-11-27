<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaText;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * From the local browser check: "now" means today, overdue and active loans
 * take no period at all, and a branch name is repeated as it was typed.
 */
class LindaNowAndWordingTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->atManila('2026-11-16 09:00');
    }

    /** @return array<string, array{string}> */
    public static function nowWords(): array
    {
        return [
            'now' => ['now'],
            'right now' => ['right now'],
            'currently' => ['currently'],
            'as of now' => ['as of now'],
            'as of today' => ['as of today'],
            'ngayon' => ['ngayon'],
        ];
    }

    #[DataProvider('nowWords')]
    public function test_now_reads_as_today(string $words): void
    {
        $this->assertSame('today', LindaPeriod::parse($words)?->key, $words);
    }

    public function test_ngayon_inside_a_longer_phrase_keeps_its_own_period(): void
    {
        $this->assertSame('this_month', LindaPeriod::parse('ngayong buwan')?->key);
        $this->assertSame('this_week', LindaPeriod::parse('ngayong linggo')?->key);
        $this->assertSame('today', LindaPeriod::parse('ngayong araw')?->key);
    }

    public function test_collections_right_now_are_todays(): void
    {
        $match = LindaMatcher::match('collections right now');

        $this->assertSame(LindaIntent::CollectionSummary, $match?->intent);
        $this->assertSame('today', $match->period?->key);

        $this->assertSame('today', LindaMatcher::match('Magkano ang nakolekta ngayon?')?->period?->key);
    }

    public function test_overdue_and_active_loans_take_no_period(): void
    {
        $this->assertFalse(LindaIntent::OverdueAccounts->takesPeriod());
        $this->assertFalse(LindaIntent::LoanSummary->takesPeriod());
        $this->assertFalse(LindaIntent::UnsupportedQuestion->takesPeriod());
        $this->assertTrue(LindaIntent::CollectionSummary->takesPeriod());
        $this->assertTrue(LindaIntent::GeneralLendingSummary->takesPeriod());

        $this->assertNull(LindaMatcher::match('active loans right now')?->period);
        $this->assertNull(LindaMatcher::match('Which members currently have overdue payments?')?->period);
        $this->assertSame(LindaIntent::OverdueAccounts, LindaMatcher::match('overdue last month')?->intent);
    }

    public function test_a_name_is_repeated_as_it_was_typed(): void
    {
        $this->assertSame('Atlantis', LindaText::asTyped('atlantis', 'How much did we collect sa Atlantis?'));
        $this->assertSame('SAN  JOSE', LindaText::asTyped('san jose', 'overdue sa SAN  JOSE'));
        $this->assertSame('Riverside', LindaText::asTyped('riverside', 'what about yesterday?', 'how about sa Riverside?'));
        $this->assertSame('San Jose', LindaText::asTyped('san jose', 'what about yesterday?'));
        $this->assertSame('Atlantis', LindaText::asTyped('atlantis', 'sa atlantisville'));
    }
}
