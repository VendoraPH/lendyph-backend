<?php

use App\Models\Role;
use App\Rules\UniqueRoleDisplayName;
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
 *   (`created`, the list in its new values). The display name gets the
 *   check a role save gets (UniqueRoleDisplayName): when another role
 *   already reads as "Accountant", the role is created with no display name
 *   and its audit row says so. INSERT-only, as in
 *   2026_09_16_110001_add_approval_chain_roles: a role an admin already built
 *   under that key is left exactly as it is, permissions included, with one
 *   system audit row naming it (`role_kept_by_migration`). Nobody is given
 *   the role; the owner assigns it in Users.
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

    private const KEPT_ACTION = 'role_kept_by_migration';

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
        'gcash:view', 'fees:view',
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
        $existing = DB::table('roles')->where('name', self::ROLE)->where('guard_name', $guard)->first(['id', 'name', 'display_name', 'is_system']);

        if ($existing !== null) {
            if (! (bool) $existing->is_system) {
                $this->recordTheKeptRole($existing);
            }

            return;
        }

        $this->ensurePermissions(self::ACCOUNTANT_PERMISSIONS, $guard);

        $now = now();
        $clash = $this->roleReadingAs(self::ROLE_ATTRIBUTES['display_name']);
        $attributes = [...self::ROLE_ATTRIBUTES, 'display_name' => $clash === null ? self::ROLE_ATTRIBUTES['display_name'] : null];

        $roleId = DB::table('roles')->insertGetId([
            'name' => self::ROLE,
            'guard_name' => $guard,
            ...$attributes,
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
                ...$attributes,
                'permissions' => array_values($permissions),
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => null,
            'user_agent' => null,
            'description' => sprintf(
                'System change: Role %s (%s) created by migration %s with %d permissions. No user is given it; the owner assigns it.%s',
                json_encode($attributes['display_name'] ?? self::ROLE, JSON_UNESCAPED_UNICODE),
                self::ROLE,
                self::NAME,
                count($permissions),
                $clash === null ? '' : sprintf(
                    ' Its display name "%s" was left empty: role %s (%s) already reads as it. Set one in Settings > User Roles.',
                    self::ROLE_ATTRIBUTES['display_name'],
                    json_encode($clash->display_name ?? $clash->name, JSON_UNESCAPED_UNICODE),
                    $clash->name,
                ),
            ),
            'created_at' => $now,
        ]);

        if ($clash !== null) {
            Log::warning('The Accountant role was created with no display name; another role already reads as "Accountant".', [
                'migration' => self::NAME,
                'role_id' => $roleId,
                'clashing_role' => $clash->name,
            ]);
        }
    }

    /**
     * The role whose key or display name already reads as `$displayName`,
     * by the same comparison a role save makes (UniqueRoleDisplayName), any
     * guard. Null when there is none.
     */
    private function roleReadingAs(string $displayName): ?object
    {
        foreach (DB::table('roles')->orderBy('id')->get(['id', 'name', 'display_name']) as $role) {
            if (UniqueRoleDisplayName::readsAsAnotherRole([$role], $displayName)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * A role keyed `accountant` that this migration didn't create: left as
     * it is, and named once in the audit log so the owner knows the Accountant
     * role wasn't created. A re-run writes no second row.
     */
    private function recordTheKeptRole(object $role): void
    {
        $recorded = DB::table('audit_logs')
            ->where('action', self::KEPT_ACTION)
            ->where('auditable_type', self::ROLE_TYPE)
            ->where('auditable_id', $role->id)
            ->exists();

        if ($recorded) {
            return;
        }

        $label = filled($role->display_name) ? trim((string) $role->display_name) : $role->name;

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => self::KEPT_ACTION,
            'auditable_type' => self::ROLE_TYPE,
            'auditable_id' => $role->id,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => null,
            'user_agent' => null,
            'description' => sprintf(
                'System change: Role %s (%s) already existed and was left as it is, permissions included; migration %s did not create the Accountant role or change this one.',
                json_encode($label, JSON_UNESCAPED_UNICODE),
                $role->name,
                self::NAME,
            ),
            'created_at' => now(),
        ]);

        Log::warning('A role keyed accountant already exists; it was left as it is, permissions included.', [
            'migration' => self::NAME,
            'role_id' => (int) $role->id,
        ]);
    }

    private function forgetCachedPermissions(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
