<?php

namespace Tests\Feature\Phase6\ExecutiveFigures;

use App\Services\RepaymentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-A: Portfolio by Product sends each product's share of the
 * outstanding principal (`outstanding_share`), and an `other` row for the
 * products past the four largest, so the dashboard's product donut neither
 * adds up nor ranks anything itself. Each loan has its own product.
 */
class ProductOutstandingShareTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_each_product_s_share_of_the_principal_and_one_row_for_the_rest(): void
    {
        $loans = [];
        foreach ([10000, 60000, 20000, 50000, 30000, 40000] as $principal) {
            $loans[$principal] = $this->createReleasedLoan(['principal_amount' => $principal]);
        }
        $paidOff = $this->createReleasedLoan(['principal_amount' => 12000]);
        app(RepaymentService::class)->processRepayment($paidOff->fresh(), 14160, now()->toDateString(), $this->admin);

        $report = $this->getJson('/api/reports/portfolio-by-product')->assertOk()->json('data');
        $rows = collect($report['products'])->keyBy('product_id');

        // ₱210,000.00 of principal; ₱60,000.00 is 28.57%.
        $this->assertEquals(28.57, $rows[$loans[60000]->loan_product_id]['outstanding_share']);
        $this->assertEquals(4.76, $rows[$loans[10000]->loan_product_id]['outstanding_share']);
        $this->assertEquals(0, $rows[$paidOff->loan_product_id]['outstanding_share']);

        $this->assertSame(4, $report['outstanding_share_named']);
        $this->assertSame([
            $loans[60000]->loan_product_id,
            $loans[50000]->loan_product_id,
            $loans[40000]->loan_product_id,
            $loans[30000]->loan_product_id,
        ], $report['named_product_ids'], 'Largest first; the browser ranks nothing.');
        $this->assertEquals([
            'product_count' => 2,
            'product_ids' => [$loans[20000]->loan_product_id, $loans[10000]->loan_product_id],
            'loan_count' => 2,
            'outstanding_principal' => 30000,
            'total_outstanding' => 35400,
            'outstanding_share' => 14.29,
        ], $report['other']);
        $this->assertEquals(210000, $report['totals']['outstanding_principal']);
    }

    public function test_four_products_or_fewer_leave_the_other_row_empty(): void
    {
        $this->createReleasedLoan();

        $report = $this->getJson('/api/reports/portfolio-by-product')->assertOk()->json('data');

        $this->assertEquals(100, $report['products'][0]['outstanding_share']);
        $this->assertSame([$report['products'][0]['product_id']], $report['named_product_ids']);
        $this->assertEquals([
            'product_count' => 0,
            'product_ids' => [],
            'loan_count' => 0,
            'outstanding_principal' => 0,
            'total_outstanding' => 0,
            'outstanding_share' => 0,
        ], $report['other']);
    }

    public function test_an_empty_book_has_no_share(): void
    {
        $report = $this->getJson('/api/reports/portfolio-by-product')->assertOk()->json('data');

        $this->assertSame([], $report['products']);
        $this->assertSame([], $report['named_product_ids']);
        $this->assertNull($report['other']['outstanding_share']);
    }
}
