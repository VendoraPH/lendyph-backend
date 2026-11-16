<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;

class LindaWordingTest extends LindaTestCase
{
    public function test_money_is_pesos_with_two_decimals(): void
    {
        $this->assertSame('₱0.00', LindaWording::money(0));
        $this->assertSame('₱1,500.00', LindaWording::money(1500));
        $this->assertSame('₱10.50', LindaWording::money(10.5));
        $this->assertSame('₱1,234,567.89', LindaWording::money('1234567.89'));
        $this->assertSame('₱0.01', LindaWording::money(0.005));
    }

    public function test_counts_take_their_plural(): void
    {
        $this->assertSame('1 payment', LindaWording::count(1, 'payment'));
        $this->assertSame('0 payments', LindaWording::count(0, 'payment'));
        $this->assertSame('1,204 payments', LindaWording::count(1204, 'payment'));
        $this->assertSame('2 members', LindaWording::count(2, 'member'));
        $this->assertSame('3 branches', LindaWording::count(3, 'branch', 'branches'));
    }

    public function test_a_period_reads_as_its_manila_dates(): void
    {
        // 00:30 on Nov 16 in Manila is still Nov 15 in UTC.
        $this->atManila('2026-11-16 00:30');

        $this->assertSame('from November 1 to 16, 2026', LindaWording::during(LindaPeriod::named('this_month')));
        $this->assertSame('on November 16, 2026', LindaWording::during(LindaPeriod::named('today')));
        $this->assertSame('November 16, 2026', LindaWording::today());
    }

    public function test_the_suggested_questions_are_the_panels_word_for_word(): void
    {
        $this->assertSame([
            'How much have we collected this month?',
            'Which members currently have overdue payments?',
            'How many active loans do we have?',
            'How much have we disbursed this month?',
            'Give me a summary of our lending operation this month.',
        ], LindaWording::SUGGESTED_QUESTIONS);
    }
}
