<?php

/**
 * A loan product keeps what was typed into it.
 *
 * The product form's payload is the one built by `formToApiPayload()` in the
 * frontend: it sends the term range, and `max_interest_rate`, and leaves out
 * the single-value `term`. An edit used to read that absence as "copy
 * max_term", so saving a product with a default term of 12 and a maximum of 24
 * rewrote the default to 24.
 */

use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->first());
});

/** A create body with everything the request requires. */
function newProductBody(array $overrides = []): array
{
    return array_merge([
        'name' => 'Salary Loan',
        'interest_rate' => 3,
        'interest_method' => 'straight',
        'term' => 12,
        'min_term' => 3,
        'max_term' => 24,
        'frequency' => 'monthly',
    ], $overrides);
}

it('has a nullable text description column', function () {
    expect(Schema::hasColumn('loan_products', 'description'))->toBeTrue()
        ->and(Schema::getColumnType('loan_products', 'description'))->toBe('text');

    expect(LoanProduct::factory()->create()->fresh()->description)->toBeNull();
});

it('stores and returns the description on create', function () {
    $response = $this->postJson('/api/loan-products', newProductBody(['description' => 'For salaried members']))
        ->assertCreated()
        ->assertJsonPath('data.description', 'For salaried members');

    $id = $response->json('data.id');

    expect(LoanProduct::find($id)->description)->toBe('For salaried members');
    $this->getJson("/api/loan-products/{$id}")->assertOk()->assertJsonPath('data.description', 'For salaried members');
    expect(collect($this->getJson('/api/loan-products')->json('data'))->firstWhere('id', $id)['description'])
        ->toBe('For salaried members');
});

it('changes the description on edit, clears it with null, and leaves it alone when absent', function () {
    $product = LoanProduct::factory()->create(['description' => 'Old']);

    $this->putJson("/api/loan-products/{$product->id}", ['description' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.description', 'New');
    expect($product->fresh()->description)->toBe('New');

    $this->putJson("/api/loan-products/{$product->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.description', 'New');
    expect($product->fresh()->description)->toBe('New');

    $this->putJson("/api/loan-products/{$product->id}", ['description' => null])
        ->assertOk()
        ->assertJsonPath('data.description', null);
    expect($product->fresh()->description)->toBeNull();
});

it('keeps the stored default term when an edit sends the term range without it', function () {
    $product = LoanProduct::factory()->create(['term' => 12, 'min_term' => 3, 'max_term' => 24]);

    $this->putJson("/api/loan-products/{$product->id}", [
        'name' => $product->name,
        'min_interest_rate' => 1,
        'max_interest_rate' => 3,
        'interest_method' => 'straight',
        'interest_rate_frequency' => 'monthly',
        'min_term' => 3,
        'max_term' => 24,
        'term_unit' => 'months',
        'frequencies' => ['monthly'],
        'grace_period_days' => 3,
        'scb_required' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.term', 12)
        ->assertJsonPath('data.max_term', 24);

    expect($product->fresh()->term)->toBe(12);
});

it('keeps the stored default term when an edit raises the maximum', function () {
    $product = LoanProduct::factory()->create(['term' => 12, 'min_term' => 3, 'max_term' => 24]);

    $this->putJson("/api/loan-products/{$product->id}", ['max_term' => 36])
        ->assertOk()
        ->assertJsonPath('data.term', 12)
        ->assertJsonPath('data.max_term', 36);
});

it('changes the default term when an edit sends one', function () {
    $product = LoanProduct::factory()->create(['term' => 12, 'min_term' => 3, 'max_term' => 24]);

    $this->putJson("/api/loan-products/{$product->id}", ['term' => 18, 'max_term' => 24])
        ->assertOk()
        ->assertJsonPath('data.term', 18);

    expect($product->fresh()->term)->toBe(18);
});
