<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guards around switching a role off and on.
 *
 *  - admin and super_admin always count as active, even if their row says
 *    otherwise, so hasRole() and the permission checks never disagree.
 *  - Reactivating re-grants the role's permissions to everyone holding it, so
 *    an actor who isn't a super_admin can't reactivate a role carrying a
 *    permission they don't hold.
 *  - Nobody deactivates a role they hold themselves.
 */
class RoleActivationGuardsTest extends TestCase
{
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::where('username', 'super_admin')->firstOrFail();
        $this->actingAs($this->superAdmin);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function role(string $name, array $permissions, bool $active = true): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web', 'is_system' => false, 'is_active' => $active]);
        $role->syncPermissions($permissions);

        return $role;
    }

    /**
     * A role desk operator: may switch roles on and off, holds little else.
     */
    private function roleDesk(): User
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('role_desk', ['settings:view', 'settings:update', 'dashboard:view']));

        return $user;
    }

    public function test_admin_counts_as_active_even_when_its_row_is_switched_off(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'admin')->firstOrFail());

        // Not reachable through the API (admin is protected); a hand edit.
        DB::table('roles')->where('name', 'admin')->update(['is_active' => false]);

        $user = User::findOrFail($user->id);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->activeRoles()->contains('name', 'admin'));
        $this->assertTrue($user->hasPermissionTo('loans:view'));

        $this->actingAs($user)->getJson('/api/loans')->assertOk();
        $this->assertContains('loans:view', $this->actingAs($user)->getJson('/api/auth/me')->json('data.permissions'));
    }

    public function test_super_admin_counts_as_active_even_when_its_row_is_switched_off(): void
    {
        DB::table('roles')->where('name', 'super_admin')->update(['is_active' => false]);

        $user = User::findOrFail($this->superAdmin->id);
        $this->assertTrue($user->activeRoles()->contains('name', 'super_admin'));
        $this->assertContains('users:view', $user->getAllPermissions()->pluck('name')->all());
    }

    public function test_an_actor_cannot_reactivate_a_role_carrying_a_permission_they_do_not_hold(): void
    {
        $actor = $this->roleDesk();
        $role = $this->role('loan_viewer_desk', ['dashboard:view', 'loans:view'], active: false);

        $this->actingAs($actor)
            ->patchJson("/api/roles/{$role->id}/reactivate")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role' => 'You can\'t grant permissions you do not hold yourself (loans:view).']);

        $this->assertFalse(Role::findOrFail($role->id)->is_active);
    }

    public function test_an_actor_can_reactivate_a_role_carrying_only_permissions_they_hold(): void
    {
        $actor = $this->roleDesk();
        $role = $this->role('dashboard_desk', ['dashboard:view'], active: false);

        $this->actingAs($actor)
            ->patchJson("/api/roles/{$role->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_a_super_admin_can_reactivate_any_role(): void
    {
        $role = $this->role('loan_viewer_desk', ['dashboard:view', 'loans:view'], active: false);

        $this->patchJson("/api/roles/{$role->id}/reactivate")->assertOk();

        $this->assertTrue(Role::findOrFail($role->id)->is_active);
    }

    public function test_an_actor_cannot_deactivate_a_role_they_hold(): void
    {
        $actor = $this->roleDesk();
        $role = Role::where('name', 'role_desk')->firstOrFail();

        $this->actingAs($actor)
            ->patchJson("/api/roles/{$role->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role' => 'You can\'t deactivate a role you hold. Another administrator has to do it for you.']);

        $this->assertTrue(Role::findOrFail($role->id)->is_active);
        $this->assertTrue(User::findOrFail($actor->id)->hasPermissionTo('settings:update'));
    }

    public function test_an_actor_can_deactivate_a_role_they_do_not_hold(): void
    {
        $actor = $this->roleDesk();
        $role = $this->role('dashboard_desk', ['dashboard:view']);

        $this->actingAs($actor)->patchJson("/api/roles/{$role->id}/deactivate")->assertOk();

        $this->assertFalse(Role::findOrFail($role->id)->is_active);
    }
}
