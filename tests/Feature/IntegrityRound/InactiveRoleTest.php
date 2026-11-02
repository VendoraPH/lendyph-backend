<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Item 16: a role's `is_active` is stored and shown but never enforced.
 * PATCH /roles/{id}/deactivate changes nothing about access: users holding
 * the role keep every permission it carries, and the role can still be given
 * to new or existing users.
 *
 * Pinned (plan decision 7, option a): a deactivated role grants nothing (its
 * users keep the assignment, so reactivating restores access), its
 * permissions leave the signed-in user's permission list, and assigning it is
 * a 422 on `role`.
 */
class InactiveRoleTest extends TestCase
{
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::where('username', 'super_admin')->firstOrFail();
        $this->actingAs($this->superAdmin);
    }

    private function deactivatedViewerRole(): Role
    {
        $role = Role::create(['name' => 'loan_viewer_desk', 'guard_name' => 'web', 'is_system' => false, 'is_active' => true]);
        $role->syncPermissions(['loans:view']);

        return $role;
    }

    public function test_a_deactivated_role_no_longer_grants_its_permissions(): void
    {
        $role = $this->deactivatedViewerRole();
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->getJson('/api/loans')->assertOk();

        $this->actingAs($this->superAdmin)->patchJson("/api/roles/{$role->id}/deactivate")->assertSuccessful();

        $user = User::findOrFail($user->id);
        $this->actingAs($user)->getJson('/api/loans')->assertForbidden();

        $permissions = $this->actingAs($user)->getJson('/api/auth/me')->assertOk()->json('data.permissions');
        $this->assertNotContains('loans:view', $permissions ?? []);
    }

    public function test_a_deactivated_role_cannot_be_given_to_a_new_user(): void
    {
        $role = $this->deactivatedViewerRole();
        $role->update(['is_active' => false]);

        $this->postJson('/api/users', [
            'first_name' => 'Ina',
            'last_name' => 'Reyes',
            'username' => 'ina_reyes',
            'email' => 'ina.reyes@example.test',
            'password' => 'Secret-pass-123',
            'password_confirmation' => 'Secret-pass-123',
            'branch_id' => Branch::firstOrFail()->id,
            'role' => $role->name,
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_a_deactivated_role_cannot_be_given_to_an_existing_user(): void
    {
        $role = $this->deactivatedViewerRole();
        $role->update(['is_active' => false]);
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'cashier')->firstOrFail());

        $this->putJson("/api/users/{$user->id}", ['role' => $role->name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }
}
