<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/reports/income?loan_id= answers for that one loan: its interest
 * and penalty collected, its own processing fee, and their total. Before, the
 * filter validated and was then dropped, so a per-loan Income view showed
 * the whole cooperative's income.
 */
class IncomeReportLoanFilterTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loanA;

    private Loan $loanB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $product = LoanProduct::factory()->create(['processing_fee' => 2.0]);

        $this->loanA = $this->releasedLoan($product, '2026-01-05 09:00:00', 60000);
        $this->loanB = $this->releasedLoan($product, '2026-01-06 09:00:00', 30000);

        $this->repayment($this->loanA, '2026-02-01', 1800.00, 57.99);
        $this->repayment($this->loanA, '2026-03-01', 1800.00, 0);
        $this->repayment($this->loanA, '2026-03-15', 900.00, 10.00, 'voided');
        $this->repayment($this->loanB, '2026-02-15', 900.25, 12.34);
    }

    public function test_every_income_figure_is_for_the_requested_loan_only(): void
    {
        $this->getJson("/api/reports/income?loan_id={$this->loanA->id}")
            ->assertOk()
            ->assertJsonPath('data.interest_income', 3600)
            ->assertJsonPath('data.penalty_income', 57.99)
            ->assertJsonPath('data.processing_fees', 1200)
            ->assertJsonPath('data.total', 4857.99);

        $this->getJson("/api/reports/income?loan_id={$this->loanB->id}")
            ->assertOk()
            ->assertJsonPath('data.interest_income', 900.25)
            ->assertJsonPath('data.penalty_income', 12.34)
            ->assertJsonPath('data.processing_fees', 600)
            ->assertJsonPath('data.total', 1512.59);
    }

    public function test_the_loan_filter_combines_with_the_period(): void
    {
        // Released on 2026-01-05, so its fee falls outside a February period;
        // only its February repayment is income in it.
        $this->getJson("/api/reports/income?loan_id={$this->loanA->id}&date_from=2026-02-01&date_to=2026-02-28")
            ->assertOk()
            ->assertJsonPath('data.interest_income', 1800)
            ->assertJsonPath('data.penalty_income', 57.99)
            ->assertJsonPath('data.processing_fees', 0)
            ->assertJsonPath('data.total', 1857.99);
    }

    public function test_income_by_loan_honours_the_same_loan_filter(): void
    {
        $byLoan = $this->getJson("/api/reports/income/by-loan?loan_id={$this->loanA->id}")->assertOk();
        $income = $this->getJson("/api/reports/income?loan_id={$this->loanA->id}")->assertOk();

        $this->assertSame([$this->loanA->id], array_column($byLoan->json('data'), 'loan_id'));
        $this->assertEquals($income->json('data.interest_income'), $byLoan->json('totals.interest_income'));
        $this->assertEquals($income->json('data.penalty_income'), $byLoan->json('totals.penalty_income'));
        $this->assertSame(2, $byLoan->json('totals.payments'));
    }

    public function test_without_a_loan_filter_every_loan_counts(): void
    {
        $this->getJson('/api/reports/income')
            ->assertOk()
            ->assertJsonPath('data.interest_income', 4500.25)
            ->assertJsonPath('data.penalty_income', 70.33)
            ->assertJsonPath('data.processing_fees', 1800)
            ->assertJsonPath('data.total', 6370.58);
    }

    /**
     * The product's processing fee recorded as deducted, the way
     * LoanService::createLoan() writes it; the Income report reads it there.
     */
    private function releasedLoan(LoanProduct $product, string $releasedAt, float $principal): Loan
    {
        $fee = round($principal * (float) $product->processing_fee / 100, 2);

        return Loan::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'deductions' => [[
                'name' => 'Processing Fee',
                'amount' => $fee,
                'type' => 'percentage',
                'original_value' => (float) $product->processing_fee,
            ]],
            'total_deductions' => $fee,
            'net_proceeds' => $principal - $fee,
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }

    private function repayment(Loan $loan, string $date, float $interest, float $penalty, string $status = 'posted'): Repayment
    {
        return Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $date,
            'amount_paid' => $interest + $penalty,
            'principal_applied' => 0,
            'interest_applied' => $interest,
            'penalty_applied' => $penalty,
            'status' => $status,
            'received_by' => $this->admin->id,
        ]);
    }
}
