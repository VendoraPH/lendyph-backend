<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class RoleGCashAndAutoPayPermissionsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_six_new_permissions_exist_after_migrations(): void
    {
        $expected = [
            'gcash:view', 'gcash:transact', 'gcash:settings',
            'auto_pay:view', 'auto_pay:process', 'auto_pay:toggle',
        ];

        foreach ($expected as $name) {
            $this->assertNotNull(
                Permission::where('name', $name)->where('guard_name', 'web')->first(),
                "Expected permission '{$name}' to exist after migrations + seeders",
            );
        }
    }

    public function test_admin_role_seed_includes_all_six_new_perms(): void
    {
        $admin = Role::where('name', 'admin')->firstOrFail();

        foreach (['gcash:view', 'gcash:transact', 'gcash:settings', 'auto_pay:view', 'auto_pay:process', 'auto_pay:toggle'] as $perm) {
            $this->assertTrue(
                $admin->hasPermissionTo($perm),
                "Expected admin role to have '{$perm}'",
            );
        }
    }

    public function test_cashier_role_has_gcash_view_and_transact(): void
    {
        $cashier = Role::where('name', 'cashier')->firstOrFail();

        $this->assertTrue($cashier->hasPermissionTo('gcash:view'));
        $this->assertTrue($cashier->hasPermissionTo('gcash:transact'));
        // cashier does NOT get gcash:settings — that's admin-only.
        $this->assertFalse($cashier->hasPermissionTo('gcash:settings'));
    }

    public function test_role_update_accepts_full_admin_perm_payload_including_the_six_new_perms(): void
    {
        $admin = Role::where('name', 'admin')->firstOrFail();

        $response = $this->putJson("/api/roles/{$admin->id}", [
            'description' => 'Full access to all modules',
            'permissions' => [
                'dashboard:view',
                'borrowers:view', 'borrowers:create', 'borrowers:update', 'borrowers:delete',
                'loans:view', 'loans:create', 'loans:update', 'loans:delete',
                'loans:approve', 'loans:reject', 'loans:release',
                'payments:view', 'payments:create', 'payments:update', 'payments:void',
                'reports:view', 'reports:export',
                'settings:view', 'settings:update',
                'users:view', 'users:create', 'users:update', 'users:delete',
                'audit_logs:view', 'audit_logs:export',
                'share_capital:view', 'share_capital:create', 'share_capital:update',
                'gcash:view', 'gcash:transact', 'gcash:settings',
                'auto_pay:view', 'auto_pay:process', 'auto_pay:toggle',
            ],
        ]);

        $response->assertOk();
    }

    public function test_role_update_rejects_bogus_permission_name(): void
    {
        $admin = Role::where('name', 'admin')->firstOrFail();

        $this->putJson("/api/roles/{$admin->id}", [
            'permissions' => ['gcash:bogus'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_admin_user_me_endpoint_returns_the_six_new_perms(): void
    {
        // Admin role is granted to the super_admin user via the seeder; create
        // a fresh user assigned the `admin` role to exercise the non-super path.
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        $response = $this->getJson('/api/auth/me')->assertOk();
        $permissions = $response->json('data.permissions') ?? $response->json('permissions');
        $this->assertIsArray($permissions);

        foreach (['gcash:view', 'gcash:transact', 'gcash:settings', 'auto_pay:view', 'auto_pay:process', 'auto_pay:toggle'] as $perm) {
            $this->assertContains($perm, $permissions, "Expected /api/auth/me permissions to include '{$perm}'");
        }
    }
}
