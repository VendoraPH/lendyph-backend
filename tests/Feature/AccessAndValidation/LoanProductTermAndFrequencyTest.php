<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\LoanProduct;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Saving a loan product keeps its stored `frequency` while that frequency is
 * still allowed, and the default `term` stays between `min_term` and `max_term`.
 */
class LoanProductTermAndFrequencyTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Salary Loan',
            'interest_rate' => 3,
            'interest_method' => 'straight',
            'term' => 6,
            'min_term' => 3,
            'max_term' => 12,
            'frequency' => 'monthly',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storedProduct(array $attributes = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'term' => 6,
            'min_term' => 3,
            'max_term' => 12,
            'frequency' => 'semi_monthly',
            'frequencies' => ['monthly', 'semi_monthly'],
        ], $attributes));
    }

    public function test_an_update_keeps_the_stored_frequency_while_it_is_still_allowed(): void
    {
        $product = $this->storedProduct();

        $this->putJson("/api/loan-products/{$product->id}", ['frequencies' => ['monthly', 'semi_monthly', 'weekly']])
            ->assertOk();

        $this->assertSame('semi_monthly', $this->storedFrequency($product));
        $this->assertSame(['monthly', 'semi_monthly', 'weekly'], $product->fresh()->frequencies);
    }

    public function test_an_update_moves_the_frequency_when_the_stored_one_is_no_longer_allowed(): void
    {
        $product = $this->storedProduct();

        $this->putJson("/api/loan-products/{$product->id}", ['frequencies' => ['weekly', 'monthly']])
            ->assertOk();

        $this->assertSame('weekly', $this->storedFrequency($product));
    }

    public function test_a_sent_frequency_still_wins(): void
    {
        $product = $this->storedProduct();

        $this->putJson("/api/loan-products/{$product->id}", ['frequency' => 'monthly', 'frequencies' => ['monthly', 'semi_monthly']])
            ->assertOk();

        $this->assertSame('monthly', $this->storedFrequency($product));
    }

    public function test_creating_a_product_refuses_a_term_outside_its_range(): void
    {
        foreach ([2, 13] as $term) {
            $this->postJson('/api/loan-products', $this->productPayload(['term' => $term]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term']);
        }

        foreach ([3, 12] as $term) {
            $this->postJson('/api/loan-products', $this->productPayload(['name' => "Salary Loan {$term}", 'term' => $term]))
                ->assertCreated();
        }
    }

    public function test_a_range_with_an_open_end_only_checks_the_end_it_has(): void
    {
        $this->postJson('/api/loan-products', $this->productPayload(['name' => 'No range', 'term' => 60, 'min_term' => null, 'max_term' => null]))
            ->assertCreated();

        $this->postJson('/api/loan-products', $this->productPayload(['name' => 'Floor only', 'term' => 2, 'max_term' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term']);
    }

    public function test_an_update_refuses_a_term_outside_the_range(): void
    {
        $product = $this->storedProduct();

        $this->putJson("/api/loan-products/{$product->id}", ['term' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term']);

        $this->assertSame(6, (int) $product->fresh()->term);

        $this->putJson("/api/loan-products/{$product->id}", ['term' => 12])->assertOk();
    }

    public function test_an_update_cannot_move_the_range_away_from_the_stored_term(): void
    {
        $product = $this->storedProduct();

        $this->putJson("/api/loan-products/{$product->id}", ['max_term' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term']);

        $this->putJson("/api/loan-products/{$product->id}", ['min_term' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term']);

        $this->assertSame(12, (int) $product->fresh()->max_term);
    }

    /**
     * Products saved before this rule can hold a term outside their range. An
     * edit that doesn't touch the term or the range still saves.
     */
    public function test_an_older_product_outside_its_range_can_still_change_other_fields(): void
    {
        $product = $this->storedProduct(['term' => 24]);

        $this->putJson("/api/loan-products/{$product->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $product->fresh()->name);
    }

    private function storedFrequency(LoanProduct $product): string
    {
        return (string) $product->fresh()->getRawOriginal('frequency');
    }
}
