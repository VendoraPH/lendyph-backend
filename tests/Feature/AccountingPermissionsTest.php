<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Who may do what in accounting.
 *
 * The split that matters is `general_bookkeeper`: it drafts entries, records
 * expenses and reconciles accounts, and holds NONE of `journals:post`,
 * `journals:reverse` or `accounting:close`. Drafting is reversible and nothing
 * reaches the ledger until somebody else posts it; posting makes an entry
 * immutable, reversing writes a second entry against it, and closing locks a
 * period. Preparer and approver being different people is a control, not a
 * convention, and granting any of those three to the role that prepares the
 * work would quietly remove it.
 *
 * Both grant paths are covered, because they are genuinely different code: a
 * fresh database gets these from RoleAndPermissionSeeder, while staging and
 * production are already migrated and will never re-run a seeder — they get
 * them only from the migration. Testing one would leave the other free to drift.
 */
class AccountingPermissionsTest extends TestCase
{
    use SetupLendyPH;

    /** The whole accounting vocabulary. Seventeen strings, none of them optional. */
    private const ACCOUNTING_PERMISSIONS = [
        'accounting:view',
        'accounting:reconcile',
        'accounting:close',
        'accounting:settings',
        'chart_of_accounts:view',
        'chart_of_accounts:create',
        'chart_of_accounts:update',
        'chart_of_accounts:delete',
        'journals:view',
        'journals:create',
        'journals:post',
        'journals:reverse',
        'expenses:view',
        'expenses:create',
        'expenses:update',
        'cash_accounts:view',
        'cash_accounts:transfer',
    ];

    /** Draft, record, reconcile. Never post, reverse or close. */
    private const BOOKKEEPER_PERMISSIONS = [
        'accounting:view',
        'accounting:reconcile',
        'chart_of_accounts:view',
        'journals:view',
        'journals:create',
        'expenses:view',
        'expenses:create',
        'cash_accounts:view',
    ];

    /** A branch manager looks at the books. They do not keep them. */
    private const MANAGER_PERMISSIONS = [
        'accounting:view',
        'chart_of_accounts:view',
        'journals:view',
        'expenses:view',
        'cash_accounts:view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_every_accounting_permission_exists(): void
    {
        foreach (self::ACCOUNTING_PERMISSIONS as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists(),
                "The {$permission} permission was never created.",
            );
        }

        $this->assertCount(17, self::ACCOUNTING_PERMISSIONS);
    }

    public function test_admin_and_super_admin_hold_all_seventeen(): void
    {
        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();

            foreach (self::ACCOUNTING_PERMISSIONS as $permission) {
                $this->assertTrue(
                    $role->hasPermissionTo($permission),
                    "Expected {$roleName} to hold {$permission}.",
                );
            }
        }
    }

    public function test_the_bookkeeper_prepares_but_cannot_approve(): void
    {
        $role = Role::query()->where('name', 'general_bookkeeper')->firstOrFail();

        foreach (self::BOOKKEEPER_PERMISSIONS as $permission) {
            $this->assertTrue($role->hasPermissionTo($permission), "Expected general_bookkeeper to hold {$permission}.");
        }

        foreach (['journals:post', 'journals:reverse', 'accounting:close'] as $permission) {
            $this->assertFalse(
                $role->hasPermissionTo($permission),
                "general_bookkeeper must NOT hold {$permission} — the preparer cannot also be the approver.",
            );
        }
    }

    public function test_the_bookkeeper_holds_no_accounting_permission_beyond_its_eight(): void
    {
        $role = Role::query()->where('name', 'general_bookkeeper')->firstOrFail();

        $held = array_values(array_intersect(
            self::ACCOUNTING_PERMISSIONS,
            $role->permissions()->pluck('name')->all(),
        ));

        $this->assertSame(
            array_values(array_intersect(self::ACCOUNTING_PERMISSIONS, self::BOOKKEEPER_PERMISSIONS)),
            $held,
        );
    }

    /**
     * `manager` is being added by separate work and may not exist on this
     * branch yet. When it does not, the role is created here and the migration
     * is re-run over it — which is exactly the staging and production ordering
     * (roles first, migration second) that `migrate:fresh` never exercises,
     * because there migrations run before any role exists.
     */
    public function test_the_manager_is_read_only(): void
    {
        $role = Role::query()->where('name', 'manager')->where('guard_name', 'web')->first();

        if ($role === null) {
            $role = Role::query()->create([
                'name' => 'manager',
                'guard_name' => 'web',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Branch manager — placeholder created by this test while the role is still being added.',
            ]);

            $this->accountingPermissionMigration()->up();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $role = $role->fresh();
        }

        foreach (self::MANAGER_PERMISSIONS as $permission) {
            $this->assertTrue($role->hasPermissionTo($permission), "Expected manager to hold {$permission}.");
        }

        $writes = array_values(array_diff(self::ACCOUNTING_PERMISSIONS, self::MANAGER_PERMISSIONS));

        foreach ($writes as $permission) {
            $this->assertFalse(
                $role->hasPermissionTo($permission),
                "manager must NOT hold {$permission} — the role is read-only accounting.",
            );
        }
    }

    public function test_no_operational_role_picks_up_accounting_by_accident(): void
    {
        foreach (['loan_officer', 'cashier', 'collector', 'viewer'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();

            foreach (self::ACCOUNTING_PERMISSIONS as $permission) {
                $this->assertFalse(
                    $role->hasPermissionTo($permission),
                    "{$roleName} must NOT hold {$permission}.",
                );
            }
        }
    }

    /**
     * The staging and production path: those boxes already have their roles
     * when the migration lands, so the migration's own grant loop is what does
     * the work.
     */
    public function test_the_migration_grants_the_permissions_when_the_roles_already_exist(): void
    {
        $this->accountingPermissionMigration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(
            0,
            Permission::query()->whereIn('name', self::ACCOUNTING_PERMISSIONS)->count(),
            'down() must remove every accounting permission it created.',
        );

        $this->accountingPermissionMigration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ACCOUNTING_PERMISSIONS as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists(),
                "The migration must recreate {$permission}.",
            );
        }

        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $this->assertTrue($role->hasPermissionTo('journals:post'), "Expected the migration to grant journals:post to {$roleName}.");
        }

        $bookkeeper = Role::query()->where('name', 'general_bookkeeper')->firstOrFail();
        $this->assertTrue($bookkeeper->hasPermissionTo('journals:create'));
        $this->assertFalse($bookkeeper->hasPermissionTo('journals:post'));
    }

    /**
     * `updateOrInsert` plus existence-checked grants, so re-running over a box
     * that already has them is a no-op rather than a failed deploy or a
     * duplicated pivot row.
     */
    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $this->accountingPermissionMigration()->up();
        $this->accountingPermissionMigration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ACCOUNTING_PERMISSIONS as $permission) {
            $this->assertSame(1, Permission::query()->where('name', $permission)->count(), "{$permission} was created twice.");
        }

        $adminId = Role::query()->where('name', 'admin')->value('id');
        $permissionId = Permission::query()->where('name', 'journals:post')->value('id');

        $this->assertSame(
            1,
            DB::table('role_has_permissions')
                ->where('role_id', $adminId)
                ->where('permission_id', $permissionId)
                ->count(),
        );
    }

    /**
     * The seeder's canonical list has to carry them too. The migration alone
     * would leave a freshly built database depending on migration order for
     * permissions the seeder claims to define — the drift `collaterals:*`
     * already has.
     */
    public function test_the_seeder_lists_every_accounting_permission(): void
    {
        $seeder = file_get_contents(database_path('seeders/RoleAndPermissionSeeder.php'));

        foreach (self::ACCOUNTING_PERMISSIONS as $permission) {
            $this->assertStringContainsString("'{$permission}'", $seeder, "RoleAndPermissionSeeder must list {$permission}.");
        }
    }

    private function accountingPermissionMigration(): object
    {
        return require database_path('migrations/2026_09_16_200002_add_accounting_permissions.php');
    }
}
