<?php

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * GET /auth/me names the caller's own roles in words, so "Access Restricted"
 * can say which role the user holds even without `users:view` (which the
 * roles list needs). `roles` keeps its keys; `role_details` adds each of the
 * same roles with its display name, or the key in words when it has none.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

it('lists the caller\'s own roles with their display names, beside the keys', function () {
    $teller = Role::create(['name' => 'spec_front_desk', 'guard_name' => 'web', 'display_name' => 'Front Desk Teller']);
    $teller->syncPermissions(['loans:view']);
    $unnamed = Role::create(['name' => 'spec_night_shift', 'guard_name' => 'web']);
    Role::create(['name' => 'spec_not_mine', 'guard_name' => 'web', 'display_name' => 'Someone Else\'s Role']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->inBranches($this->branch)->create();
    $user->assignRole($teller, $unnamed);

    expect($user->can('users:view'))->toBeFalse();

    $data = $this->actingAs($user)->getJson('/api/auth/me')->assertOk()->json('data');

    expect($data['roles'])->toEqualCanonicalizing(['spec_front_desk', 'spec_night_shift'])
        ->and(collect($data['role_details'])->sortBy('key')->values()->all())->toBe([
            ['key' => 'spec_front_desk', 'display_name' => 'Front Desk Teller'],
            ['key' => 'spec_night_shift', 'display_name' => 'Spec Night Shift'],
        ])
        ->and(array_column($data['role_details'], 'key'))->toBe($data['roles']);
});

it('adds role_details only to the caller\'s own profile', function () {
    $other = User::factory()->inBranches($this->branch)->create();
    $other->assignRole('admin');

    $this->getJson('/api/auth/me')->assertOk()->assertJsonStructure(['data' => ['role_details']]);

    expect($this->getJson("/api/users/{$other->id}")->assertOk()->json('data'))->not->toHaveKey('role_details');
});
