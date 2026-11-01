<?php

namespace Tests\Feature\ServerFigures;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans/{id}/amortization-preview serves a loan before release (the
 * Release dialog's schedule, the Schedule tab and the Overview's Total Payable
 * and Interest Amount). Each row carries its share capital build-up and total
 * payment, and a `totals` sibling of `data` adds the columns up, built by the
 * same code as POST /loans/preview's `amortization.totals`, so the browser
 * never sums a column itself.
 */
class AmortizationPreviewScbTest extends TestCase
{
    use SetupLendyPH;

    private const PRODUCT = [
        'interest_rate' => 3, 'interest_method' => 'straight', 'frequency' => 'monthly',
        'term' => 3, 'max_term' => 3, 'processing_fee' => 0, 'service_fee' => 0,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function approvedLoanWithScb(LoanProduct $product, float $scb): Loan
    {
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => '2026-10-03',
            'scb_amount' => $scb,
            'deductions' => [],
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function formPreview(LoanProduct $product, float $scb): array
    {
        return $this->postJson('/api/loans/preview', [
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'term' => 3,
            'frequency' => 'monthly',
            'start_date' => '2026-10-03',
            'scb_amount' => $scb,
        ])->assertOk()->json('data.amortization');
    }

    public function test_each_row_and_the_totals_equal_the_loan_form_preview(): void
    {
        $product = LoanProduct::factory()->create(self::PRODUCT);
        $loan = $this->approvedLoanWithScb($product, 100.50);
        $this->assertSame('approved', $loan->status);

        $form = $this->formPreview($product, 100.50);
        $response = $this->getJson("/api/loans/{$loan->id}/amortization-preview")->assertOk();
        $rows = $response->json('data');

        $this->assertCount(3, $rows);

        foreach ($rows as $i => $row) {
            $this->assertEquals(100.5, $row['share_capital_build_up']);
            $this->assertEquals(round($row['total_due'] + 100.5, 2), $row['total_payment']);

            foreach (['principal_due', 'interest_due', 'total_due', 'share_capital_build_up', 'total_payment'] as $key) {
                $this->assertEquals($form['rows'][$i][$key], $row[$key], "row {$i} {$key}");
            }
        }

        $totals = $response->json('totals');

        foreach (['principal_due', 'interest_due', 'share_capital_build_up', 'total_payment'] as $key) {
            $this->assertEquals($form['totals'][$key], $totals[$key], "totals {$key}");
        }

        $this->assertEquals(10000, $totals['principal_due']);
        $this->assertEquals(900, $totals['interest_due']);
        $this->assertEquals(10900, $totals['total_due']);
        $this->assertEquals(301.5, $totals['share_capital_build_up']);
        $this->assertEquals(11201.5, $totals['total_payment']);
    }

    public function test_a_loan_without_build_up_shows_zero_and_a_total_payment_equal_to_the_amount_due(): void
    {
        $product = LoanProduct::factory()->create(self::PRODUCT);
        $loan = $this->approvedLoanWithScb($product, 0);

        $response = $this->getJson("/api/loans/{$loan->id}/amortization-preview")->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertArrayHasKey('share_capital_build_up', $row);
            $this->assertEquals(0, $row['share_capital_build_up']);
            $this->assertEquals($row['total_due'], $row['total_payment']);
        }

        $this->assertEquals(0, $response->json('totals.share_capital_build_up'));
        $this->assertEquals($response->json('totals.total_due'), $response->json('totals.total_payment'));
        $this->assertEquals($this->formPreview($product, 0)['totals']['total_payment'], $response->json('totals.total_payment'));
    }
}
