<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /loans/preview's `maturity_date` and `deductions`: the figures the loan
 * form and the restructure page show for what a loan will withhold, computed
 * by the code a create and a release run, so the form never adds anything up.
 */
class LoanFormPreviewDeductionsTest extends TestCase
{
    use SetupLendyPH;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'min_term' => 1,
            'max_term' => 12,
            'frequency' => 'monthly',
            'processing_fee' => 1.5,
            'service_fee' => 1,
            'notarial_fee' => 0,
            'min_amount' => 0,
        ]);
    }

    public function test_with_no_deductions_sent_it_withholds_the_products_own_fees_as_a_create_does(): void
    {
        $deductions = $this->preview()->assertOk()->json('data.deductions');

        $this->assertEquals([
            'items' => [
                ['name' => 'Processing Fee', 'amount' => 150, 'type' => 'percentage', 'original_value' => 1.5],
                ['name' => 'Service Fee', 'amount' => 100, 'type' => 'percentage', 'original_value' => 1],
            ],
            'stated_total' => 250,
            'configured_fees' => [],
            'configured_total' => 0,
            'total_deductions' => 250,
            'net_proceeds' => 9750,
            'error' => null,
        ], $deductions);

        // The same items and totals the loan stores when it is created.
        $loan = $this->create();
        $this->assertEquals($loan->deductions, $deductions['items']);
        $this->assertEquals((float) $loan->total_deductions, $deductions['total_deductions']);
        $this->assertEquals((float) $loan->net_proceeds, $deductions['net_proceeds']);
    }

    public function test_an_empty_list_withholds_nothing_and_stated_deductions_are_computed_as_a_create_computes_them(): void
    {
        $none = $this->preview(['deductions' => []])->json('data.deductions');
        $this->assertSame([], $none['items']);
        $this->assertEquals(0, $none['total_deductions']);
        $this->assertEquals(10000, $none['net_proceeds']);

        $stated = [
            ['name' => 'Processing Fee', 'amount' => 2.3456, 'type' => 'percentage'],
            ['name' => 'Notarial Fee', 'amount' => 199.99, 'type' => 'fixed'],
        ];
        $shown = $this->preview(['deductions' => $stated])->json('data.deductions');
        $loan = $this->create(['deductions' => $stated]);

        $this->assertEquals($loan->deductions, $shown['items']);
        // 2.3456% of ₱10,000 = ₱234.56, plus ₱199.99.
        $this->assertEquals(434.55, $shown['stated_total']);
        $this->assertEquals(9565.45, $shown['net_proceeds']);
        $this->assertEquals((float) $loan->net_proceeds, $shown['net_proceeds']);
    }

    public function test_it_adds_the_configured_fees_a_release_of_the_same_loan_would_charge(): void
    {
        // Applies: this product, ₱10,000 is above ₱5,000 and 181 days above 90.
        Fee::create(['name' => 'CI Fee', 'type' => 'fixed', 'value' => 100, 'applicable_product_ids' => [$this->product->id], 'conditions' => ['loan_amount_gt' => 5000, 'term_days_gt' => 90]]);
        Fee::create(['name' => 'Doc Stamp', 'type' => 'percentage', 'value' => 0.75, 'applicable_product_ids' => null, 'conditions' => null]);
        // Does not: another product, and a term condition this loan misses.
        $other = LoanProduct::factory()->create();
        Fee::create(['name' => 'Other Product Fee', 'type' => 'fixed', 'value' => 50, 'applicable_product_ids' => [$other->id], 'conditions' => null]);
        Fee::create(['name' => 'Short Term Fee', 'type' => 'fixed', 'value' => 70, 'applicable_product_ids' => null, 'conditions' => ['term_days_lt' => 30]]);

        $deductions = $this->preview()->json('data.deductions');

        $this->assertSame(['CI Fee', 'Doc Stamp'], array_column($deductions['configured_fees'], 'name'));
        $this->assertEquals(175, $deductions['configured_total']);
        $this->assertEquals(425, $deductions['total_deductions']);
        $this->assertEquals(9575, $deductions['net_proceeds']);

        // Item for item what the release preview of the saved loan adds.
        $loan = $this->create();
        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan->fresh(), $this->admin, 'ok');
        $release = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');

        $this->assertEquals(array_values(array_filter($release['deductions'], fn (array $item): bool => isset($item['fee_id']))), $deductions['configured_fees']);
        $this->assertSame($release['total_deductions'], number_format($deductions['total_deductions'], 2, '.', ''));
        $this->assertSame($release['net_proceeds'], number_format($deductions['net_proceeds'], 2, '.', ''));
    }

    public function test_deductions_above_the_principal_are_an_error_in_the_body_not_a_422(): void
    {
        $response = $this->preview(['deductions' => [['name' => 'Service Fee', 'amount' => 10000.01, 'type' => 'fixed']]])->assertOk();

        $deductions = $response->json('data.deductions');
        $this->assertSame('Total deductions exceed the principal amount.', $deductions['error']);
        $this->assertNull($deductions['net_proceeds']);
        $this->assertEquals(10000.01, $deductions['total_deductions']);
        $this->assertCount(1, $deductions['items']);
        // The rest of the preview still shows.
        $this->assertNotNull($response->json('data.amortization'));
        $this->assertSame('2026-07-15', $response->json('data.maturity_date'));
    }

    public function test_configured_fees_that_overrun_the_principal_are_an_error_with_the_release_guard_message(): void
    {
        Fee::create(['name' => 'Huge Fee', 'type' => 'fixed', 'value' => 9800, 'applicable_product_ids' => null, 'conditions' => null]);

        $deductions = $this->preview()->json('data.deductions');

        $this->assertStringStartsWith('Configured fees of ₱9,800.00 bring total deductions to ₱10,050.00', $deductions['error']);
        $this->assertNull($deductions['net_proceeds']);
        $this->assertEquals(10050, $deductions['total_deductions']);
    }

    public function test_there_are_no_deductions_until_a_product_and_a_principal_are_known(): void
    {
        $this->assertNull($this->preview(['loan_product_id' => null])->json('data.deductions'));
        $this->assertNull($this->preview(['principal_amount' => 0])->json('data.deductions'));
        $this->assertNull($this->preview(['principal_amount' => null])->json('data.deductions'));
    }

    public function test_the_maturity_date_needs_only_the_product_term_frequency_and_start(): void
    {
        $maturity = $this->postJson('/api/loans/preview', [
            'loan_product_id' => $this->product->id,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => '2026-01-31',
        ])->assertOk()->json('data.maturity_date');

        $this->assertSame($this->create(['start_date' => '2026-01-31'])->maturity_date->toDateString(), $maturity);

        // A term outside the product's range has none, as a create refuses it.
        $this->assertNull($this->preview(['term' => 13])->json('data.maturity_date'));
        $this->assertNull($this->preview(['frequency' => null])->json('data.maturity_date'));
    }

    public function test_malformed_deductions_are_a_422(): void
    {
        $this->preview(['deductions' => [['name' => 'Fee', 'amount' => 5, 'type' => 'per-cent']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deductions.0.type');

        $this->preview(['deductions' => [['name' => 'Fee', 'amount' => 1.23456, 'type' => 'percentage']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deductions.0.amount');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function preview(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/loans/preview', array_merge($this->terms(), $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = []): Loan
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return app(LoanService::class)->createLoan(array_merge($this->terms(), ['borrower_id' => $borrower->id], $overrides), $this->admin)->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function terms(): array
    {
        return [
            'loan_product_id' => $this->product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3.0,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => '2026-01-15',
        ];
    }
}
