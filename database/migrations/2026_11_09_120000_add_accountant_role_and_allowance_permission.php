<?php

use App\Models\Role;
use App\Services\RolePermissionAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Accountant role and the `accounting:allowance` permission (Phase 2,
 * accounting round C, slice C1).
 *
 * - `accounting:allowance` (posting the allowance for credit losses) is
 *   granted to super_admin and admin, one `role_permissions_changed` audit
 *   row each through RolePermissionAudit, with no user, so it reads as a
 *   system change. Mirrors 2026_11_09_100000_add_reminders_permissions.
 * - The `accountant` system role is created when it is missing, with its
 *   display name, description and permission list, and one system audit row
 *   (`created`, the list in its new values). INSERT-only, as in
 *   2026_09_16_110001_add_approval_chain_roles: a role an admin already built
 *   under that key is left exactly as it is, permissions included, and
 *   logged. Nobody is given the role; the owner assigns it in Users.
 *
 * Listed here AND in RoleAndPermissionSeeder (ACCOUNTANT_PERMISSIONS): this
 * migration is what deployed databases get, the seeder is what a fresh
 * database builds from. The list is spelled out rather than read off the
 * seeder, so this migration writes on its last deployment what it wrote on
 * its first. A permission in it that a deployment lacks is created first.
 *
 * Idempotent: insertOrIgnore throughout, a role that exists is never written,
 * and a re-run that changes no list writes no row.
 */
return new class extends Migration
{
    private const NAME = '2026_11_09_120000_add_accountant_role_and_allowance_permission';

    private const PERMISSION = 'accounting:allowance';

    private const GRANTED_TO = ['super_admin', 'admin'];

    private const ROLE = 'accountant';

    private const ROLE_TYPE = 'App\Models\Role';

    private const ROLE_ATTRIBUTES = [
        'display_name' => 'Accountant',
        'description' => 'Keeps the books: journals, expenses, reconciliation, period and year-end close, opening balances and the allowance. Assigned by the owner.',
        'is_system' => true,
        'is_active' => true,
    ];

    private const ACCOUNTANT_PERMISSIONS = [
        'accounting:view', 'accounting:reconcile', 'accounting:close', 'accounting:settings', 'accounting:allowance',
        'chart_of_accounts:view', 'chart_of_accounts:create', 'chart_of_accounts:update', 'chart_of_accounts:delete',
        'journals:view', 'journals:create', 'journals:post', 'journals:reverse',
        'expenses:view', 'expenses:create', 'expenses:update', 'expenses:pay',
        'cash_accounts:view', 'cash_accounts:transfer',
        'branches:view_all',
        'dashboard:view', 'reports:view', 'reports:export',
        'loans:view', 'payments:view', 'loan_adjustments:view', 'share_capital:view',
        'gcash:view', 'fees:view', 'audit_logs:view',
    ];

    public function up(): void
    {
        $guard = 'web';

        DB::transaction(function () use ($guard): void {
            $this->ensurePermissions([self::PERMISSION], $guard);
            $this->grantTheAdministrators($guard);
            $this->createTheAccountant($guard);
        });

        DB::afterCommit(fn () => $this->forgetCachedPermissions());
    }

    /**
     * Nothing to undo. Removing the role would revoke the access of whoever
     * the owner assigned it to, and each change is on record in the audit log.
     */
    public function down(): void {}

    /**
     * @param  list<string>  $names
     */
    private function ensurePermissions(array $names, string $guard): void
    {
        foreach ($names as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function grantTheAdministrators(string $guard): void
    {
        $permissionId = DB::table('permissions')
            ->where('guard_name', $guard)
            ->where('name', self::PERMISSION)
            ->value('id');

        $roles = Role::query()->where('guard_name', $guard)->whereIn('name', self::GRANTED_TO)->get()
            ->filter(fn (Role $role) => in_array($role->name, self::GRANTED_TO, true));

        foreach ($roles as $role) {
            $before = RolePermissionAudit::names($role);

            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);

            RolePermissionAudit::record(
                $role,
                $before,
                RolePermissionAudit::names($role),
                sprintf('Permissions for role %s changed by migration %s: added %s', $role->auditName(), self::NAME, self::PERMISSION),
            );
        }
    }

    private function createTheAccountant(string $guard): void
    {
        $existing = DB::table('roles')->where('name', self::ROLE)->where('guard_name', $guard)->first(['id', 'is_system']);

        if ($existing !== null) {
            if (! (bool) $existing->is_system) {
                Log::warning('A role keyed accountant already exists; it was left as it is, permissions included.', [
                    'migration' => self::NAME,
                    'role_id' => (int) $existing->id,
                ]);
            }

            return;
        }

        $this->ensurePermissions(self::ACCOUNTANT_PERMISSIONS, $guard);

        $now = now();

        $roleId = DB::table('roles')->insertGetId([
            'name' => self::ROLE,
            'guard_name' => $guard,
            ...self::ROLE_ATTRIBUTES,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionIds = DB::table('permissions')
            ->where('guard_name', $guard)
            ->whereIn('name', self::ACCOUNTANT_PERMISSIONS)
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        $permissions = self::ACCOUNTANT_PERMISSIONS;
        sort($permissions);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'created',
            'auditable_type' => self::ROLE_TYPE,
            'auditable_id' => $roleId,
            'old_values' => null,
            'new_values' => json_encode([
                'name' => self::ROLE,
                'guard_name' => $guard,
                ...self::ROLE_ATTRIBUTES,
                'permissions' => array_values($permissions),
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => null,
            'user_agent' => null,
            'description' => sprintf(
                'System change: Role "%s" (%s) created by migration %s with %d permissions. No user is given it; the owner assigns it.',
                self::ROLE_ATTRIBUTES['display_name'],
                self::ROLE,
                self::NAME,
                count($permissions),
            ),
            'created_at' => $now,
        ]);
    }

    private function forgetCachedPermissions(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
