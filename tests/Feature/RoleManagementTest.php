<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

it('lists all roles with is_system, is_active, users_count', function () {
    $response = $this->getJson('/api/roles')->assertSuccessful();

    $admin = collect($response->json('data'))->firstWhere('name', 'admin');
    expect($admin)->not->toBeNull();
    expect($admin['is_system'])->toBeTrue();
    expect($admin['is_active'])->toBeTrue();
    expect($admin['users_count'])->toBeInt();
    expect($admin)->toHaveKey('description');
});

it('creates a custom role with permissions', function () {
    $response = $this->postJson('/api/roles', [
        'name' => 'branch_manager',
        'description' => 'Manages a branch',
        'permissions' => ['dashboard:view', 'borrowers:view', 'loans:view'],
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('branch_manager');
    expect($response->json('data.is_system'))->toBeFalse();
    expect($response->json('data.is_active'))->toBeTrue();
    expect($response->json('data.permissions'))->toContain('dashboard:view');

    $this->assertDatabaseHas('roles', [
        'name' => 'branch_manager',
        'is_system' => false,
        'is_active' => true,
    ]);
});

it('rejects custom role name that is not snake_case', function () {
    $this->postJson('/api/roles', [
        'name' => 'BranchManager',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rejects duplicate custom role name', function () {
    Role::create(['name' => 'branch_manager', 'guard_name' => 'web']);

    $this->postJson('/api/roles', [
        'name' => 'branch_manager',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('updates description and permissions on a custom role', function () {
    $role = Role::create(['name' => 'cash_manager', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissions(['dashboard:view']);

    $this->putJson("/api/roles/{$role->id}", [
        'description' => 'Handles daily cashflow',
        'permissions' => ['dashboard:view', 'payments:view', 'payments:create'],
    ])->assertSuccessful()
        ->assertJsonPath('data.description', 'Handles daily cashflow');

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->toArray())
        ->toBe(['dashboard:view', 'payments:create', 'payments:view']);
});

it('rejects rename of a system role', function () {
    $admin = Role::where('name', 'admin')->first();

    $this->putJson("/api/roles/{$admin->id}", [
        'name' => 'super_admin',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('allows description edit on a system role', function () {
    $loanOfficer = Role::where('name', 'loan_officer')->first();

    $this->putJson("/api/roles/{$loanOfficer->id}", [
        'description' => 'Updated description only',
    ])->assertSuccessful()
        ->assertJsonPath('data.description', 'Updated description only');
});

it('deactivates and reactivates a custom role', function () {
    $role = Role::create(['name' => 'temp_role', 'guard_name' => 'web', 'is_system' => false]);

    $this->patchJson("/api/roles/{$role->id}/deactivate")
        ->assertSuccessful()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/roles/{$role->id}/reactivate")
        ->assertSuccessful()
        ->assertJsonPath('data.is_active', true);
});

it('rejects deactivation of the admin role', function () {
    $admin = Role::where('name', 'admin')->first();

    $this->patchJson("/api/roles/{$admin->id}/deactivate")
        ->assertStatus(422);
});

it('deletes a custom role with no assigned users', function () {
    $role = Role::create(['name' => 'deletable', 'guard_name' => 'web', 'is_system' => false]);

    $this->deleteJson("/api/roles/{$role->id}")
        ->assertSuccessful();

    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
});

it('rejects deletion of a system role', function () {
    $admin = Role::where('name', 'admin')->first();

    $this->deleteJson("/api/roles/{$admin->id}")
        ->assertStatus(422);
});

it('rejects deletion of a custom role that has assigned users', function () {
    $role = Role::create(['name' => 'in_use', 'guard_name' => 'web', 'is_system' => false]);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->deleteJson("/api/roles/{$role->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

it('requires settings:delete permission to destroy a role (settings:update alone is insufficient)', function () {
    $custom = Role::create(['name' => 'deletable_by_delegated', 'guard_name' => 'web', 'is_system' => false]);

    $operator = User::factory()->create();
    $operatorRole = Role::create(['name' => 'settings_updater', 'guard_name' => 'web', 'is_system' => false]);
    $operatorRole->syncPermissions(['settings:view', 'settings:update', 'users:view']);
    $operator->assignRole($operatorRole);

    $this->actingAs($operator)
        ->deleteJson("/api/roles/{$custom->id}")
        ->assertStatus(403);

    $this->assertDatabaseHas('roles', ['id' => $custom->id]);
});

it('allows destroy when the user has settings:delete', function () {
    $custom = Role::create(['name' => 'deletable_role', 'guard_name' => 'web', 'is_system' => false]);

    $operator = User::factory()->create();
    $operatorRole = Role::create(['name' => 'settings_deleter', 'guard_name' => 'web', 'is_system' => false]);
    $operatorRole->syncPermissions(['settings:view', 'settings:delete', 'users:view']);
    $operator->assignRole($operatorRole);

    $this->actingAs($operator)
        ->deleteJson("/api/roles/{$custom->id}")
        ->assertSuccessful();

    $this->assertDatabaseMissing('roles', ['id' => $custom->id]);
});

it('exposes users_count on GET', function () {
    $role = Role::create(['name' => 'counted', 'guard_name' => 'web', 'is_system' => false]);
    User::factory()->count(2)->create()->each(fn ($u) => $u->assignRole($role));

    $response = $this->getJson("/api/roles/{$role->id}")->assertSuccessful();

    expect($response->json('data.users_count'))->toBe(2);
});

it('saves a display_name on a custom role without changing its key', function () {
    $role = Role::create(['name' => 'cash_manager', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", [
        'display_name' => 'Cash Desk Manager',
    ])->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Cash Desk Manager')
        ->assertJsonPath('data.name', 'cash_manager');

    $this->assertDatabaseHas('roles', [
        'id' => $role->id,
        'name' => 'cash_manager',
        'display_name' => 'Cash Desk Manager',
    ]);
});

it('saves a display_name on a system role and still refuses a changed name', function () {
    $loanOfficer = Role::where('name', 'loan_officer')->first();

    $this->putJson("/api/roles/{$loanOfficer->id}", [
        'display_name' => 'Account Officer',
    ])->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Account Officer')
        ->assertJsonPath('data.name', 'loan_officer');

    $this->putJson("/api/roles/{$loanOfficer->id}", [
        'name' => 'account_officer',
        'display_name' => 'Renamed Again',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect($loanOfficer->fresh()->name)->toBe('loan_officer');
    expect($loanOfficer->fresh()->display_name)->toBe('Account Officer');
});

it('returns display_name on role reads', function () {
    $role = Role::create(['name' => 'counted_display', 'guard_name' => 'web', 'is_system' => false]);
    $role->forceFill(['display_name' => 'Counted Display'])->save();

    $this->getJson("/api/roles/{$role->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Counted Display');

    $listed = collect($this->getJson('/api/roles')->assertSuccessful()->json('data'))
        ->firstWhere('name', 'counted_display');

    expect($listed)->toHaveKey('display_name');
    expect($listed['display_name'])->toBe('Counted Display');
});

it('accepts display_name when creating a role', function () {
    $this->postJson('/api/roles', [
        'name' => 'branch_teller',
        'display_name' => 'Branch Teller',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'branch_teller')
        ->assertJsonPath('data.display_name', 'Branch Teller');

    $this->assertDatabaseHas('roles', [
        'name' => 'branch_teller',
        'display_name' => 'Branch Teller',
    ]);
});

it('rejects a display_name longer than 100 characters', function () {
    $role = Role::create(['name' => 'long_label', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", [
        'display_name' => str_repeat('a', 101),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    $this->postJson('/api/roles', [
        'name' => 'long_label_two',
        'display_name' => str_repeat('a', 101),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
});

function clientAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'admin')->firstOrFail());

    return $user;
}

it('refuses a client admin changing the super_admin display_name but allows other system roles', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    $cashier = Role::where('name', 'cashier')->firstOrFail();

    $this->actingAs(clientAdmin())
        ->putJson("/api/roles/{$superAdmin->id}", ['display_name' => 'Branch Clerk'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    expect($superAdmin->fresh()->display_name)->toBeNull();

    $this->actingAs(clientAdmin())
        ->putJson("/api/roles/{$cashier->id}", ['display_name' => 'Teller'])
        ->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Teller');
});

it('lets a super_admin change the super_admin display_name', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();

    $this->putJson("/api/roles/{$superAdmin->id}", ['display_name' => 'Platform Owner'])
        ->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Platform Owner')
        ->assertJsonPath('data.name', 'super_admin');
});

it('rejects a display_name that reads as another role key', function (string $label) {
    $role = Role::create(['name' => 'lookalike', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => $label])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    $this->postJson('/api/roles', ['name' => 'lookalike_two', 'display_name' => $label])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
})->with(['Admin', 'super admin', 'Super_Admin', '  super   ADMIN  ']);

it('rejects a display_name that reads as another role display_name', function () {
    $cashier = Role::where('name', 'cashier')->firstOrFail();
    $cashier->forceFill(['display_name' => 'Cash Desk'])->save();

    $role = Role::create(['name' => 'second_desk', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => 'cash_desk'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
});

it('lets a role keep a display_name equal to its own key', function () {
    $role = Role::create(['name' => 'cash_manager', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => '  Cash Manager '])
        ->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Cash Manager');
});

it('rejects control, zero-width and bidi characters in display_name', function (string $label) {
    $role = Role::create(['name' => 'odd_chars', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => $label])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
})->with([
    'control' => "Desk\u{0007}Clerk",
    'zero-width space' => "Desk\u{200B}Clerk",
    'right-to-left override' => "\u{202E}Desk Clerk",
]);

it('forbids creating or updating a role without settings:update', function () {
    $role = Role::create(['name' => 'guarded_role', 'guard_name' => 'web', 'is_system' => false]);

    $viewer = User::factory()->create();
    $viewerRole = Role::create(['name' => 'settings_viewer_only', 'guard_name' => 'web', 'is_system' => false]);
    $viewerRole->syncPermissions(['settings:view', 'users:view']);
    $viewer->assignRole($viewerRole);

    $this->actingAs($viewer)
        ->putJson("/api/roles/{$role->id}", ['display_name' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson('/api/roles', ['name' => 'not_allowed', 'display_name' => 'Not Allowed'])
        ->assertForbidden();

    expect($role->fresh()->display_name)->toBeNull();
    $this->assertDatabaseMissing('roles', ['name' => 'not_allowed']);
});

it('rejects a new role key that reads as another role display_name', function (string $label, string $key) {
    Role::where('name', 'cashier')->firstOrFail()->forceFill(['display_name' => $label])->save();

    $this->postJson('/api/roles', ['name' => $key])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect(Role::where('name', $key)->exists())->toBeFalse();
})->with([
    'single word' => ['Teller', 'teller'],
    'spaced label' => ['Head  Teller', 'head_teller'],
]);

it('rejects renaming a custom role key to another role display_name', function () {
    Role::where('name', 'cashier')->firstOrFail()->forceFill(['display_name' => 'Teller'])->save();
    $role = Role::create(['name' => 'front_desk', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['name' => 'teller'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect($role->fresh()->name)->toBe('front_desk');
});

it('lets a custom role rename its key to its own display_name', function () {
    $role = Role::create(['name' => 'front_desk', 'guard_name' => 'web', 'is_system' => false, 'display_name' => 'Desk Lead']);

    $this->putJson("/api/roles/{$role->id}", ['name' => 'desk_lead'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'desk_lead')
        ->assertJsonPath('data.display_name', 'Desk Lead');
});

it('rejects a compatibility-form display_name that reads as another role key', function (string $label) {
    $role = Role::create(['name' => 'lookalike', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => $label])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    $this->postJson('/api/roles', ['name' => 'lookalike_two', 'display_name' => $label])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
})->with([
    'fullwidth' => "\u{FF21}\u{FF44}\u{FF4D}\u{FF49}\u{FF4E}",
    'mathematical bold' => "\u{1D400}\u{1D41D}\u{1D426}\u{1D422}\u{1D427}",
    'fullwidth underscore' => "super\u{FF3F}admin",
]);

it('rejects combining marks and Hangul fillers in display_name', function (string $label) {
    $role = Role::create(['name' => 'odd_marks', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => $label])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    $this->postJson('/api/roles', ['name' => 'odd_marks_two', 'display_name' => $label])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
})->with([
    'stray mark after a space' => "Desk \u{0301}Clerk",
    'combining overlay' => "Desk Cle\u{0336}rk",
    'U+115F' => "Desk\u{115F}Clerk",
    'U+1160' => "Desk\u{1160}Clerk",
    'U+3164' => "Desk\u{3164}Clerk",
    'U+FFA0' => "Desk\u{FFA0}Clerk",
    'unassigned U+2065' => "Desk\u{2065}Clerk",
    'unassigned U+FFF0' => "Desk\u{FFF0}Clerk",
    'unassigned tag area U+E0080' => "Desk\u{E0080}Clerk",
    'Braille blank U+2800' => "Desk\u{2800}Clerk",
    'private use U+E000' => "Desk\u{E000}Clerk",
]);

it('saves a display_name with precomposed accents', function () {
    $role = Role::create(['name' => 'accented', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => "Jos\u{00E9} Cashier"])
        ->assertSuccessful()
        ->assertJsonPath('data.display_name', "Jos\u{00E9} Cashier");
});

it('saves a decomposed accent in display_name as the precomposed form', function () {
    $role = Role::create(['name' => 'decomposed', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => "Jose\u{0301} Cashier"])
        ->assertSuccessful()
        ->assertJsonPath('data.display_name', "Jos\u{00E9} Cashier");

    expect($role->fresh()->display_name)->toBe("Jos\u{00E9} Cashier");

    $this->postJson('/api/roles', ['name' => 'decomposed_two', 'display_name' => "Jose\u{0301} Teller"])
        ->assertCreated()
        ->assertJsonPath('data.display_name', "Jos\u{00E9} Teller");

    expect(Role::where('name', 'decomposed_two')->value('display_name'))->toBe("Jos\u{00E9} Teller");
});

it('refuses invalid UTF-8 in a form-encoded display_name with 422, not 500', function () {
    $role = Role::create(['name' => 'bytes_role', 'guard_name' => 'web', 'is_system' => false]);

    $this->put("/api/roles/{$role->id}", ['display_name' => "Admin\xFF"], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    $this->post('/api/roles', ['name' => 'bytes_role_two', 'display_name' => "Desk\xC3("], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    expect($role->fresh()->display_name)->toBeNull()
        ->and(Role::where('name', 'bytes_role_two')->exists())->toBeFalse();
});

it('rejects a new role key that reads as another role full-width display_name', function () {
    Role::where('name', 'cashier')->firstOrFail()->forceFill(['display_name' => "\u{FF34}\u{FF45}\u{FF4C}\u{FF4C}\u{FF45}\u{FF52}"])->save();

    $this->postJson('/api/roles', ['name' => 'teller'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rejects a new role key that normalises to an existing key', function () {
    Role::create(['name' => 'head_teller', 'guard_name' => 'web', 'is_system' => false]);

    $this->postJson('/api/roles', ['name' => 'head__teller'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect(Role::where('name', 'head__teller')->exists())->toBeFalse();
});

/**
 * Run `$land` once, right after the form request's role lookup (the last
 * read before the controller opens its transaction), so a competing write
 * lands deterministically in the window between validation and the write.
 */
function landAfterRoleValidation(Closure $land): Closure
{
    $landed = false;
    DB::listen(function ($query) use (&$landed, $land) {
        $sql = strtolower($query->sql);

        if (! $landed && str_contains($sql, 'select `id`, `name`, `display_name` from `roles`') && ! str_contains($sql, 'for update')) {
            $landed = true;
            $land();
        }
    });

    return function () use (&$landed) {
        return $landed;
    };
}

it('refuses a new key when another role takes it as a label between validation and the write', function () {
    $landed = landAfterRoleValidation(fn () => DB::table('roles')->where('name', 'cashier')->update(['display_name' => 'Teller']));

    $this->postJson('/api/roles', ['name' => 'teller'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['name' => ['The name is already used by another role.']]);

    expect($landed())->toBeTrue('the competing relabel never landed, so this test proved nothing')
        ->and(Role::where('name', 'teller')->exists())->toBeFalse();
});

it('refuses a label when another role takes it as a key between validation and the write', function () {
    $cashier = Role::where('name', 'cashier')->firstOrFail();
    $landed = landAfterRoleValidation(fn () => DB::table('roles')->insert([
        'name' => 'teller', 'guard_name' => 'web', 'is_system' => false, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->putJson("/api/roles/{$cashier->id}", ['display_name' => 'Teller'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name'])
        ->assertJsonFragment(['display_name' => ['The display name is already used by another role.']]);

    expect($landed())->toBeTrue('the competing create never landed, so this test proved nothing')
        ->and($cashier->fresh()->display_name)->toBeNull();
});
