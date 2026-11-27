<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaPeriod;

/**
 * Why a period is refused, so a reply can say so: not started yet, over 10
 * years, or impossible.
 */
class LindaPeriodRefusalTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->atManila('2026-11-08 00:30');
    }

    public function test_each_refusal_has_its_reason(): void
    {
        $this->assertSame(LindaPeriod::REFUSED_FUTURE, LindaPeriod::refusal('December 1 to 5, 2026'));
        $this->assertSame(LindaPeriod::REFUSED_FUTURE, LindaPeriod::refusal('December 2026'));
        $this->assertSame(LindaPeriod::REFUSED_TOO_LONG, LindaPeriod::refusal('January 1, 2010 to November 8, 2026'));
        $this->assertSame(LindaPeriod::REFUSED_INVALID, LindaPeriod::refusal('February 30, 2026'));
        $this->assertSame(LindaPeriod::REFUSED_INVALID, LindaPeriod::refusal('November 5 to 1'));
    }

    public function test_an_accepted_period_or_no_period_has_no_refusal(): void
    {
        $this->assertNull(LindaPeriod::refusal('last month'));
        $this->assertNull(LindaPeriod::refusal('November 1 to 5'));
        $this->assertNull(LindaPeriod::refusal('the last 45 days'));
        $this->assertNull(LindaPeriod::refusal('collections December 1 to 5, 2026'));
    }

    public function test_extract_carries_the_reason(): void
    {
        $this->assertSame(
            ['period' => null, 'rest' => 'collections', 'refused' => true, 'reason' => LindaPeriod::REFUSED_FUTURE],
            LindaPeriod::extract('collections december 1 to 5 2026'),
        );
        $this->assertNull(LindaPeriod::extract('collections last month')['reason']);
    }
}
