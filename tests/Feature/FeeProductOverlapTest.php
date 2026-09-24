<?php

namespace Tests\Feature;

use App\Models\Fee;
use App\Models\LoanProduct;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The write-time guardrail against the double-charge footgun: a `Fee`
 * catalog row sharing a (normalized) name with a `LoanProduct`'s own nonzero
 * processing/service/notarial fee column, which `LoanReleaseFeeService`
 * would then charge TWICE at release — see `FeeOverlapDetector`'s class
 * docblock for the full reasoning.
 *
 * `LoanReleaseFeesTest` covers what release itself does with a collision,
 * including the two protected specs proving both mechanisms deliberately
 * charge when a collision reaches release — built via raw `Fee::create()`,
 * which bypasses this guard on purpose, the same way it bypasses every
 * FormRequest. Nothing here re-tests that; this is the write-time refusal in
 * front of it, plus the reverse (`LoanProduct`-side) direction of the same
 * check.
 */
class FeeProductOverlapTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * A product with all three colliding columns zeroed by default, so a
     * test only has to set the one field it cares about.
     */
    private function productWith(array $overrides = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
        ], $overrides));
    }

    // ── Creating a colliding fee is rejected, one per field ───────────────

    public function test_creating_a_fee_named_like_an_active_processing_fee_is_rejected(): void
    {
        $this->productWith(['processing_fee' => 2.0]);

        $response = $this->postJson('/api/fees', [
            'name' => 'Processing Fee',
            'type' => 'percentage',
            'value' => 2.0,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->assertDatabaseMissing('fees', ['name' => 'Processing Fee']);
    }

    public function test_creating_a_fee_named_like_an_active_service_fee_is_rejected(): void
    {
        $this->productWith(['service_fee' => 1.0]);

        $this->postJson('/api/fees', [
            'name' => 'Service Fee',
            'type' => 'percentage',
            'value' => 1.0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    public function test_creating_a_fee_named_like_an_active_notarial_fee_is_rejected(): void
    {
        $this->productWith(['notarial_fee' => 3.0]);

        $this->postJson('/api/fees', [
            'name' => 'Notarial Fee',
            'type' => 'percentage',
            'value' => 3.0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    // ── The rejection message ───────────────────────────────────────────

    public function test_the_rejection_message_names_the_colliding_product_and_field(): void
    {
        $this->productWith(['name' => 'Salary Loan', 'processing_fee' => 2.0]);

        $response = $this->postJson('/api/fees', [
            'name' => 'Processing Fee',
            'type' => 'percentage',
            'value' => 2.0,
        ])->assertUnprocessable();

        $message = $response->json('errors.name.0');
        $this->assertStringContainsString('Salary Loan', $message);
        $this->assertStringContainsString('processing fee', $message);
    }

    // ── Value-awareness: a name-only ban would fail these ─────────────────

    /** Proves the check is value-aware, not a blanket name ban. */
    public function test_a_same_named_fee_is_allowed_when_the_products_own_field_is_zero(): void
    {
        $this->productWith(['processing_fee' => 0]);

        $this->postJson('/api/fees', [
            'name' => 'Processing Fee',
            'type' => 'percentage',
            'value' => 2.0,
        ])->assertCreated();

        $this->assertDatabaseHas('fees', ['name' => 'Processing Fee']);
    }

    public function test_a_genuinely_distinct_name_is_never_blocked_even_with_active_product_fees(): void
    {
        $this->productWith(['processing_fee' => 2.0, 'service_fee' => 1.0, 'notarial_fee' => 3.0]);

        $this->postJson('/api/fees', [
            'name' => 'Insurance Premium',
            'type' => 'percentage',
            'value' => 1.0,
        ])->assertCreated();
    }

    /** Exact-normalized match only — a near miss is not a match. */
    public function test_a_near_miss_label_is_not_blocked(): void
    {
        $this->productWith(['processing_fee' => 2.0]);

        $this->postJson('/api/fees', [
            'name' => 'Processing Fee Waiver',
            'type' => 'fixed',
            'value' => 0,
        ])->assertCreated();
    }

    /** Lowercase, non-alphanumeric collapsed to spaces, trimmed — several variants of one label. */
    public function test_case_and_punctuation_variants_of_a_colliding_label_are_still_caught(): void
    {
        $this->productWith(['processing_fee' => 2.0]);

        foreach (['PROCESSING-FEE.', 'processing   fee', '  Processing Fee  '] as $variant) {
            $this->postJson('/api/fees', [
                'name' => $variant,
                'type' => 'percentage',
                'value' => 2.0,
            ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        }
    }

    // ── The escape hatch ────────────────────────────────────────────────

    public function test_scoping_a_fee_away_from_the_colliding_product_is_allowed(): void
    {
        $this->productWith(['processing_fee' => 2.0]);
        $other = $this->productWith(['processing_fee' => 0]);

        $this->postJson('/api/fees', [
            'name' => 'Processing Fee',
            'type' => 'percentage',
            'value' => 2.0,
            'applicable_product_ids' => [$other->id],
        ])->assertCreated();
    }

    // ── Malformed input is a clean 422, not a 500 ─────────────────────────

    /**
     * Regression: `Validator::after()` runs even when the standalone
     * `applicable_product_ids => array` rule has already failed for this
     * exact input — so a non-array value (still present, just the wrong
     * shape) reaches this guard's `after()` callback regardless. Before
     * `GuardsAgainstProductFeeOverlap` normalized it with
     * `is_array($rawProductIds) ? $rawProductIds : null`, the raw string
     * flowed straight into `FeeOverlapDetector::collidingProducts()`'s
     * strict `?array` parameter and threw an uncaught TypeError — a bare
     * 500 — instead of the clean 422 the `array` rule was already about to
     * produce on its own.
     */
    public function test_a_malformed_applicable_product_ids_returns_a_clean_422_not_a_500(): void
    {
        $response = $this->postJson('/api/fees', [
            'name' => 'Some New Fee',
            'type' => 'fixed',
            'value' => 100,
            'applicable_product_ids' => '5',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['applicable_product_ids']);
        $this->assertDatabaseMissing('fees', ['name' => 'Some New Fee']);
    }

    // ── Updates ─────────────────────────────────────────────────────────

    public function test_updating_an_existing_fees_name_into_a_collision_is_rejected(): void
    {
        $this->productWith(['service_fee' => 1.5]);
        $fee = Fee::factory()->create(['name' => 'Harmless Fee']);

        $this->putJson("/api/fees/{$fee->id}", [
            'name' => 'Service Fee',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $this->assertSame('Harmless Fee', $fee->fresh()->name);
    }

    /**
     * The regression case that ruled out a field-attached `ValidationRule`
     * on `name`: a PATCH that resends only `applicable_product_ids`, never
     * `name`, must still be checked against the EFFECTIVE post-write name —
     * the row's existing one, since this request never sends a new one.
     */
    public function test_updating_only_applicable_product_ids_into_a_collision_is_rejected_even_when_name_is_not_resent(): void
    {
        $colliding = $this->productWith(['notarial_fee' => 5.0]);
        $safe = $this->productWith(['notarial_fee' => 0]);

        // Raw Eloquent, like the protected specs in LoanReleaseFeesTest —
        // bypasses this guard on purpose to get a "Notarial Fee" row on the
        // books that is not YET colliding, scoped only to a product whose
        // own column is 0.
        $fee = Fee::create([
            'name' => 'Notarial Fee',
            'type' => 'percentage',
            'value' => 5.0,
            'applicable_product_ids' => [$safe->id],
        ]);

        $response = $this->putJson("/api/fees/{$fee->id}", [
            'applicable_product_ids' => [$colliding->id],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->assertEquals([$safe->id], $fee->fresh()->applicable_product_ids);
    }

    // ── Companion: the LoanProduct side ────────────────────────────────

    public function test_raising_a_products_fee_into_a_collision_with_an_existing_catalog_fee_is_rejected(): void
    {
        Fee::create(['name' => 'Service Fee', 'type' => 'percentage', 'value' => 1.0]);
        $product = $this->productWith();

        $response = $this->putJson("/api/loan-products/{$product->id}", [
            'service_fee' => 1.0,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['service_fee']);
        $this->assertEquals(0, (float) $product->fresh()->service_fee);
    }

    public function test_raising_a_products_fee_is_allowed_when_nothing_matches(): void
    {
        Fee::create(['name' => 'Insurance Premium', 'type' => 'percentage', 'value' => 1.0]);
        $product = $this->productWith();

        $this->putJson("/api/loan-products/{$product->id}", [
            'service_fee' => 1.0,
        ])->assertOk();

        $this->assertEquals(1.0, (float) $product->fresh()->service_fee);
    }

    /**
     * Beyond the plan's minimum list: the STORE-side (create) half of the
     * same reverse check, which is a distinct code path — a product being
     * created has no id yet, so only an UNSCOPED catalog fee (one that
     * applies to every product) can possibly collide with it. See
     * `FeeOverlapDetector::collidingFees()`'s `$productId === null` branch.
     */
    public function test_creating_a_loan_product_whose_fee_matches_an_unscoped_catalog_fee_is_rejected(): void
    {
        Fee::create(['name' => 'Processing Fee', 'type' => 'percentage', 'value' => 2.0]);

        $response = $this->postJson('/api/loan-products', [
            'name' => 'New Product',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 2.0,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['processing_fee']);
        $this->assertDatabaseMissing('loan_products', ['name' => 'New Product']);
    }
}
