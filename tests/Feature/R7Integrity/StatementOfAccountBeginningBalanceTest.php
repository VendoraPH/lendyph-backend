<?php

namespace Tests\Feature\R7Integrity;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Statement of Account schedule rows carry `beginning_balance` (round r7,
 * decision 3), built the way AmortizationScheduleResource builds it: the
 * period's remaining balance plus its principal due, to the centavo. The
 * statement's Beginning Balance column showed a dash without it.
 */
class StatementOfAccountBeginningBalanceTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_each_row_s_beginning_balance_equals_the_schedule_resource_s(): void
    {
        // ₱10,000.00 over 3 months: principal ₱3,333.33 / ₱3,333.33 / ₱3,333.34,
        // so the rounding of each row is exercised.
        $loan = $this->createReleasedLoan(['principal_amount' => 10000, 'product' => ['term' => 3]]);

        $schedule = collect($this->getJson("/api/loans/{$loan->id}/amortization-schedule")->assertOk()->json('data'))
            ->keyBy('period_number');
        $soaRows = collect($this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data.amortization_schedule'))
            ->keyBy('period_number');

        $this->assertCount(3, $soaRows);

        foreach ($soaRows as $period => $row) {
            $this->assertArrayHasKey('beginning_balance', $row, "Period {$period} carries a beginning balance.");
            $this->assertEquals($schedule[$period]['beginning_balance'], $row['beginning_balance'], "Period {$period}.");
        }

        $this->assertEquals(10000, $soaRows[1]['beginning_balance'], 'The first period opens at the principal (₱10,000.00).');
    }
}
