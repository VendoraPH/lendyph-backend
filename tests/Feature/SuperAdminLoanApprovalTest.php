<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->superAdmin = User::where('username', 'super_admin')->first();
    expect($this->superAdmin)->not->toBeNull();
    expect($this->superAdmin->hasRole('super_admin'))->toBeTrue();
    $this->actingAs($this->superAdmin);
});

it('allows super_admin to approve a loan in for_review status', function () {
    $product = LoanProduct::factory()->create();
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $createResponse = $this->postJson('/api/loans', [
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 10000,
        'start_date' => now()->toDateString(),
    ])->assertCreated();

    $loanId = $createResponse->json('data.id');

    $this->patchJson("/api/loans/{$loanId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'for_review');

    $approveResponse = $this->patchJson("/api/loans/{$loanId}/approve", [
        'approval_remarks' => 'Approved by super_admin',
    ]);

    $approveResponse->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by_user.id', $this->superAdmin->id);
});

it('allows super_admin to reject a loan in for_review status', function () {
    $product = LoanProduct::factory()->create();
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $createResponse = $this->postJson('/api/loans', [
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 10000,
        'start_date' => now()->toDateString(),
    ])->assertCreated();

    $loanId = $createResponse->json('data.id');

    $this->patchJson("/api/loans/{$loanId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'for_review');

    $this->patchJson("/api/loans/{$loanId}/reject", [
        'approval_remarks' => 'Rejected by super_admin',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});
