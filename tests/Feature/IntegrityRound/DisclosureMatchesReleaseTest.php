<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 13: the disclosure signed before release and the release dialog quote
 * different net proceeds.
 *
 * DisclosureService reads the stored `deductions` and `net_proceeds`, which
 * hold only what the application stated. The release adds every catalog fee
 * that applies (LoanReleaseFeeService::applyOnRelease()), and
 * GET /loans/{id}/release-preview quotes that (LoanReleaseFeeService::preview()).
 * So for ₱10,000 with a 2% catalog fee the borrower signs a statement saying
 * ₱10,000.00 and is handed ₱9,800.00, and the ₱200 fee (a finance charge) is
 * missing from the finance charges R.A. 3765 requires disclosed.
 *
 * Pinned (plan decision 6, option a): before release the disclosure includes
 * the catalog fees the release would add, by the same code, so its net
 * proceeds equal the release preview's `net_proceeds`. Insurance is chosen in
 * the release dialog, so before release it is not on the statement; after
 * release the statement shows exactly what the release withheld and left
 * owing.
 */
class DisclosureMatchesReleaseTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function approvedLoanWithCatalogFee(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'processing_fee' => 0, 'service_fee' => 0, 'notarial_fee' => 0,
        ]);
        Fee::create(['name' => 'Release Fee', 'type' => 'percentage', 'value' => 2.0, 'applicable_product_ids' => [$product->id]]);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    public function test_the_pre_release_disclosure_nets_the_catalog_fees_the_release_will_charge(): void
    {
        $loan = $this->approvedLoanWithCatalogFee();

        $release = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');
        $this->assertEquals(9800, (float) $release['net_proceeds']);

        $disclosure = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $this->assertEquals(9800, $disclosure['proceeds']['net_proceeds']);
        $this->assertEquals(9800, $disclosure['totals']['net_proceeds']);
        $this->assertEquals(9800, $disclosure['deductions']['net_proceeds']);
        $this->assertContains(['name' => 'Release Fee', 'amount' => 200, 'provisional' => true, 'provisional_reason' => 'release_fee'], $disclosure['finance_charges']['items']);
    }

    public function test_after_a_release_with_partial_insurance_the_disclosure_matches_the_release(): void
    {
        $loan = $this->approvedLoanWithCatalogFee();
        $insurance = [
            'insurance_premium_percentage' => 1.0,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 60,
        ];

        $before = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');
        $preview = $this->getJson("/api/loans/{$loan->id}/release-preview?".http_build_query($insurance))->assertOk()->json('data');

        // Before release: the fee is on the statement, the insurance (not chosen yet) is not.
        $this->assertEquals((float) $preview['net_proceeds'], $before['proceeds']['net_proceeds']);
        $this->assertEquals(9740, (float) $preview['net_proceeds_after_insurance']);

        $this->patchJson("/api/loans/{$loan->id}/release", $insurance)->assertOk();

        $after = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');
        $this->assertEquals(9740, $after['proceeds']['net_proceeds']);
        $this->assertContains(
            ['name' => 'Insurance Premium', 'amount' => 100, 'deducted' => 60, 'owed' => 40],
            $after['non_finance_charges']['items'],
        );
    }
}
