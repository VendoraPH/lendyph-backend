<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\DisclosureService;
use App\Services\FeeAmount;
use App\Services\LoanReleaseFeeService;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Fee type "formula" (decision 5e): a percentage of the principal, optionally
 * per month of the term, kept between a minimum and a maximum, rounded half up
 * to the centavo. Catalog fees charge it at release; the release preview, the
 * loan form preview and the disclosure quote the same figure; product custom
 * fees accept and store it.
 */
class FeeFormulaTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A product charging nothing of its own, so only the catalog fee shows. */
    private function freeProduct(array $overrides = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'term_unit' => 'months',
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 1000,
        ], $overrides));
    }

    private function approvedLoan(LoanProduct $product, float $principal = 10000): Loan
    {
        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'deductions' => [],
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function formulaFee(LoanProduct $product, array $overrides = []): Fee
    {
        return Fee::create(array_merge([
            'name' => 'Formula Fee',
            'type' => 'formula',
            'value' => 1.0,
            'per_month' => false,
            'min_amount' => null,
            'max_amount' => null,
            'applicable_product_ids' => [$product->id],
        ], $overrides));
    }

    private function previewedFeeAmount(Loan $loan, string $name = 'Formula Fee'): ?float
    {
        $deductions = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data.deductions');

        foreach ($deductions as $item) {
            if ($item['name'] === $name) {
                return (float) $item['amount'];
            }
        }

        return null;
    }

    // ── Settings API ─────────────────────────────────────────────────────

    public function test_a_formula_fee_is_saved_and_returned_with_its_fields(): void
    {
        $this->postJson('/api/fees', [
            'name' => 'Insurance Handling',
            'type' => 'formula',
            'value' => 0.5,
            'per_month' => true,
            'min_amount' => 150,
            'max_amount' => 1500.5,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'formula')
            ->assertJsonPath('data.value', 0.5)
            ->assertJsonPath('data.per_month', true)
            ->assertJsonPath('data.min_amount', 150)
            ->assertJsonPath('data.max_amount', 1500.5);

        $this->assertDatabaseHas('fees', ['name' => 'Insurance Handling', 'type' => 'formula', 'per_month' => 1, 'min_amount' => 150, 'max_amount' => 1500.5]);
    }

    public function test_a_formula_fee_needs_its_rate_and_a_minimum_no_larger_than_its_maximum(): void
    {
        $this->postJson('/api/fees', ['name' => 'No Rate', 'type' => 'formula'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->postJson('/api/fees', ['name' => 'Upside Down', 'type' => 'formula', 'value' => 1, 'min_amount' => 500, 'max_amount' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('max_amount');

        $this->postJson('/api/fees', ['name' => 'Centavo Plus', 'type' => 'formula', 'value' => 1, 'min_amount' => 10.005])
            ->assertUnprocessable()->assertJsonValidationErrors('min_amount');

        $this->postJson('/api/fees', ['name' => 'Negative', 'type' => 'formula', 'value' => 1, 'max_amount' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('max_amount');

        $this->postJson('/api/fees', ['name' => 'Not A Flag', 'type' => 'formula', 'value' => 1, 'per_month' => 'often'])
            ->assertUnprocessable()->assertJsonValidationErrors('per_month');

        $this->postJson('/api/fees', ['name' => 'Equal Bounds', 'type' => 'formula', 'value' => 1, 'min_amount' => 100, 'max_amount' => 100])
            ->assertCreated();
    }

    public function test_an_update_checks_the_bounds_against_the_stored_ones(): void
    {
        $fee = $this->formulaFee($this->freeProduct(), ['min_amount' => 100, 'max_amount' => 300]);

        $this->putJson("/api/fees/{$fee->id}", ['min_amount' => 400])
            ->assertUnprocessable()->assertJsonValidationErrors('max_amount');

        $this->putJson("/api/fees/{$fee->id}", ['min_amount' => 250])->assertOk()
            ->assertJsonPath('data.min_amount', 250)
            ->assertJsonPath('data.max_amount', 300);
    }

    public function test_a_fee_switched_off_formula_drops_its_formula_fields(): void
    {
        $fee = $this->formulaFee($this->freeProduct(), ['per_month' => true, 'min_amount' => 100, 'max_amount' => 300]);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'percentage'])->assertOk()
            ->assertJsonPath('data.per_month', false)
            ->assertJsonPath('data.min_amount', null)
            ->assertJsonPath('data.max_amount', null);
    }

    // ── What the release charges ─────────────────────────────────────────

    public function test_the_minimum_and_maximum_clamp_the_released_fee(): void
    {
        $product = $this->freeProduct(['max_amount' => 100000]);
        $this->formulaFee($product, ['min_amount' => 150, 'max_amount' => 300]);

        // 1% of ₱10,000.00 is ₱100.00: raised to the ₱150.00 minimum.
        $this->assertSame(150.0, $this->previewedFeeAmount($this->approvedLoan($product, 10000)));
        // 1% of ₱50,000.00 is ₱500.00: held to the ₱300.00 maximum.
        $this->assertSame(300.0, $this->previewedFeeAmount($this->approvedLoan($product, 50000)));
        // 1% of ₱20,000.00 is ₱200.00, between the two.
        $this->assertSame(200.0, $this->previewedFeeAmount($this->approvedLoan($product, 20000)));
    }

    public function test_per_month_multiplies_by_a_six_month_term(): void
    {
        $product = $this->freeProduct();
        $this->formulaFee($product, ['value' => 0.5, 'per_month' => true]);

        // 0.5% × 6 months of ₱10,000.00.
        $this->assertSame(300.0, $this->previewedFeeAmount($this->approvedLoan($product, 10000)));
    }

    public function test_weekly_and_daily_days_terms_convert_on_a_thirty_day_month(): void
    {
        $weekly = $this->freeProduct(['term' => 84, 'term_unit' => 'days', 'frequency' => 'weekly', 'interest_rate_frequency' => 'weekly']);
        $this->formulaFee($weekly, ['name' => 'Weekly Formula', 'per_month' => true]);

        $daily = $this->freeProduct(['term' => 45, 'term_unit' => 'days', 'frequency' => 'daily', 'interest_rate_frequency' => 'daily']);
        $this->formulaFee($daily, ['name' => 'Daily Formula', 'per_month' => true]);

        // 1% × 84/30 months of ₱10,000.00, and 1% × 45/30.
        $this->assertSame(280.0, $this->previewedFeeAmount($this->approvedLoan($weekly, 10000), 'Weekly Formula'));
        $this->assertSame(150.0, $this->previewedFeeAmount($this->approvedLoan($daily, 10000), 'Daily Formula'));
    }

    public function test_an_upon_maturity_loan_counts_its_term_in_months(): void
    {
        $product = $this->freeProduct(['term' => 3, 'interest_method' => 'upon_maturity', 'frequency' => 'upon_maturity']);
        $this->formulaFee($product, ['per_month' => true]);

        $this->assertSame(300.0, $this->previewedFeeAmount($this->approvedLoan($product, 10000)));
    }

    public function test_the_fee_rounds_half_up_to_the_centavo(): void
    {
        $product = $this->freeProduct();
        $this->formulaFee($product, ['value' => 0.1]);

        // 0.1% of ₱10,005.00 is exactly ₱10.005.
        $this->assertSame(10.01, $this->previewedFeeAmount($this->approvedLoan($product, 10005)));
    }

    public function test_preview_form_preview_and_disclosure_quote_exactly_what_the_release_charges(): void
    {
        $product = $this->freeProduct();
        $fee = $this->formulaFee($product, ['value' => 0.75, 'per_month' => true, 'min_amount' => 100, 'max_amount' => 1000]);
        $loan = $this->approvedLoan($product, 12345);

        // 0.75% × 6 months of ₱12,345.00 is ₱555.525: ₱555.53.
        $expected = ['name' => 'Formula Fee', 'amount' => 555.53, 'type' => 'formula', 'original_value' => 0.75, 'per_month' => true, 'min_amount' => 100.0, 'max_amount' => 1000.0, 'fee_id' => $fee->id];

        $form = $this->postJson('/api/loans/preview', [
            'loan_product_id' => $product->id,
            'principal_amount' => 12345,
            'interest_rate' => 3,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'deductions' => [],
        ])->assertOk()->json('data.deductions');

        $preview = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');
        $disclosure = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');

        $release = $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $preview['fee_fingerprint']])->assertOk()->json('data');

        $this->assertEquals([$expected], $release['deductions']);
        $this->assertSame('555.53', $release['total_deductions']);
        $this->assertSame('11789.47', $release['net_proceeds']);

        $this->assertEquals($release['deductions'], $preview['deductions']);
        $this->assertSame($release['total_deductions'], $preview['total_deductions']);
        $this->assertSame($release['net_proceeds'], $preview['net_proceeds']);

        $this->assertEquals([$expected], $form['configured_fees']);
        $this->assertEquals(555.53, $form['total_deductions']);
        $this->assertEquals(11789.47, $form['net_proceeds']);

        // The disclosure keeps classifying by name: a coop fee, a finance charge.
        $this->assertSame([['name' => 'Formula Fee', 'amount' => 555.53, 'provisional' => true, 'provisional_reason' => 'release_fee']], $disclosure['finance_charges']['items']);
        $this->assertEquals((float) $release['net_proceeds'], $disclosure['proceeds']['net_proceeds']);
        $this->assertEquals((float) $release['total_deductions'], $disclosure['totals']['total_deductions']);

        $after = app(DisclosureService::class)->generateDisclosure($loan->fresh());
        $this->assertSame([['name' => 'Formula Fee', 'amount' => 555.53]], $after['finance_charges']['items']);
    }

    public function test_a_changed_bound_since_the_preview_is_refused_with_409(): void
    {
        $product = $this->freeProduct();
        $fee = $this->formulaFee($product, ['min_amount' => 150, 'max_amount' => 300]);
        $loan = $this->approvedLoan($product, 10000);

        $fingerprint = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data.fee_fingerprint');

        $fee->update(['min_amount' => 200]);

        $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $fingerprint])
            ->assertStatus(409)->assertJsonValidationErrors(['fee_fingerprint']);
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_existing_fixed_and_percentage_items_keep_their_shape(): void
    {
        $product = $this->freeProduct();
        $fixed = Fee::create(['name' => 'Flat', 'type' => 'fixed', 'value' => 500, 'applicable_product_ids' => [$product->id]]);
        $percentage = Fee::create(['name' => 'Share', 'type' => 'percentage', 'value' => 2, 'applicable_product_ids' => [$product->id]]);

        $deductions = $this->getJson("/api/loans/{$this->approvedLoan($product)->id}/release-preview")->assertOk()->json('data.deductions');

        $this->assertEquals([
            ['name' => 'Flat', 'amount' => 500.0, 'type' => 'fixed', 'original_value' => 500.0, 'fee_id' => $fixed->id],
            ['name' => 'Share', 'amount' => 200.0, 'type' => 'percentage', 'original_value' => 2.0, 'fee_id' => $percentage->id],
        ], $deductions);
    }

    // ── Product custom fees ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $customFee */
    private function productPayload(array $customFee): array
    {
        return [
            'name' => 'Formula Product',
            'interest_rate' => 3,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'custom_fees' => [$customFee],
        ];
    }

    public function test_a_product_custom_formula_fee_is_saved_and_computes_like_a_catalog_one(): void
    {
        $customFee = ['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'per_month' => true, 'min_amount' => 100, 'max_amount' => 250];

        $id = $this->postJson('/api/loan-products', $this->productPayload($customFee))->assertCreated()->json('data.id');

        $stored = $this->getJson("/api/loan-products/{$id}")->assertOk()->json('data.custom_fees.0');
        $this->assertEquals($customFee, $stored);

        $months = FeeAmount::termMonths(6, 'months');
        // 0.5% × 6 months: ₱300.00 on ₱10,000.00 held to ₱250.00; ₱30.00 on
        // ₱1,000.00 raised to ₱100.00; ₱150.00 on ₱5,000.00.
        $this->assertSame(250.0, FeeAmount::forCustomFee($stored, 10000, $months));
        $this->assertSame(100.0, FeeAmount::forCustomFee($stored, 1000, $months));
        $this->assertSame(150.0, FeeAmount::forCustomFee($stored, 5000, $months));

        $this->putJson("/api/loan-products/{$id}", ['custom_fees' => [['name' => 'Handling', 'type' => 'formula', 'value' => 1, 'max_amount' => 400]]])
            ->assertOk()->assertJsonPath('data.custom_fees.0.max_amount', 400);
    }

    public function test_a_product_custom_formula_fee_with_a_minimum_above_its_maximum_is_refused(): void
    {
        $this->postJson('/api/loan-products', $this->productPayload(['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'min_amount' => 300, 'max_amount' => 100]))
            ->assertUnprocessable()->assertJsonValidationErrors('custom_fees.0.max_amount');

        $this->postJson('/api/loan-products', $this->productPayload(['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'min_amount' => 1.234]))
            ->assertUnprocessable()->assertJsonValidationErrors('custom_fees.0.min_amount');

        $product = $this->freeProduct();
        $this->putJson("/api/loan-products/{$product->id}", ['custom_fees' => [['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'min_amount' => 300, 'max_amount' => 100]]])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_fees.0.max_amount');
    }

    // ── Review follow-ups ────────────────────────────────────────────────

    /**
     * The hash development gives these two fees (computed from its
     * fingerprint(): sha256 of the JSON rows [id, name, type, value]). A
     * fixed or percentage fee's fingerprint must not move, or a preview
     * issued before the deploy would answer 409 at release.
     */
    public function test_fixed_and_percentage_fingerprints_are_unchanged_from_development(): void
    {
        $fees = [
            (new Fee)->forceFill(['id' => 7, 'name' => 'Flat Fee', 'type' => 'fixed', 'value' => 500, 'per_month' => true, 'min_amount' => 1, 'max_amount' => 2]),
            (new Fee)->forceFill(['id' => 8, 'name' => 'Share Fee', 'type' => 'percentage', 'value' => 2.5]),
        ];

        $this->assertSame(
            'e036d0bab5bbf28ff76ad8c6dedea1e5c39bec3c4d5e4371aef5723371aa2377',
            app(LoanReleaseFeeService::class)->fingerprint($fees),
        );
    }

    public function test_flipping_per_month_since_the_preview_is_refused_with_409(): void
    {
        $product = $this->freeProduct();
        $fee = $this->formulaFee($product, ['per_month' => false]);
        $loan = $this->approvedLoan($product, 10000);

        $fingerprint = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data.fee_fingerprint');

        $fee->update(['per_month' => true]);

        $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $fingerprint])
            ->assertStatus(409)->assertJsonValidationErrors(['fee_fingerprint']);
        $this->assertSame('approved', $loan->fresh()->status);
    }

    private function formulaMigration(): object
    {
        return require database_path('migrations/2026_11_02_210500_add_formula_type_to_fees_table.php');
    }

    public function test_the_rollback_is_refused_while_a_formula_fee_exists(): void
    {
        $this->formulaFee($this->freeProduct());

        try {
            $this->formulaMigration()->down();
            $this->fail('The rollback ran with a formula fee in Settings.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Formula fees exist', $e->getMessage());
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('fees', 'per_month'));
        $this->assertSame(1, Fee::where('type', 'formula')->count());
    }

    public function test_the_rollback_is_refused_while_a_product_carries_a_formula_custom_fee(): void
    {
        $this->freeProduct(['custom_fees' => [['name' => 'Flat', 'type' => 'fixed', 'value' => 50]]]);
        $this->freeProduct(['custom_fees' => [['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'per_month' => true]]]);

        try {
            $this->formulaMigration()->down();
            $this->fail('The rollback ran with a formula custom fee on a product.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('formula custom fees', $e->getMessage());
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('fees', 'per_month'));
    }

    public function test_a_formula_rate_above_100_percent_is_refused(): void
    {
        $this->postJson('/api/fees', ['name' => 'Runaway', 'type' => 'formula', 'value' => 100.0001])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson('/api/fees', ['name' => 'Whole', 'type' => 'formula', 'value' => 100])->assertCreated();

        // A ₱500 fixed fee switched to formula without a new rate would be 500%.
        $fixed = Fee::create(['name' => 'Five Hundred', 'type' => 'fixed', 'value' => 500]);
        $this->putJson("/api/fees/{$fixed->id}", ['type' => 'formula'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->putJson("/api/fees/{$fixed->id}", ['type' => 'formula', 'value' => 1.5])->assertOk();

        // A fixed fee is pesos, never capped at 100.
        $this->postJson('/api/fees', ['name' => 'Big Fixed', 'type' => 'fixed', 'value' => 500])->assertCreated();

        $this->postJson('/api/loan-products', $this->productPayload(['name' => 'Handling', 'type' => 'formula', 'value' => 150]))
            ->assertUnprocessable()->assertJsonValidationErrors('custom_fees.0.value');
    }

    public function test_a_product_custom_fee_that_is_not_a_formula_drops_the_formula_fields(): void
    {
        $id = $this->postJson('/api/loan-products', array_merge($this->productPayload(['name' => 'Flat', 'type' => 'fixed', 'value' => 50, 'per_month' => true, 'min_amount' => 10, 'max_amount' => 20]), [
            'custom_fees' => [
                ['name' => 'Flat', 'type' => 'fixed', 'value' => 50, 'per_month' => true, 'min_amount' => 10, 'max_amount' => 20],
                ['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'per_month' => true, 'min_amount' => 10],
            ],
        ]))->assertCreated()->json('data.id');

        $this->assertSame(
            [['name' => 'Flat', 'type' => 'fixed', 'value' => 50], ['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'per_month' => true, 'min_amount' => 10]],
            LoanProduct::find($id)->custom_fees,
        );

        $this->putJson("/api/loan-products/{$id}", ['custom_fees' => [['name' => 'Handling', 'type' => 'percentage', 'value' => 0.5, 'per_month' => true, 'max_amount' => 20]]])->assertOk();
        $this->assertSame([['name' => 'Handling', 'type' => 'percentage', 'value' => 0.5]], LoanProduct::find($id)->custom_fees);
    }

    public function test_a_per_month_formula_deduction_with_no_known_term_is_left_out(): void
    {
        $deductions = [
            ['name' => 'Flat', 'amount' => 50, 'type' => 'fixed'],
            ['name' => 'Handling', 'amount' => 1, 'type' => 'formula', 'per_month' => true, 'min_amount' => 100],
        ];
        $loans = app(LoanService::class);

        $unknown = $loans->computeDeductions(10000, $deductions);
        $this->assertSame(['Flat'], array_column($unknown['items'], 'name'));
        $this->assertEquals(50, $unknown['total']);

        // 1% × 6 months of ₱10,000.00, once the term is known.
        $known = $loans->computeDeductions(10000, $deductions, FeeAmount::termMonths(6, 'months'));
        $this->assertSame([50.0, 600.0], array_column($known['items'], 'amount'));
    }

    public function test_the_edit_preview_uses_the_loans_stored_term_unit_as_the_release_does(): void
    {
        $product = $this->freeProduct();
        $this->formulaFee($product, ['per_month' => true]);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
            'deductions' => [],
        ], $this->admin);

        // The product moves to a days term after the loan copied its months.
        $product->update(['term_unit' => 'days']);
        $this->assertSame('months', $loan->fresh()->term_unit->value);

        $form = $this->postJson('/api/loans/preview', [
            'loan_id' => $loan->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'term' => 6,
            'frequency' => 'monthly',
            'deductions' => [],
        ])->assertOk()->json('data.deductions.configured_fees');

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        // 1% × 6 months of ₱10,000.00, not 6 days' worth.
        $this->assertSame(600.0, $this->previewedFeeAmount($loan->fresh()));
        $this->assertEquals(600, $form[0]['amount']);
    }
}
