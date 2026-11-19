<?php

use App\Models\Role;
use App\Rules\UniqueRoleDisplayName;
use App\Services\RolePermissionAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every Phase 6 permission and the Compliance Officer role (Phase 6 plan §2,
 * decisions 11 and 12; slice X0). The AMLA, executive dashboard, multi-branch
 * and loan origination slices add no permission migration of their own.
 *
 * - The 27 permissions (PERMISSIONS) are granted to super_admin and admin,
 *   one `role_permissions_changed` audit row each through
 *   RolePermissionAudit, with no user, so it reads as a system change. No
 *   other role gets them; loan officers included.
 * - The `compliance_officer` system role is created when it is missing, with
 *   its display name, description and permission list (every `amla:*` key,
 *   every branch, member details, reports, and read-only lending, savings and
 *   GCash; AMLA analysis §4, decision 12), and one system audit row. The
 *   display name gets the check a role save gets (UniqueRoleDisplayName), as
 *   in 2026_11_09_120000_add_accountant_role_and_allowance_permission: the
 *   second label when another role already reads as the first, and no role
 *   (one audit row saying so) when both are taken. A role an admin already
 *   built under that key is left exactly as it is, permissions included, and
 *   named once in the audit log. Nobody is given the role; the owner assigns
 *   it in Users.
 *
 * Listed here AND in RoleAndPermissionSeeder (PHASE6_PERMISSIONS,
 * COMPLIANCE_OFFICER_PERMISSIONS): this migration is what deployed databases
 * get, the seeder is what a fresh database builds from. The lists are spelled
 * out rather than read off the seeder, so this migration writes on its last
 * deployment what it wrote on its first.
 *
 * Idempotent: insertOrIgnore throughout, a role that exists is never written,
 * and a re-run that changes no list writes no row. A role this migration
 * already granted (its audit row names it) is skipped, so a re-run after a
 * rollback never re-grants a key the owner removed. Nothing is revoked.
 */
return new class extends Migration
{
    private const NAME = '2026_11_19_100000_add_phase6_permissions_and_compliance_officer_role';

    private const PERMISSIONS = [
        'amla:view', 'amla:alerts', 'amla:cases', 'amla:cdd', 'amla:screening', 'amla:sanctions', 'amla:ctr',
        'amla:str', 'amla:approve', 'amla:export', 'amla:submit', 'amla:settings', 'amla:audit',
        'dashboard:analytics', 'dashboard:export', 'dashboard:settings',
        'branches:manage', 'borrowers:transfer',
        'interbranch_transfers:view', 'interbranch_transfers:create', 'interbranch_transfers:approve', 'interbranch_transfers:receive',
        'loan_origination:view', 'loan_origination:update', 'loan_origination:assess', 'loan_origination:convert', 'loan_origination:settings',
    ];

    private const GRANTED_TO = ['super_admin', 'admin'];

    private const ROLE = 'compliance_officer';

    private const ROLE_TYPE = 'App\Models\Role';

    private const KEPT_ACTION = 'role_kept_by_migration';

    private const NOT_CREATED_ACTION = 'role_not_created_by_migration';

    /** In order of preference; RoleAndPermissionSeeder::COMPLIANCE_OFFICER_DISPLAY_NAMES. */
    private const DISPLAY_NAMES = ['Compliance Officer', 'Compliance Officer (AMLA)'];

    private const ROLE_ATTRIBUTES = [
        'display_name' => 'Compliance Officer',
        'description' => 'Anti-money laundering compliance: alerts, cases, due diligence, screening, sanctions, covered and suspicious transaction reports and the AMLA log, with read-only lending, savings and GCash in every branch. Assigned by the owner.',
        'is_system' => true,
        'is_active' => true,
    ];

    private const COMPLIANCE_OFFICER_PERMISSIONS = [
        'amla:view', 'amla:alerts', 'amla:cases', 'amla:cdd', 'amla:screening', 'amla:sanctions', 'amla:ctr',
        'amla:str', 'amla:approve', 'amla:export', 'amla:submit', 'amla:settings', 'amla:audit',
        'branches:view_all', 'borrowers:view', 'reports:view',
        'dashboard:view', 'loans:view', 'loan_adjustments:view', 'payments:view', 'collaterals:view',
        'fees:view', 'share_capital:view', 'auto_pay:view',
        'savings:view', 'gcash:view',
    ];

    public function up(): void
    {
        $guard = 'web';

        DB::transaction(function () use ($guard): void {
            $this->ensurePermissions(self::PERMISSIONS, $guard);
            $this->grantTheAdministrators($guard);
            $this->createTheComplianceOfficer($guard);
        });

        DB::afterCommit(fn () => $this->forgetCachedPermissions());
    }

    /**
     * Nothing to undo. Removing the permissions or the role would revoke the
     * access of whoever the owner granted it to, and each change is on record
     * in the audit log.
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
        $permissionIds = DB::table('permissions')
            ->where('guard_name', $guard)
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id');

        $roles = Role::query()->where('guard_name', $guard)->whereIn('name', self::GRANTED_TO)->get()
            ->filter(fn (Role $role) => in_array($role->name, self::GRANTED_TO, true));

        foreach ($roles as $role) {
            if ($this->alreadyGranted($role)) {
                continue;
            }

            $before = RolePermissionAudit::names($role);

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }

            RolePermissionAudit::record(
                $role,
                $before,
                RolePermissionAudit::names($role),
                sprintf('Permissions for role %s changed by migration %s: added the Phase 6 permissions (AMLA, executive dashboard, branches, inter-branch transfers and loan origination)', $role->auditName(), self::NAME),
            );
        }
    }

    /**
     * This migration already granted the role its keys (its audit row says
     * so), so a re-run after a rollback never re-grants one the owner has
     * removed since, as the kept and not-created cases never write twice.
     */
    private function alreadyGranted(Role $role): bool
    {
        return DB::table('audit_logs')
            ->where('action', 'role_permissions_changed')
            ->where('auditable_type', self::ROLE_TYPE)
            ->where('auditable_id', $role->id)
            ->where('description', 'like', '%'.self::NAME.'%')
            ->exists();
    }

    private function createTheComplianceOfficer(string $guard): void
    {
        $existing = DB::table('roles')->where('name', self::ROLE)->where('guard_name', $guard)->first(['id', 'name', 'display_name', 'is_system']);

        if ($existing !== null) {
            if (! (bool) $existing->is_system) {
                $this->recordTheKeptRole($existing);
            }

            return;
        }

        $clashes = [];
        $label = null;

        foreach (self::DISPLAY_NAMES as $candidate) {
            $clash = $this->roleReadingAs($candidate);

            if ($clash === null) {
                $label = $candidate;

                break;
            }

            $clashes[$candidate] = $clash;
        }

        if ($label === null) {
            $this->recordTheRoleNotCreated($clashes);

            return;
        }

        $this->ensurePermissions(self::COMPLIANCE_OFFICER_PERMISSIONS, $guard);

        $now = now();
        $attributes = [...self::ROLE_ATTRIBUTES, 'display_name' => $label];

        $roleId = DB::table('roles')->insertGetId([
            'name' => self::ROLE,
            'guard_name' => $guard,
            ...$attributes,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionIds = DB::table('permissions')
            ->where('guard_name', $guard)
            ->whereIn('name', self::COMPLIANCE_OFFICER_PERMISSIONS)
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        $permissions = self::COMPLIANCE_OFFICER_PERMISSIONS;
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
                json_encode($label, JSON_UNESCAPED_UNICODE),
                self::ROLE,
                self::NAME,
                count($permissions),
                $clashes === [] ? '' : ' Its display name is '.json_encode($label, JSON_UNESCAPED_UNICODE).' because '.$this->describeClashes($clashes).'.',
            ),
            'created_at' => $now,
        ]);

        if ($clashes !== []) {
            Log::warning('The Compliance Officer role was created with its second display name; another role already reads as "Compliance Officer".', [
                'migration' => self::NAME,
                'role_id' => $roleId,
                'display_name' => $label,
            ]);
        }
    }

    /**
     * @param  array<string, object>  $clashes  label => the role already reading as it
     */
    private function describeClashes(array $clashes): string
    {
        $parts = [];

        foreach ($clashes as $label => $role) {
            $parts[] = sprintf(
                '"%s" is already read by role %s (%s)',
                $label,
                json_encode(filled($role->display_name) ? trim((string) $role->display_name) : $role->name, JSON_UNESCAPED_UNICODE),
                $role->name,
            );
        }

        return implode(' and ', $parts);
    }

    /**
     * Every label is taken: the role is not created, so Users never lists two
     * roles reading alike, and one system audit row says so. A re-run while
     * they are still taken writes no second row.
     *
     * @param  array<string, object>  $clashes
     */
    private function recordTheRoleNotCreated(array $clashes): void
    {
        $recorded = DB::table('audit_logs')
            ->where('action', self::NOT_CREATED_ACTION)
            ->where('description', 'like', '%'.self::NAME.'%')
            ->exists();

        if ($recorded) {
            return;
        }

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => self::NOT_CREATED_ACTION,
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => json_encode(['name' => self::ROLE, 'display_names' => self::DISPLAY_NAMES], JSON_UNESCAPED_UNICODE),
            'ip_address' => null,
            'user_agent' => null,
            'description' => sprintf(
                'System change: migration %s did not create the Compliance Officer role (%s): %s. Rename one of those roles and ask for the Compliance Officer role to be added.',
                self::NAME,
                self::ROLE,
                $this->describeClashes($clashes),
            ),
            'created_at' => now(),
        ]);

        Log::warning('The Compliance Officer role was not created; both of its display names are already taken.', [
            'migration' => self::NAME,
            'clashing_roles' => array_map(fn (object $role) => $role->name, $clashes),
        ]);
    }

    /**
     * The role whose key or display name already reads as `$displayName`, by
     * the same comparison a role save makes (UniqueRoleDisplayName), any
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
     * A role keyed `compliance_officer` that this migration didn't create:
     * left as it is, and named once in the audit log so the owner knows the
     * Compliance Officer role wasn't created. A re-run writes no second row.
     */
    private function recordTheKeptRole(object $role): void
    {
        $recorded = DB::table('audit_logs')
            ->where('action', self::KEPT_ACTION)
            ->where('auditable_type', self::ROLE_TYPE)
            ->where('auditable_id', $role->id)
            ->where('description', 'like', '%'.self::NAME.'%')
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
                'System change: Role %s (%s) already existed and was left as it is, permissions included; migration %s did not create the Compliance Officer role or change this one.',
                json_encode($label, JSON_UNESCAPED_UNICODE),
                $role->name,
                self::NAME,
            ),
            'created_at' => now(),
        ]);

        Log::warning('A role keyed compliance_officer already exists; it was left as it is, permissions included.', [
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
