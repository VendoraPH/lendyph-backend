<?php

use App\Models\LoanProduct;
use App\Models\User;
use App\Rules\UniqueLoanProductName;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Loan product names are unique, ignoring case and extra spaces, on create
 * and when a name changes. A product already sharing its name with another
 * still saves while the name isn't changed. There is no database index:
 * existing duplicates would break it.
 */

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->first());
    $this->existing = LoanProduct::factory()->create(['name' => 'Regular Loan']);
});

function uniqueProductNamePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Salary Loan',
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 12,
        'frequency' => 'monthly',
        'penalty_rate' => 2.0,
        'grace_period_days' => 0,
        'min_amount' => 5000,
        'max_amount' => 100000,
    ], $overrides);
}

it('refuses a new product named like another, ignoring case and spacing', function (string $name) {
    $this->postJson('/api/loan-products', uniqueProductNamePayload(['name' => $name]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'Another loan product is already named “Regular Loan”.']);
})->with([
    'the same' => 'Regular Loan',
    'lower case' => 'regular loan',
    'extra spaces' => '  REGULAR   loan ',
    'a tab' => "Regular\tLoan",
    'a non-breaking space inside' => "Regular\u{00A0}Loan",
    'non-breaking spaces around' => "\u{00A0}Regular Loan\u{00A0}",
]);

it('accepts a new product with a name of its own', function () {
    $this->postJson('/api/loan-products', uniqueProductNamePayload(['name' => 'Regular Loan Plus']))->assertCreated();
});

it('refuses renaming a product to another product\'s name', function () {
    $product = LoanProduct::factory()->create(['name' => 'Salary Loan']);

    $this->putJson("/api/loan-products/{$product->id}", ['name' => 'regular  LOAN'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'Another loan product is already named “Regular Loan”.']);
});

it('lets a product keep its own name, or change only its case or spacing', function () {
    $this->putJson("/api/loan-products/{$this->existing->id}", ['name' => 'Regular Loan', 'description' => 'Kept'])->assertOk();
    $this->putJson("/api/loan-products/{$this->existing->id}", ['name' => 'REGULAR  LOAN'])->assertOk();
});

it('still saves an existing duplicate while its name is not changed', function () {
    // Saved before names were unique.
    $duplicate = LoanProduct::factory()->create(['name' => 'regular loan']);

    $this->putJson("/api/loan-products/{$duplicate->id}", ['name' => 'regular loan', 'description' => 'Updated'])
        ->assertOk();
    $this->putJson("/api/loan-products/{$duplicate->id}", ['description' => 'Updated again'])
        ->assertOk();

    expect($duplicate->fresh()->description)->toBe('Updated again');
});

it('normalises a non-breaking space like any other space, inside the rule itself', function () {
    // Request trimming strips these before validation; the rule doesn't rely on it.
    expect(UniqueLoanProductName::normalise("\u{00A0}Regular\u{00A0}\u{2003}Loan\u{00A0}"))->toBe('regular loan')
        ->and(UniqueLoanProductName::normalise(" \tREGULAR   loan\n"))->toBe('regular loan');

    $fails = [];
    (new UniqueLoanProductName)->validate('name', "\u{00A0}Regular Loan\u{00A0}", function (string $message) use (&$fails) {
        $fails[] = $message;
    });

    expect($fails)->toBe(['Another loan product is already named “Regular Loan”.']);
});
