<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class UponMaturityFrequencyReleaseTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function approvedLoan(string $interestMethod, string $frequency, int $term = 3, float $principal = 10000): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => $interestMethod,
            'term' => $term,
            'frequency' => $frequency,
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loanService = app(LoanService::class);

        $loan = $loanService->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $loanService->submitForReview($loan);
        $loanService->approve($loan, $this->admin, 'OK');

        return $loan->fresh();
    }

    public function test_upon_maturity_frequency_with_straight_interest_generates_single_schedule_row(): void
    {
        $loan = $this->approvedLoan('straight', 'upon_maturity', term: 3, principal: 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $loan->refresh();
        $schedules = AmortizationSchedule::where('loan_id', $loan->id)->get();

        $this->assertCount(1, $schedules);

        $row = $schedules->first();
        $this->assertEquals($loan->maturity_date->toDateString(), $row->due_date->toDateString());
        $this->assertEquals(10000.0, (float) $row->principal_due);
        // Total interest = 10000 × 3% × 3 months = 900
        $this->assertEquals(900.0, (float) $row->interest_due);
        $this->assertEquals(10900.0, (float) $row->total_due);
    }

    public function test_upon_maturity_frequency_with_diminishing_interest_generates_single_schedule_row(): void
    {
        $loan = $this->approvedLoan('diminishing', 'upon_maturity', term: 3, principal: 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $schedules = AmortizationSchedule::where('loan_id', $loan->id)->get();

        $this->assertCount(1, $schedules);
        $this->assertEquals(
            $loan->fresh()->maturity_date->toDateString(),
            $schedules->first()->due_date->toDateString(),
        );
    }

    public function test_upon_maturity_frequency_with_upon_maturity_interest_generates_single_schedule_row(): void
    {
        $loan = $this->approvedLoan('upon_maturity', 'upon_maturity', term: 3, principal: 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $schedules = AmortizationSchedule::where('loan_id', $loan->id)->get();

        $this->assertCount(1, $schedules);
        $this->assertEquals(
            $loan->fresh()->maturity_date->toDateString(),
            $schedules->first()->due_date->toDateString(),
        );
    }

    public function test_upon_maturity_loan_next_due_date_equals_maturity_date(): void
    {
        $loan = $this->approvedLoan('straight', 'upon_maturity', term: 3, principal: 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $response = $this->getJson("/api/loans/{$loan->id}");
        $response->assertOk();

        $this->assertEquals(
            $response->json('data.maturity_date'),
            $response->json('data.next_due_date'),
        );
    }

    public function test_monthly_frequency_unaffected_by_upon_maturity_short_circuit(): void
    {
        $loan = $this->approvedLoan('straight', 'monthly', term: 6, principal: 60000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $schedules = AmortizationSchedule::where('loan_id', $loan->id)->get();

        $this->assertCount(6, $schedules);
    }
}
