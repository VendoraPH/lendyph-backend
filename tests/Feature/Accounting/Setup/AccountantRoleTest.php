<?php

namespace Tests\Feature\Accounting\Setup;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Role;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The Accountant role and the `accounting:allowance` permission (Phase 2,
 * accounting round C, slice C1).
 *
 * - `accounting:allowance` posts the allowance for credit losses. Held by
 *   super_admin, admin and accountant only, so a bookkeeper or a manager can't
 *   post one.
 * - `accountant` keeps the books: every accounting permission, every branch,
 *   and read-only lending and report views. No `borrowers:view` (members'
 *   personal data) and no lending writes. No user gets it from a seeder or a
 *   migration; the owner assigns it.
 *
 * Both grant paths are covered: the seeder for a fresh database, the
 * migration for one already migrated.
 */
class AccountantRoleTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const MIGRATION = '2026_11_09_120000_add_accountant_role_and_allowance_permission';

    private const DESCRIPTION = 'Keeps the books: journals, expenses, reconciliation, period and year-end close, opening balances and the allowance. Assigned by the owner.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── The seeder (a fresh database) ──

    public function test_the_allowance_permission_exists_and_the_administrators_hold_it(): void
    {
        $this->assertTrue(Permission::query()->where('name', 'accounting:allowance')->where('guard_name', 'web')->exists());

        foreach (['super_admin', 'admin', 'accountant'] as $role) {
            $this->assertContains('accounting:allowance', $this->grants($role), "{$role} must hold accounting:allowance.");
        }
    }

    public function test_no_other_seeded_role_holds_the_allowance_permission(): void
    {
        $holders = Role::query()->where('guard_name', 'web')->get()
            ->filter(fn (Role $role) => in_array('accounting:allowance', $this->grants($role->name), true))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['accountant', 'admin', 'super_admin'], $holders);

        foreach (['general_bookkeeper', 'manager', 'loan_officer', 'cashier', 'collector', 'viewer', 'loan_processor', 'bod1', 'bod7'] as $role) {
            $this->assertNotContains('accounting:allowance', $this->grants($role), "{$role} must not hold accounting:allowance.");
        }
    }

    public function test_the_accountant_is_a_system_role_with_exactly_its_list(): void
    {
        $role = Role::findByName('accountant');

        $this->assertTrue($role->is_system);
        $this->assertTrue($role->is_active);
        $this->assertSame('Accountant', $role->display_name);
        $this->assertSame(self::DESCRIPTION, $role->description);
        $this->assertSame($this->sorted(RoleAndPermissionSeeder::ACCOUNTANT_PERMISSIONS), $this->grants('accountant'));
    }

    public function test_the_accountant_list_is_the_one_decided(): void
    {
        $this->assertSame($this->sorted([
            'accounting:view', 'accounting:reconcile', 'accounting:close', 'accounting:settings', 'accounting:allowance',
            'chart_of_accounts:view', 'chart_of_accounts:create', 'chart_of_accounts:update', 'chart_of_accounts:delete',
            'journals:view', 'journals:create', 'journals:post', 'journals:reverse',
            'expenses:view', 'expenses:create', 'expenses:update', 'expenses:pay',
            'cash_accounts:view', 'cash_accounts:transfer',
            'branches:view_all',
            'dashboard:view', 'reports:view', 'reports:export',
            'loans:view', 'payments:view', 'loan_adjustments:view', 'share_capital:view',
            'gcash:view', 'fees:view', 'audit_logs:view',
        ]), $this->sorted(RoleAndPermissionSeeder::ACCOUNTANT_PERMISSIONS));

        foreach (['borrowers:view', 'loans:create', 'loans:update', 'payments:create', 'users:view', 'settings:update'] as $permission) {
            $this->assertNotContains($permission, RoleAndPermissionSeeder::ACCOUNTANT_PERMISSIONS);
        }
    }

    public function test_the_seeder_gives_nobody_the_accountant_role(): void
    {
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', Role::findByName('accountant')->id)->count());
    }

    public function test_the_bookkeeper_and_the_manager_are_unchanged(): void
    {
        $this->assertNotContains('journals:post', $this->grants('general_bookkeeper'));
        $this->assertNotContains('accounting:close', $this->grants('general_bookkeeper'));
        $this->assertNotContains('journals:create', $this->grants('manager'));
    }

    // ── The migration (an already-migrated database) ──

    public function test_the_migration_adds_the_permission_and_the_role_with_one_audit_row_each(): void
    {
        $this->forgetRoleAndPermission();
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertContains('accounting:allowance', $this->grants('super_admin'));
        $this->assertContains('accounting:allowance', $this->grants('admin'));
        $this->assertNotContains('accounting:allowance', $this->grants('general_bookkeeper'));
        $this->assertNotContains('accounting:allowance', $this->grants('manager'));

        $role = Role::findByName('accountant');
        $this->assertTrue($role->is_system);
        $this->assertTrue($role->is_active);
        $this->assertSame('Accountant', $role->display_name);
        $this->assertSame(self::DESCRIPTION, $role->description);
        $this->assertSame($this->sorted(RoleAndPermissionSeeder::ACCOUNTANT_PERMISSIONS), $this->grants('accountant'));
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', $role->id)->count());

        $rows = AuditLog::query()->where('id', '>', $before)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());

        $grants = $rows->where('action', 'role_permissions_changed')->values();
        $this->assertEqualsCanonicalizing(
            [Role::findByName('super_admin')->id, Role::findByName('admin')->id],
            $grants->pluck('auditable_id')->map(fn ($id) => (int) $id)->all(),
        );
        foreach ($grants as $row) {
            $this->assertSame(Role::class, $row->auditable_type);
            $this->assertStringContainsString(self::MIGRATION, (string) $row->description);
            $this->assertSame(['accounting:allowance'], array_values(array_diff($row->new_values['permissions'], $row->old_values['permissions'])));
            $this->assertSame([], array_values(array_diff($row->old_values['permissions'], $row->new_values['permissions'])));
        }

        $created = $rows->where('action', 'created')->sole();
        $this->assertSame(Role::class, $created->auditable_type);
        $this->assertSame($role->id, (int) $created->auditable_id);
        $this->assertSame('accountant', $created->new_values['name']);
        $this->assertSame($this->sorted(RoleAndPermissionSeeder::ACCOUNTANT_PERMISSIONS), $created->new_values['permissions']);
        $this->assertStringContainsString(self::MIGRATION, (string) $created->description);
    }

    public function test_a_second_run_changes_nothing_and_writes_nothing(): void
    {
        $this->forgetRoleAndPermission();
        Auth::logout();
        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $audits = AuditLog::query()->count();
        $pivot = DB::table('role_has_permissions')->count();
        $roles = DB::table('roles')->count();
        $permissions = DB::table('permissions')->count();

        $this->migration()->up();

        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertSame($pivot, DB::table('role_has_permissions')->count());
        $this->assertSame($roles, DB::table('roles')->count());
        $this->assertSame($permissions, DB::table('permissions')->count());
    }

    public function test_the_migration_over_the_seeded_database_writes_nothing(): void
    {
        $audits = AuditLog::query()->count();
        $pivot = DB::table('role_has_permissions')->count();

        $this->migration()->up();

        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertSame($pivot, DB::table('role_has_permissions')->count());
    }

    public function test_an_existing_accountant_role_is_left_as_it_is(): void
    {
        $this->forgetRoleAndPermission();
        $custom = Role::query()->create(['name' => 'accountant', 'guard_name' => 'web', 'description' => 'Built by hand', 'is_system' => false, 'is_active' => false]);
        $custom->givePermissionTo('journals:view');
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = $custom->fresh();
        $this->assertFalse($role->is_system);
        $this->assertFalse($role->is_active);
        $this->assertSame('Built by hand', $role->description);
        $this->assertSame(['journals:view'], $this->grants('accountant'));
        $this->assertSame(0, AuditLog::query()->where('id', '>', $before)->where('auditable_id', $role->id)->count());
    }

    // ── What an Accountant can do ──

    public function test_the_accountant_keeps_the_books(): void
    {
        $this->seedChartOfAccounts();
        $this->actingAs($this->userWithRole('accountant'));

        $this->getJson('/api/accounting/journals')->assertOk();

        $id = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Electricity for September',
            'branch_id' => $this->branch->id,
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 350000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 350000],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/accounting/journals/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->getJson('/api/accounting/periods?per_page=100')->assertOk();
        $period = AccountingPeriod::query()->where('code', '2026-09')->sole();
        $this->postJson("/api/accounting/periods/{$period->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->getJson('/api/reports/income')->assertOk();
        $this->getJson('/api/accounting/trial-balance')->assertOk();
    }

    public function test_the_accountant_cannot_read_members_or_write_loans(): void
    {
        $this->actingAs($this->userWithRole('accountant'));

        $this->getJson('/api/borrowers')->assertForbidden();
        $this->postJson('/api/loans', [])->assertForbidden();
    }

    // ── Helpers ──

    /**
     * A database migrated before this change: no `accounting:allowance`, no
     * `accountant` role.
     */
    private function forgetRoleAndPermission(): void
    {
        $roleId = DB::table('roles')->where('name', 'accountant')->value('id');

        if ($roleId !== null) {
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        $permissionId = DB::table('permissions')->where('name', 'accounting:allowance')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function grants(string $role): array
    {
        $id = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');

        if ($id === null) {
            return [];
        }

        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $id)
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return array_values($names);
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }
}
