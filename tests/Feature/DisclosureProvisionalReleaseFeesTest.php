<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Before release the disclosure adds the catalog fees the release will charge,
 * through the same LoanReleaseFeeService::itemsFor() the release preview uses,
 * each sorted like a stored deduction and marked `provisional`. Once released
 * the fees are stored deductions and the statement shows exactly the same
 * figures, none of them provisional.
 */
class DisclosureProvisionalReleaseFeesTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * ₱10,000 for 6 months at 3% flat, a ₱100 processing fee stated on the
     * application, and two catalog fees the release adds: a 2% coop fee
     * (₱200, a finance charge) and a ₱50 documentary stamp tax (a tax, so a
     * non-finance charge).
     */
    private function approvedLoan(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'processing_fee' => 0, 'service_fee' => 0, 'notarial_fee' => 0,
        ]);
        Fee::create(['name' => 'Release Fee', 'type' => 'percentage', 'value' => 2.0, 'applicable_product_ids' => [$product->id]]);
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 50, 'applicable_product_ids' => [$product->id]]);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
            'deductions' => [['name' => 'Processing Fee', 'amount' => 100, 'type' => 'fixed', 'original_value' => 100]],
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    public function test_before_release_each_catalog_fee_is_sorted_and_marked_provisional(): void
    {
        $loan = $this->approvedLoan();

        $data = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $this->assertSame([
            ['name' => 'Processing Fee', 'amount' => 100],
            ['name' => 'Release Fee', 'amount' => 200, 'provisional' => true],
        ], $data['finance_charges']['items']);
        $this->assertSame([
            ['name' => 'Documentary Stamp Tax', 'amount' => 50, 'deducted' => 50, 'owed' => 0, 'provisional' => true],
        ], $data['non_finance_charges']['items']);

        $this->assertEquals(9650, $data['proceeds']['net_proceeds']);
        $this->assertEquals(300, $data['proceeds']['finance_charges_deducted']);
        $this->assertEquals(50, $data['proceeds']['non_finance_charges_deducted']);
        $this->assertEquals(350, $data['totals']['total_deductions']);
        $this->assertEquals(350, $data['deductions']['total_deductions']);
        $this->assertEquals(0, $data['totals']['unitemised_deductions']);

        $this->assertSame(['Processing Fee', 'Release Fee', 'Documentary Stamp Tax'], array_column($data['deductions']['items'], 'name'));
        $this->assertSame([false, true, true], array_map(fn (array $item) => ($item['provisional'] ?? false) === true, $data['deductions']['items']));

        $release = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');
        $this->assertEquals((float) $release['net_proceeds'], $data['proceeds']['net_proceeds']);
        $this->assertEquals((float) $release['total_deductions'], $data['totals']['total_deductions']);
    }

    public function test_after_release_the_statement_shows_the_same_figures_none_provisional(): void
    {
        $loan = $this->approvedLoan();

        $before = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
        $after = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $this->assertEquals(9650, (float) $loan->fresh()->net_proceeds);
        $this->assertSame($before['proceeds'], $after['proceeds']);
        $this->assertSame($before['totals']['net_proceeds'], $after['totals']['net_proceeds']);
        $this->assertSame($before['totals']['total_deductions'], $after['totals']['total_deductions']);
        // The amount financed (principal less the finance charges deducted)
        // was the same before release, so the effective rate is too.
        $this->assertSame($before['rates'], $after['rates']);

        $this->assertSame([
            ['name' => 'Processing Fee', 'amount' => 100],
            ['name' => 'Release Fee', 'amount' => 200],
        ], $after['finance_charges']['items']);
        $this->assertSame([
            ['name' => 'Documentary Stamp Tax', 'amount' => 50, 'deducted' => 50, 'owed' => 0],
        ], $after['non_finance_charges']['items']);
        $this->assertSame([], array_filter(array_column($after['deductions']['items'], 'provisional')));
    }

    public function test_a_loan_with_no_catalog_fee_is_unchanged_before_release(): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'processing_fee' => 0, 'service_fee' => 0, 'notarial_fee' => 0,
        ]);
        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
            'deductions' => [],
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        $data = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $this->assertSame([], $data['finance_charges']['items']);
        $this->assertSame([], $data['deductions']['items']);
        $this->assertEquals(10000, $data['proceeds']['net_proceeds']);
        $this->assertEquals(0, $data['totals']['total_deductions']);
    }
}
