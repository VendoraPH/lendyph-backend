<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Loan;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 11: extending an overdue loan sets a due date already in the past.
 *
 * LoanAdjustmentService::extensionFigures() takes `new_due_date` from
 * nextCycleDueDate($loan, $latestOpenDueDate): one anchored cycle after the
 * OLD due date. A one-month loan started 27 Apr 2026 was due 27 May; extended
 * on 2 Nov 2026 it becomes due 27 Jun 2026, already four months overdue, so
 * the new period is late (and penalised) the moment it is created.
 *
 * Pinned, whichever interest option decision 4 picks: the new due date is
 * after the day the extension is made, on the loan's anchor day, and the
 * preview quotes the date the extension records. The interest an extension
 * charges for the skipped months is decision 4 and is not pinned here.
 */
class OverdueExtensionDueDateTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-11-02 10:00:00');
    }

    private function overdueOneMonthLoan(): Loan
    {
        return $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ]);
    }

    public function test_the_extension_preview_quotes_a_due_date_after_today(): void
    {
        $loan = $this->overdueOneMonthLoan();

        $newDueDate = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")
            ->assertOk()
            ->json('data.new_due_date');

        $this->assertTrue(Carbon::parse($newDueDate)->isAfter(today()), "new due date {$newDueDate} is not after today");
        $this->assertSame(27, Carbon::parse($newDueDate)->day);
    }

    public function test_extending_an_overdue_loan_moves_its_due_date_past_today(): void
    {
        $loan = $this->overdueOneMonthLoan();

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")
            ->assertOk()
            ->json('data.new_due_date');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $loan->refresh()->load('amortizationSchedules');
        $maturity = $loan->maturity_date;
        $openRow = $loan->amortizationSchedules->firstWhere('status', 'pending');

        $this->assertTrue($maturity->isAfter(today()), "maturity {$maturity->toDateString()} is not after today");
        $this->assertNotNull($openRow);
        $this->assertSame($maturity->toDateString(), $openRow->due_date->toDateString());
        $this->assertSame($preview, $maturity->toDateString());
    }
}
