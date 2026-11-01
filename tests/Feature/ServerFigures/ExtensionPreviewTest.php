<?php

namespace Tests\Feature\ServerFigures;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans/{loan}/extension-preview?interest_option=pay|defer: the Extend
 * Loan dialog's figures, computed by the server with the arithmetic
 * POST /loans/{loan}/extend records, so the dialog can never quote a figure
 * the extension does not charge.
 *
 * The dialog used to compute `outstanding principal × rate / 100` in the
 * browser, ignoring the rate's frequency (1% a week was quoted as 1% a month)
 * and the penalty-first allocation of the 'pay' option.
 */
class ExtensionPreviewTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-11-02 10:00:00');
    }

    private function uponMaturityLoan(array $product = [], float $principal = 60000, ?string $startDate = '2026-04-27'): Loan
    {
        return $this->createReleasedLoan([
            'product' => array_merge([
                'interest_method' => 'upon_maturity',
                'term' => 1,
                'frequency' => 'monthly',
                'interest_rate' => 3.0,
            ], $product),
            'principal_amount' => $principal,
            'start_date' => $startDate,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function writeCounts(Loan $loan): array
    {
        return [
            'adjustments' => LoanAdjustment::where('loan_id', $loan->id)->count(),
            'repayments' => Repayment::where('loan_id', $loan->id)->count(),
            'schedules' => AmortizationSchedule::where('loan_id', $loan->id)->count(),
            'ledger' => LoanLedgerEntry::where('loan_id', $loan->id)->count(),
        ];
    }

    public function test_it_quotes_a_month_at_the_converted_rate_not_the_raw_rate(): void
    {
        // 70,000 at 1% a week: one 30-day month is 70,000 × 1% × 30/7 = 3,000,
        // not the 700 the browser's `principal × rate / 100` showed.
        $loan = $this->uponMaturityLoan(['interest_rate' => 1.0, 'interest_rate_frequency' => 'weekly'], 70000);

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")
            ->assertOk()
            ->json('data');

        $this->assertEquals(70000, $preview['carry_principal']);
        $this->assertEquals(3000, $preview['carry_interest']);
        $this->assertEquals(3000, $preview['fresh_interest']);
        $this->assertEquals(6000, $preview['new_interest_due']);
        $this->assertEquals(76000, $preview['new_total_due']);
        $this->assertEquals(['amount' => 0, 'penalty_part' => 0, 'interest_part' => 0], $preview['collect_now']);
    }

    public function test_the_defer_preview_equals_what_the_extension_then_records(): void
    {
        $loan = $this->uponMaturityLoan(['interest_rate' => 1.0, 'interest_rate_frequency' => 'weekly'], 70000);

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")
            ->assertOk()
            ->json('data');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $recorded = LoanAdjustment::where('loan_id', $loan->id)->firstOrFail()->new_values;
        $newPeriod = $loan->fresh()->amortizationSchedules()->reorder('period_number', 'desc')->firstOrFail();

        $this->assertEquals($recorded['carry_principal'], $preview['carry_principal']);
        $this->assertEquals($recorded['carry_interest'], $preview['carry_interest']);
        $this->assertEquals($recorded['fresh_interest'], $preview['fresh_interest']);
        $this->assertSame($recorded['new_due_date'], $preview['new_due_date']);
        $this->assertEquals((float) $newPeriod->interest_due, $preview['new_interest_due']);
        $this->assertEquals((float) $newPeriod->total_due, $preview['new_total_due']);
    }

    public function test_the_pay_preview_on_an_overdue_loan_takes_the_penalty_first_exactly_as_the_extension_does(): void
    {
        // Due 2026-05-27 and still unpaid on 2026-11-02, so a penalty is owed.
        // The 'pay' collection is the outstanding interest; allocation takes
        // the penalty out of it first and the rest of the interest carries.
        $loan = $this->uponMaturityLoan();

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")
            ->assertOk()
            ->json('data');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $repayment = Repayment::where('loan_id', $loan->id)->firstOrFail();
        $recorded = LoanAdjustment::where('loan_id', $loan->id)->firstOrFail()->new_values;
        $newPeriod = $loan->fresh()->amortizationSchedules()->reorder('period_number', 'desc')->firstOrFail();

        $this->assertGreaterThan(0, $preview['collect_now']['penalty_part'], 'an overdue loan owes a penalty');
        $this->assertEquals((float) $repayment->amount_paid, $preview['collect_now']['amount']);
        $this->assertEquals((float) $repayment->penalty_applied, $preview['collect_now']['penalty_part']);
        $this->assertEquals((float) $repayment->interest_applied, $preview['collect_now']['interest_part']);
        $this->assertEquals($recorded['carry_principal'], $preview['carry_principal']);
        $this->assertEquals($recorded['carry_interest'], $preview['carry_interest']);
        $this->assertEquals($recorded['fresh_interest'], $preview['fresh_interest']);
        $this->assertEquals((float) $newPeriod->interest_due, $preview['new_interest_due']);
        $this->assertEquals((float) $newPeriod->total_due, $preview['new_total_due']);
        $this->assertSame($recorded['new_due_date'], $preview['new_due_date']);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $loan = $this->uponMaturityLoan();
        $before = $this->writeCounts($loan);
        $penaltyBefore = AmortizationSchedule::where('loan_id', $loan->id)->sum('penalty_amount');

        foreach (['pay', 'defer'] as $option) {
            $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option={$option}")->assertOk();
        }

        $this->assertSame($before, $this->writeCounts($loan));
        $this->assertEquals($penaltyBefore, AmortizationSchedule::where('loan_id', $loan->id)->sum('penalty_amount'));
        $this->assertSame('2026-05-27', $loan->fresh()->maturity_date->toDateString());
    }

    public function test_the_preview_states_the_interest_option(): void
    {
        $loan = $this->uponMaturityLoan();

        $this->getJson("/api/loans/{$loan->id}/extension-preview")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('interest_option');
    }

    public function test_a_user_without_loans_extend_gets_403(): void
    {
        $loan = $this->uponMaturityLoan();

        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);

        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertForbidden();
    }
}
