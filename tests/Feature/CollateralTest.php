<?php

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

it('lists collaterals for a borrower', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $other = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    Collateral::factory()->count(2)->create(['borrower_id' => $borrower->id]);
    Collateral::factory()->count(3)->create(['borrower_id' => $other->id]);

    $response = $this->getJson('/api/collaterals?borrower_id='.$borrower->id)
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('creates a collateral', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $type = CollateralType::where('name', 'Land Title')->first();

    $response = $this->postJson('/api/collaterals', [
        'borrower_id' => $borrower->id,
        'collateral_type_id' => $type->id,
        'detail_value' => 'TCT-99999',
        'amount' => 250000,
    ])->assertCreated();

    expect($response->json('data.detail_value'))->toBe('TCT-99999');
    expect((float) $response->json('data.amount'))->toBe(250000.0);
    expect($response->json('data.collateral_type.name'))->toBe('Land Title');

    $this->assertDatabaseHas('collaterals', [
        'borrower_id' => $borrower->id,
        'collateral_type_id' => $type->id,
        'detail_value' => 'TCT-99999',
    ]);
});

it('rejects collateral creation with invalid borrower', function () {
    $type = CollateralType::first();

    $this->postJson('/api/collaterals', [
        'borrower_id' => 99999,
        'collateral_type_id' => $type->id,
        'amount' => 100,
    ])->assertUnprocessable();
});

it('updates a collateral', function () {
    $collateral = Collateral::factory()->create(['amount' => 100000]);

    $this->putJson("/api/collaterals/{$collateral->id}", [
        'amount' => 175000,
    ])->assertOk()
        ->assertJsonPath('data.amount', 175000);
});

it('deletes a collateral that is not attached to any loan', function () {
    $collateral = Collateral::factory()->create();

    $this->deleteJson("/api/collaterals/{$collateral->id}")
        ->assertOk();

    $this->assertDatabaseMissing('collaterals', ['id' => $collateral->id]);
});

it('rejects deletion when collateral is attached to a loan', function () {
    $loan = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id]);

    $loan->collaterals()->attach($collateral->id, [
        'snapshot_value' => 50000,
        'attached_at' => now(),
    ]);

    $this->deleteJson("/api/collaterals/{$collateral->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('collaterals', ['id' => $collateral->id]);
});

it('attaches a collateral to a loan with snapshot value', function () {
    $loan = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id]);

    $response = $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    expect((float) $response->json('data.pivot.snapshot_value'))->toBe(250000.0);

    $this->assertDatabaseHas('loan_collaterals', [
        'loan_id' => $loan->id,
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ]);
});

it('rejects re-attaching the same collateral to the same loan', function () {
    $loan = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id]);

    $loan->collaterals()->attach($collateral->id, [
        'snapshot_value' => 100,
        'attached_at' => now(),
    ]);

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 200,
    ])->assertStatus(422);
});

it('lists collaterals attached to a loan', function () {
    $loan = $this->createReleasedLoan();
    $a = Collateral::factory()->create(['borrower_id' => $loan->borrower_id]);
    $b = Collateral::factory()->create(['borrower_id' => $loan->borrower_id]);

    $loan->collaterals()->attach($a->id, ['snapshot_value' => 100, 'attached_at' => now()]);
    $loan->collaterals()->attach($b->id, ['snapshot_value' => 200, 'attached_at' => now()]);

    $response = $this->getJson("/api/loans/{$loan->id}/collaterals")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('detaches a collateral from a loan', function () {
    $loan = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id]);

    $loan->collaterals()->attach($collateral->id, [
        'snapshot_value' => 100,
        'attached_at' => now(),
    ]);

    $this->deleteJson("/api/loans/{$loan->id}/collaterals/{$collateral->id}")
        ->assertOk();

    $this->assertDatabaseMissing('loan_collaterals', [
        'loan_id' => $loan->id,
        'collateral_id' => $collateral->id,
    ]);
});

it('viewer cannot create collateral', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $type = CollateralType::first();

    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    $this->postJson('/api/collaterals', [
        'borrower_id' => $borrower->id,
        'collateral_type_id' => $type->id,
        'amount' => 100,
    ])->assertForbidden();
});

it('viewer can list collaterals', function () {
    Collateral::factory()->count(2)->create();

    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    $this->getJson('/api/collaterals')->assertOk();
});

it('admin role can be updated with collateral permissions (regression for the original 422)', function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('username', 'super_admin')->first();
    $this->actingAs($admin);

    $adminRole = Role::where('name', 'admin')->first();

    $this->putJson("/api/roles/{$adminRole->id}", [
        'permissions' => [
            'dashboard:view',
            'collaterals:view',
            'collaterals:create',
            'collaterals:update',
            'collaterals:delete',
        ],
    ])->assertOk();

    $perms = \Spatie\Permission\Models\Permission::query()
        ->whereIn('name', ['collaterals:view', 'collaterals:create', 'collaterals:update', 'collaterals:delete'])
        ->pluck('name')
        ->all();
    expect($perms)->toContain('collaterals:view', 'collaterals:create', 'collaterals:update', 'collaterals:delete');

    expect($adminRole->fresh()->permissions->pluck('name')->all())
        ->toContain('collaterals:view', 'collaterals:create', 'collaterals:update', 'collaterals:delete');
});
