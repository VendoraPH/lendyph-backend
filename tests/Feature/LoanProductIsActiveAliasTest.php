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
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

it('PUT /loan-products with is_active=false flips status to inactive', function () {
    $product = LoanProduct::factory()->create(['status' => 'active']);

    $this->putJson("/api/loan-products/{$product->id}", ['is_active' => false])
        ->assertOk();

    expect($product->fresh()->status)->toBe('inactive');
});

it('PUT /loan-products with is_active=true flips status back to active', function () {
    $product = LoanProduct::factory()->create(['status' => 'inactive']);

    $this->putJson("/api/loan-products/{$product->id}", ['is_active' => true])
        ->assertOk();

    expect($product->fresh()->status)->toBe('active');
});

it('PUT /loan-products with explicit status wins over is_active', function () {
    $product = LoanProduct::factory()->create(['status' => 'active']);

    $this->putJson("/api/loan-products/{$product->id}", [
        'is_active' => true,
        'status' => 'inactive',
    ])->assertOk();

    expect($product->fresh()->status)->toBe('inactive');
});

it('POST /loan-products honors is_active=false on create', function () {
    $response = $this->postJson('/api/loan-products', [
        'name' => 'Draft Product',
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 12,
        'frequency' => 'monthly',
        'is_active' => false,
    ])->assertCreated();

    $productId = $response->json('data.id');
    expect(LoanProduct::find($productId)->status)->toBe('inactive');
});
