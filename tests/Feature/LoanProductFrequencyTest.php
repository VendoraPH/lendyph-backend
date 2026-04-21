<?php

use App\Enums\LoanFrequency;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('username', 'admin')->first();
    $this->actingAs($this->admin);
});

function loanProductFrequencyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Frequency Test Product',
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 12,
        'penalty_rate' => 2.0,
        'grace_period_days' => 0,
        'min_amount' => 5000,
        'max_amount' => 100000,
    ], $overrides);
}

it('accepts frequencies array with a single valid value', function () {
    $this->postJson('/api/loan-products', loanProductFrequencyPayload([
        'frequencies' => ['monthly'],
    ]))->assertCreated();

    $this->assertDatabaseHas('loan_products', [
        'name' => 'Frequency Test Product',
        'frequency' => 'monthly',
    ]);
});

it('accepts every canonical frequency', function (string $value) {
    $this->postJson('/api/loan-products', loanProductFrequencyPayload([
        'name' => "Product-{$value}",
        'frequencies' => [$value],
    ]))->assertCreated();

    $this->assertDatabaseHas('loan_products', [
        'name' => "Product-{$value}",
        'frequency' => $value,
    ]);
})->with(LoanFrequency::values());

it('accepts upon_maturity as a frequency (bullet loan product)', function () {
    $this->postJson('/api/loan-products', loanProductFrequencyPayload([
        'name' => 'Upon-Maturity Product',
        'frequencies' => ['upon_maturity'],
    ]))->assertCreated();

    $this->assertDatabaseHas('loan_products', [
        'name' => 'Upon-Maturity Product',
        'frequency' => 'upon_maturity',
    ]);
});

it('rejects empty frequencies array without silently defaulting', function () {
    $this->postJson('/api/loan-products', loanProductFrequencyPayload([
        'frequencies' => [],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['frequency']);
});

it('still accepts legacy singular frequency payload', function () {
    $this->postJson('/api/loan-products', loanProductFrequencyPayload([
        'name' => 'Legacy Payload',
        'frequency' => 'bi_weekly',
    ]))->assertCreated();

    $this->assertDatabaseHas('loan_products', [
        'name' => 'Legacy Payload',
        'frequency' => 'bi_weekly',
    ]);
});
