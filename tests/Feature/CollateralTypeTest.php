<?php

use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

it('lists seeded collateral types', function () {
    $response = $this->getJson('/api/collateral-types')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Land Title', 'Chattel', 'Share Capital', 'Stock Certificate');
});

it('creates a custom collateral type', function () {
    $response = $this->postJson('/api/collateral-types', [
        'name' => 'Vehicle Title',
        'detail_field_label' => 'OR/CR No.',
        'amount_field_label' => 'Appraised Value',
        'source' => 'manual',
        'display_order' => 5,
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Vehicle Title');
    expect($response->json('data.is_seed'))->toBeFalse();
});

it('rejects duplicate type name', function () {
    $this->postJson('/api/collateral-types', [
        'name' => 'Land Title',
        'detail_field_label' => 'X',
        'amount_field_label' => 'Y',
    ])->assertStatus(422);
});

it('updates a non-seed type', function () {
    $type = CollateralType::factory()->create(['name' => 'Old Name']);

    $this->putJson("/api/collateral-types/{$type->id}", [
        'name' => 'New Name',
    ])->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

it('rejects deletion of a seeded type', function () {
    $type = CollateralType::where('is_seed', true)->first();

    $this->deleteJson("/api/collateral-types/{$type->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('collateral_types', ['id' => $type->id]);
});

it('rejects deletion of a type that is in use', function () {
    $type = CollateralType::factory()->create();
    Collateral::factory()->create(['collateral_type_id' => $type->id]);

    $this->deleteJson("/api/collateral-types/{$type->id}")
        ->assertStatus(422);
});

it('deletes an unused, non-seed type', function () {
    $type = CollateralType::factory()->create();

    $this->deleteJson("/api/collateral-types/{$type->id}")
        ->assertOk();

    $this->assertDatabaseMissing('collateral_types', ['id' => $type->id]);
});

it('viewer can list types but cannot create', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    $this->getJson('/api/collateral-types')->assertOk();

    $this->postJson('/api/collateral-types', [
        'name' => 'Forbidden',
        'detail_field_label' => 'a',
        'amount_field_label' => 'b',
    ])->assertForbidden();
});
