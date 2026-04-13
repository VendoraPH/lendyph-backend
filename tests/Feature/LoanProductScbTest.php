<?php

use App\Models\LoanProduct;
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

it('exposes SCB fields in GET /api/loan-products', function () {
    LoanProduct::factory()->create([
        'name' => 'SCB Product',
        'scb_required' => true,
        'min_scb' => 200,
        'max_scb' => 800,
    ]);

    $response = $this->getJson('/api/loan-products')->assertSuccessful();

    $product = collect($response->json('data'))->firstWhere('name', 'SCB Product');
    expect($product)->not->toBeNull();
    expect($product['scb_required'])->toBeTrue();
    expect((float) $product['min_scb'])->toBe(200.0);
    expect((float) $product['max_scb'])->toBe(800.0);
});

it('persists SCB fields on loan product create', function () {
    $response = $this->postJson('/api/loan-products', [
        'name' => 'New SCB Product',
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 12,
        'frequency' => 'monthly',
        'penalty_rate' => 2.0,
        'grace_period_days' => 3,
        'min_amount' => 5000,
        'max_amount' => 100000,
        'scb_required' => true,
        'min_scb' => 250,
        'max_scb' => 1000,
    ])->assertCreated();

    expect($response->json('data.scb_required'))->toBeTrue();
    expect((float) $response->json('data.min_scb'))->toBe(250.0);
    expect((float) $response->json('data.max_scb'))->toBe(1000.0);

    $this->assertDatabaseHas('loan_products', [
        'name' => 'New SCB Product',
        'scb_required' => true,
        'min_scb' => 250,
        'max_scb' => 1000,
    ]);
});

it('defaults SCB fields to false/0 when not provided', function () {
    $response = $this->postJson('/api/loan-products', [
        'name' => 'No SCB Product',
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 12,
        'frequency' => 'monthly',
        'penalty_rate' => 2.0,
        'grace_period_days' => 3,
        'min_amount' => 5000,
        'max_amount' => 100000,
    ])->assertCreated();

    expect($response->json('data.scb_required'))->toBeFalse();
    expect((float) $response->json('data.min_scb'))->toBe(0.0);
    expect((float) $response->json('data.max_scb'))->toBe(0.0);
});

it('updates SCB fields on existing loan product', function () {
    $product = LoanProduct::factory()->create([
        'scb_required' => false,
        'min_scb' => 0,
        'max_scb' => 0,
    ]);

    $response = $this->putJson("/api/loan-products/{$product->id}", [
        'scb_required' => true,
        'min_scb' => 500,
        'max_scb' => 2000,
    ])->assertSuccessful();

    expect($response->json('data.scb_required'))->toBeTrue();
    expect((float) $response->json('data.min_scb'))->toBe(500.0);
    expect((float) $response->json('data.max_scb'))->toBe(2000.0);
});

it('rejects max_scb lower than min_scb', function () {
    $this->postJson('/api/loan-products', [
        'name' => 'Invalid Range',
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 12,
        'frequency' => 'monthly',
        'penalty_rate' => 2.0,
        'grace_period_days' => 3,
        'min_amount' => 5000,
        'max_amount' => 100000,
        'min_scb' => 1000,
        'max_scb' => 500,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['max_scb']);
});
