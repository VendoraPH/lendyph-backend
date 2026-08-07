<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class UserManagementTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * $this->admin is the seeded *super_admin*. Anything about the role
     * boundary has to be acted out as someone else, or the Gate::before bypass
     * in AppServiceProvider makes every result meaningless.
     */
    private function userWithRole(string $role): User
    {
        return tap(
            User::factory()->create(['branch_id' => $this->branch->id]),
            fn (User $user) => $user->assignRole($role),
        );
    }

    public function test_list_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_create_user(): void
    {
        $response = $this->postJson('/api/users', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'johndoe',
            'email' => 'john@test.com',
            'mobile_number' => '09171234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $this->branch->id,
            'role' => 'loan_officer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.username', 'johndoe');
    }

    public function test_update_user(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('viewer');

        $response = $this->putJson("/api/users/{$user->id}", [
            'first_name' => 'Updated',
            'last_name' => $user->last_name,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.first_name', 'Updated');
    }

    public function test_deactivate_and_reactivate_user(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('viewer');

        $this->patchJson("/api/users/{$user->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);

        $this->patchJson("/api/users/{$user->id}/reactivate")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $viewer = User::factory()->create(['branch_id' => $this->branch->id]);
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);

        $response = $this->postJson('/api/users', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'username' => 'janedoe',
            'email' => 'jane@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $this->branch->id,
            'role' => 'viewer',
        ]);

        $response->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Privilege escalation: who may grant which role, and to whom
    // ---------------------------------------------------------------------

    public function test_an_admin_cannot_promote_another_user_to_super_admin(): void
    {
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('loan_officer');

        // The client-side admin role holds every permission, so this is not a
        // permission failure — the request is authorized and still refused.
        $this->assertTrue($admin->can('users:update'));
        $this->assertFalse($admin->hasRole('super_admin'));

        $this->actingAs($admin)
            ->putJson("/api/users/{$target->id}", ['role' => 'super_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertSame('loan_officer', $target->fresh()->getRoleNames()->first());
    }

    public function test_an_admin_cannot_create_a_new_super_admin_account(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'first_name' => 'Back',
                'last_name' => 'Door',
                'username' => 'backdoor',
                'email' => 'backdoor@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'branch_id' => $this->branch->id,
                'role' => 'super_admin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseMissing('users', ['username' => 'backdoor']);
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        // Demotion, not promotion: `viewer` is a role this admin is perfectly
        // entitled to hand to anyone else, so only the self-edit guard can be
        // what rejects this.
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/users/{$admin->id}", ['role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertSame('admin', $admin->fresh()->getRoleNames()->first());
    }

    public function test_an_admin_cannot_promote_themselves_to_super_admin(): void
    {
        // The reported attack in one request: PUT your own record with
        // {"role":"super_admin"}, self-approve a ₱1 restructure, roll back.
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/users/{$admin->id}", ['role' => 'super_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertFalse($admin->fresh()->hasRole('super_admin'));
    }

    public function test_a_super_admin_cannot_change_their_own_role_either(): void
    {
        // The boundary is only checkable if the account it constrains cannot
        // edit it, so the rule holds even for the account that outranks it.
        $this->putJson("/api/users/{$this->admin->id}", ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertTrue($this->admin->fresh()->hasRole('super_admin'));
    }

    // ---------------------------------------------------------------------
    // Taking over the super_admin account without changing any role
    // ---------------------------------------------------------------------

    public function test_an_admin_cannot_demote_a_super_admin(): void
    {
        // Mirror image of the promotion rule: a client admin who could strip
        // the platform team's role would remove the only account able to give
        // it back.
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/users/{$this->admin->id}", ['role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $this->assertTrue($this->admin->fresh()->hasRole('super_admin'));
    }

    public function test_an_admin_cannot_edit_a_super_admins_record_at_all(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/users/{$this->admin->id}", ['email' => 'attacker@evil.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $this->assertNotSame('attacker@evil.test', $this->admin->fresh()->email);
    }

    public function test_an_admin_cannot_reset_a_super_admins_password(): void
    {
        // The role guards are worthless if the platform account can simply be
        // seized: reset its password, log in as it, self-approve a restructure.
        $admin = $this->userWithRole('admin');
        $originalHash = $this->admin->password;

        $this->assertTrue($admin->can('users:reset_password'));

        $this->actingAs($admin)
            ->postJson("/api/users/{$this->admin->id}/reset-password", [
                'password' => 'pwned12345',
                'password_confirmation' => 'pwned12345',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $this->assertSame($originalHash, $this->admin->fresh()->password);
    }

    public function test_an_admin_cannot_deactivate_a_super_admin(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->patchJson("/api/users/{$this->admin->id}/deactivate")
            ->assertUnprocessable();

        $this->assertSame('active', $this->admin->fresh()->status);
    }

    public function test_an_admin_can_still_reset_and_deactivate_ordinary_accounts(): void
    {
        // The boundary is only about super_admin targets; day-to-day staff
        // administration has to keep working.
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('cashier');
        $originalHash = $target->password;

        $this->actingAs($admin)
            ->postJson("/api/users/{$target->id}/reset-password", [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertOk();

        $this->assertNotSame($originalHash, $target->fresh()->password);

        $this->actingAs($admin)
            ->patchJson("/api/users/{$target->id}/deactivate")
            ->assertOk();

        $this->assertSame('inactive', $target->fresh()->status);
    }

    public function test_a_super_admin_can_still_reset_another_super_admins_password(): void
    {
        $otherPlatformUser = $this->userWithRole('super_admin');
        $originalHash = $otherPlatformUser->password;

        $this->postJson("/api/users/{$otherPlatformUser->id}/reset-password", [
            'password' => 'rotated12345',
            'password_confirmation' => 'rotated12345',
        ])->assertOk();

        $this->assertNotSame($originalHash, $otherPlatformUser->fresh()->password);
    }

    public function test_a_super_admin_can_still_assign_ordinary_roles_to_other_users(): void
    {
        $target = $this->userWithRole('viewer');

        $this->putJson("/api/users/{$target->id}", ['role' => 'cashier'])
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'cashier');

        $this->assertSame('cashier', $target->fresh()->getRoleNames()->first());
    }

    public function test_an_admin_can_still_assign_ordinary_roles_to_other_users(): void
    {
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)
            ->putJson("/api/users/{$target->id}", ['role' => 'loan_officer'])
            ->assertOk();

        $this->assertSame('loan_officer', $target->fresh()->getRoleNames()->first());
    }

    public function test_an_operator_cannot_grant_a_role_carrying_permissions_they_lack(): void
    {
        // Custom roles from POST /api/roles can carry any permission in the
        // registry, so `users:update` alone must not become a way to hand out
        // more than you were given.
        $branchAdmin = Role::create(['name' => 'branch_admin', 'guard_name' => 'web', 'is_system' => false]);
        $branchAdmin->syncPermissions(['users:view', 'users:update', 'loans:view']);

        $operator = tap(
            User::factory()->create(['branch_id' => $this->branch->id]),
            fn (User $user) => $user->assignRole($branchAdmin),
        );
        $target = $this->userWithRole('viewer');

        $this->actingAs($operator)
            ->putJson("/api/users/{$target->id}", ['role' => 'loan_officer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertSame('viewer', $target->fresh()->getRoleNames()->first());
    }

    public function test_an_operator_can_grant_a_role_within_their_own_permissions(): void
    {
        // The other half of the rule above: it restricts, it does not freeze.
        $branchAdmin = Role::create(['name' => 'branch_admin', 'guard_name' => 'web', 'is_system' => false]);
        $branchAdmin->syncPermissions(['users:view', 'users:update', 'loans:view']);

        $desk = Role::create(['name' => 'read_only_desk', 'guard_name' => 'web', 'is_system' => false]);
        $desk->syncPermissions(['loans:view']);

        $operator = tap(
            User::factory()->create(['branch_id' => $this->branch->id]),
            fn (User $user) => $user->assignRole($branchAdmin),
        );
        $target = $this->userWithRole('viewer');

        $this->actingAs($operator)
            ->putJson("/api/users/{$target->id}", ['role' => 'read_only_desk'])
            ->assertOk();

        $this->assertSame('read_only_desk', $target->fresh()->getRoleNames()->first());
    }

    // ---------------------------------------------------------------------
    // Audit trail
    // ---------------------------------------------------------------------

    public function test_a_role_change_writes_an_audit_entry_with_the_old_and_new_role(): void
    {
        // A role-only payload leaves the users row non-dirty, so the Auditable
        // trait never fires and promotions used to vanish entirely.
        $target = $this->userWithRole('viewer');

        $this->putJson("/api/users/{$target->id}", ['role' => 'cashier'])->assertOk();

        $log = AuditLog::where('action', 'role_changed')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'No role_changed audit entry was written.');
        $this->assertSame((int) $this->admin->id, (int) $log->user_id, 'The actor was not recorded.');
        $this->assertSame(['role' => 'viewer'], $log->old_values);
        $this->assertSame(['role' => 'cashier'], $log->new_values);
    }

    public function test_a_role_only_payload_is_not_silently_unaudited(): void
    {
        // Guards against a regression to `$user->update([])`: a payload with
        // nothing but `role` must still leave a trail.
        $target = $this->userWithRole('viewer');

        $this->putJson("/api/users/{$target->id}", ['role' => 'collector'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role_changed',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_creating_a_user_records_the_role_it_was_opened_with(): void
    {
        $this->postJson('/api/users', [
            'first_name' => 'Nina',
            'last_name' => 'Cruz',
            'username' => 'ninacruz',
            'email' => 'nina@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $this->branch->id,
            'role' => 'cashier',
        ])->assertCreated();

        $created = User::where('username', 'ninacruz')->firstOrFail();

        $log = AuditLog::where('action', 'role_assigned')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $created->id)
            ->first();

        $this->assertNotNull($log, 'No role_assigned audit entry was written.');
        $this->assertSame(['role' => 'cashier'], $log->new_values);
    }

    public function test_a_role_change_that_changes_nothing_writes_no_audit_entry(): void
    {
        $target = $this->userWithRole('viewer');

        $this->putJson("/api/users/{$target->id}", ['first_name' => 'Same', 'role' => 'viewer'])
            ->assertOk();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'role_changed',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // Self-service editing must keep working
    // ---------------------------------------------------------------------

    public function test_editing_your_own_name_and_email_still_works(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/users/{$admin->id}", [
                'first_name' => 'Renamed',
                'last_name' => 'Person',
                'email' => 'renamed@test.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Renamed')
            ->assertJsonPath('data.email', 'renamed@test.com');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'first_name' => 'Renamed',
            'email' => 'renamed@test.com',
        ]);
    }

    public function test_editing_your_own_record_while_echoing_back_your_current_role_still_works(): void
    {
        // The user form posts the whole record back, role included. Only an
        // actual change of role on your own account is refused.
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/users/{$admin->id}", [
                'first_name' => 'Still',
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Still');

        $this->assertSame('admin', $admin->fresh()->getRoleNames()->first());
    }
}
