<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\DisclosureService;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Before release the disclosure adds the catalog fees the release will charge,
 * through the same LoanReleaseFeeService::itemsFor() the release preview uses,
 * each sorted like a stored deduction and marked `provisional` (reason
 * "release_fee"), for open applications only. Fees above the principal answer
 * the release preview's own 422. Once released the fees are stored deductions
 * and the statement shows exactly the same figures, none of them provisional.
 */
class DisclosureProvisionalReleaseFeesTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

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
    private function approvedLoan(?LoanProduct $product = null): Loan
    {
        $loans = app(LoanService::class);
        $loan = $this->draftLoan($product);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /** A loan on `$product`, or on a new product carrying the two catalog fees. */
    private function draftLoan(?LoanProduct $product = null): Loan
    {
        return $this->loanOn($product ?? $this->productWithFees(), 10000, [['name' => 'Processing Fee', 'amount' => 100, 'type' => 'fixed', 'original_value' => 100]]);
    }

    private function productWithFees(): LoanProduct
    {
        $product = $this->product();
        Fee::create(['name' => 'Release Fee', 'type' => 'percentage', 'value' => 2.0, 'applicable_product_ids' => [$product->id]]);
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 50, 'applicable_product_ids' => [$product->id]]);

        return $product;
    }

    private function product(): LoanProduct
    {
        return LoanProduct::factory()->create([
            'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'processing_fee' => 0, 'service_fee' => 0, 'notarial_fee' => 0, 'min_amount' => 1000,
        ]);
    }

    /** @param  list<array<string, mixed>>  $deductions */
    private function loanOn(LoanProduct $product, float $principal, array $deductions = []): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'deductions' => $deductions,
        ], $this->admin);
    }

    /** @return array<string, mixed> */
    private function disclosureOf(Loan $loan): array
    {
        return app(DisclosureService::class)->generateDisclosure($loan->fresh());
    }

    private const RELEASE_FEE = ['provisional' => true, 'provisional_reason' => 'release_fee'];

    public function test_before_release_each_catalog_fee_is_sorted_and_marked_provisional(): void
    {
        $loan = $this->approvedLoan();

        $data = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $this->assertSame([
            ['name' => 'Processing Fee', 'amount' => 100],
            ['name' => 'Release Fee', 'amount' => 200] + self::RELEASE_FEE,
        ], $data['finance_charges']['items']);
        $this->assertSame([
            ['name' => 'Documentary Stamp Tax', 'amount' => 50, 'deducted' => 50, 'owed' => 0] + self::RELEASE_FEE,
        ], $data['non_finance_charges']['items']);

        $this->assertEquals(9650, $data['proceeds']['net_proceeds']);
        $this->assertEquals(300, $data['proceeds']['finance_charges_deducted']);
        $this->assertEquals(50, $data['proceeds']['non_finance_charges_deducted']);
        $this->assertEquals(350, $data['totals']['total_deductions']);
        $this->assertEquals(350, $data['deductions']['total_deductions']);
        $this->assertEquals(0, $data['totals']['unitemised_deductions']);

        $this->assertSame(['Processing Fee', 'Release Fee', 'Documentary Stamp Tax'], array_column($data['deductions']['items'], 'name'));
        $this->assertSame([null, 'release_fee', 'release_fee'], array_map(fn (array $item) => ($item['provisional'] ?? false) === true ? $item['provisional_reason'] : null, $data['deductions']['items']));

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

    public function test_draft_and_for_review_applications_carry_the_fees_too(): void
    {
        $loan = $this->draftLoan();

        // The endpoint discloses approved loans onwards; the service rule
        // covers every open application.
        $this->getJson("/api/loans/{$loan->id}/disclosure")->assertStatus(422);
        $draft = $this->disclosureOf($loan);
        $this->assertContains(['name' => 'Release Fee', 'amount' => 200] + self::RELEASE_FEE, $draft['finance_charges']['items']);
        $this->assertEquals(9650, $draft['proceeds']['net_proceeds']);

        app(LoanService::class)->submitForReview($loan);
        $this->assertSame('for_review', $loan->fresh()->status);
        $review = $this->disclosureOf($loan);
        $this->assertContains(['name' => 'Release Fee', 'amount' => 200] + self::RELEASE_FEE, $review['finance_charges']['items']);
        $this->assertEquals(9650, $review['proceeds']['net_proceeds']);
        $this->assertEquals(9650, $review['totals']['net_proceeds']);
    }

    public function test_rejected_and_void_loans_show_no_provisional_fees(): void
    {
        $loans = app(LoanService::class);
        $product = $this->productWithFees();

        $rejected = $this->draftLoan($product);
        $loans->submitForReview($rejected);
        $loans->reject($rejected, $this->admin, 'Not eligible');
        $this->assertSame('rejected', $rejected->fresh()->status);

        $void = $this->approvedLoan($product);
        $loans->voidLoan($void);
        $this->assertSame('void', $void->fresh()->status);

        foreach ([$rejected, $void] as $loan) {
            $data = $this->disclosureOf($loan);

            $this->assertSame([['name' => 'Processing Fee', 'amount' => 100]], $data['finance_charges']['items']);
            $this->assertSame([], $data['non_finance_charges']['items']);
            $this->assertEquals(9900, $data['proceeds']['net_proceeds']);
            $this->assertEquals(100, $data['totals']['total_deductions']);
            $this->assertSame([], array_filter(array_column($data['deductions']['items'], 'provisional')));
        }
    }

    public function test_a_provisional_fee_follows_the_unitemised_remainder(): void
    {
        $loan = $this->approvedLoan();
        // ₱50.25 withheld that no stored item explains.
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 150.25, 'net_proceeds' => 9849.75]);

        $data = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $this->assertSame([
            ['name' => 'Processing Fee', 'amount' => 100],
            ['name' => 'Other Charges', 'amount' => 50.25],
            ['name' => 'Release Fee', 'amount' => 200] + self::RELEASE_FEE,
        ], $data['finance_charges']['items']);
        $this->assertEquals(50.25, $data['totals']['unitemised_deductions']);
        $this->assertEquals(9599.75, $data['proceeds']['net_proceeds']);
        $this->assertEquals(400.25, $data['totals']['total_deductions']);

        $release = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');
        $this->assertEquals((float) $release['net_proceeds'], $data['proceeds']['net_proceeds']);
        $this->assertEquals((float) $release['net_proceeds'], $data['totals']['net_proceeds']);
        $this->assertEquals((float) $release['total_deductions'], $data['totals']['total_deductions']);
    }

    public function test_an_approved_restructure_shows_the_carried_premium_and_the_catalog_fee(): void
    {
        $source = $this->loanOwingPremium(['processing_fee' => 0, 'service_fee' => 0, 'notarial_fee' => 0]);
        // Configured after the source's release, so only the restructure is charged it.
        Fee::create(['name' => 'Release Fee', 'type' => 'percentage', 'value' => 2.0, 'applicable_product_ids' => [$source->loan_product_id]]);

        $id = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => self::SCHEDULE_TOTAL,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loans/{$id}/submit")->assertOk();
        $approver = User::factory()->create();
        $approver->assignRole(Role::where('name', 'admin')->first());
        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);
        $this->assertSame('approved', Loan::findOrFail($id)->status);

        $data = $this->getJson("/api/loans/{$id}/disclosure")->assertOk()->json('data');

        // 2% of ₱70,800.
        $this->assertSame([['name' => 'Release Fee', 'amount' => 1416] + self::RELEASE_FEE], $data['finance_charges']['items']);
        $this->assertSame([
            ['name' => 'Insurance premium carried from the previous loan', 'amount' => 400, 'deducted' => 0, 'owed' => 400, 'provisional' => true, 'provisional_reason' => 'carried_premium'],
        ], $data['non_finance_charges']['items']);

        $release = $this->getJson("/api/loans/{$id}/release-preview")->assertOk()->json('data');
        $this->assertEquals((float) $release['net_proceeds'], $data['proceeds']['net_proceeds']);
        $this->assertEquals(self::SCHEDULE_TOTAL - 1416, $data['proceeds']['net_proceeds']);
    }

    public function test_fees_above_the_principal_answer_the_release_preview_s_422(): void
    {
        $product = $this->product();
        Fee::create(['name' => 'Release Fee', 'type' => 'fixed', 'value' => 9950, 'applicable_product_ids' => [$product->id]]);
        $loans = app(LoanService::class);
        $loan = $this->loanOn($product, 10000, [['name' => 'Processing Fee', 'amount' => 100, 'type' => 'fixed', 'original_value' => 100]]);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        $preview = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertStatus(422)->json('errors.fees');
        $disclosure = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertStatus(422)->json('errors.fees');

        $this->assertSame($preview, $disclosure);
        $this->assertSame(['Configured fees of ₱9,950.00 bring total deductions to ₱10,050.00, which exceeds the ₱10,000.00 principal. Review the fee rules in Settings before releasing this loan.'], $disclosure);
    }

    public function test_a_percentage_fee_that_rounds_agrees_with_the_release_preview_to_the_centavo(): void
    {
        $product = $this->product();
        Fee::create(['name' => 'Release Fee', 'type' => 'percentage', 'value' => 1.5, 'applicable_product_ids' => [$product->id]]);
        $loans = app(LoanService::class);
        $loan = $this->loanOn($product, 3333.33);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        $data = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');
        $release = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');

        // 1.5% of ₱3,333.33 is ₱49.99995: ₱50.00 to the centavo.
        $this->assertSame([['name' => 'Release Fee', 'amount' => 50] + self::RELEASE_FEE], $data['finance_charges']['items']);
        $this->assertSame('3283.33', $release['net_proceeds']);
        $this->assertSame('50.00', $release['total_deductions']);
        $this->assertSame(3283.33, $data['proceeds']['net_proceeds']);
        $this->assertSame(3283.33, $data['totals']['net_proceeds']);
        $this->assertSame(3283.33, $data['deductions']['net_proceeds']);
        $this->assertSame(50, $data['totals']['total_deductions']);
    }
}
