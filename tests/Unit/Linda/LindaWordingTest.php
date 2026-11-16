<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;

class LindaWordingTest extends LindaTestCase
{
    public function test_each_period_refusal_reads_its_own_reason(): void
    {
        $this->assertSame(
            'That period is in the future, so there are no figures for it yet. Please ask about today or an earlier date.',
            LindaWording::periodRefused(LindaPeriod::REFUSED_FUTURE),
        );
        $this->assertSame(
            'That period is longer than 10 years. Please ask about a shorter period.',
            LindaWording::periodRefused(LindaPeriod::REFUSED_TOO_LONG),
        );
        $this->assertSame(
            'That period doesn\'t work: its start comes after its end. Try something like "October 1 to 15".',
            LindaWording::periodRefused(LindaPeriod::REFUSED_INVALID),
        );
    }

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
