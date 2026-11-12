<?php

namespace Tests\Feature;

use App\Models\LoanProduct;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A product's notarial fee is capped at 100, like every other fee and rate on
 * the product form, on create and on edit. A stored value above 100 is never
 * changed: an edit that leaves the field out keeps it.
 */
class LoanProductNotarialFeeCapTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Notarial Cap Product',
            'interest_rate' => 3,
            'interest_method' => 'straight',
            'term' => 12,
            'frequency' => 'monthly',
        ], $overrides);
    }

    public function test_create_accepts_a_notarial_fee_up_to_100_and_refuses_more(): void
    {
        foreach (['100', '99.9999', '0'] as $fee) {
            $this->postJson('/api/loan-products', $this->body(['notarial_fee' => $fee, 'name' => "Fee {$fee}"]))
                ->assertCreated();
        }

        foreach (['100.0001', '150', '5000'] as $fee) {
            $this->postJson('/api/loan-products', $this->body(['notarial_fee' => $fee]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('notarial_fee');
        }
    }

    public function test_edit_refuses_a_notarial_fee_above_100(): void
    {
        $product = LoanProduct::factory()->create(['notarial_fee' => 2]);

        $this->putJson("/api/loan-products/{$product->id}", ['notarial_fee' => 100.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notarial_fee');
        $this->assertEquals(2, $product->fresh()->notarial_fee);

        $this->putJson("/api/loan-products/{$product->id}", ['notarial_fee' => 100])->assertOk();
        $this->assertEquals(100, $product->fresh()->notarial_fee);
    }

    public function test_a_stored_fee_above_100_is_kept_by_an_edit_that_leaves_it_out(): void
    {
        $product = LoanProduct::factory()->create(['notarial_fee' => 250]);

        $this->putJson("/api/loan-products/{$product->id}", ['description' => 'Edited'])->assertOk();

        $this->assertEquals(250, $product->fresh()->notarial_fee);
    }
}
