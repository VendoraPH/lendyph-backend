<?php

namespace Tests\Feature\Phase6\Foundation;

use App\Models\AuditLog;
use App\Models\Role;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every Phase 6 permission (plan §2, decision 11): the 13 `amla:*` keys, the
 * executive dashboard's three, `branches:manage`, `borrowers:transfer`, the
 * four inter-branch transfer keys and the five loan origination keys. Granted
 * to admin and super_admin only, from the seeder on a fresh database and from
 * the migration on a deployed one, with one system audit row per role it
 * grants to. The Compliance Officer's AMLA and read-only grants are pinned by
 * ComplianceOfficerRoleTest; no other role holds any of these.
 */
class Phase6PermissionsTest extends TestCase
{
    use SetupLendyPH;

    public const MIGRATION = '2026_11_19_100000_add_phase6_permissions_and_compliance_officer_role';

    /** @var list<string> */
    public const AMLA = [
        'amla:view', 'amla:alerts', 'amla:cases', 'amla:cdd', 'amla:screening', 'amla:sanctions', 'amla:ctr',
        'amla:str', 'amla:approve', 'amla:export', 'amla:submit', 'amla:settings', 'amla:audit',
    ];

    /** @var list<string> */
    public const OTHERS = [
        'dashboard:analytics', 'dashboard:export', 'dashboard:settings',
        'branches:manage', 'borrowers:transfer',
        'interbranch_transfers:view', 'interbranch_transfers:create', 'interbranch_transfers:approve', 'interbranch_transfers:receive',
        'loan_origination:view', 'loan_origination:update', 'loan_origination:assess', 'loan_origination:convert', 'loan_origination:settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_list_is_the_one_decided(): void
    {
        $this->assertCount(13, self::AMLA);
        $this->assertCount(27, $this->names());
        $this->assertSame($this->names(), $this->sorted(RoleAndPermissionSeeder::PHASE6_PERMISSIONS));
    }

    public function test_every_key_exists_and_only_the_administrators_hold_them(): void
    {
        $this->assertSame(
            $this->names(),
            Permission::query()->where('guard_name', 'web')->whereIn('name', $this->names())->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame($this->names(), $this->grants('super_admin'));
        $this->assertSame($this->names(), $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));
    }

    public function test_the_seeder_on_a_fresh_database_grants_the_administrators_only(): void
    {
        $this->forgetPhase6();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame($this->names(), $this->grants('super_admin'));
        $this->assertSame($this->names(), $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));
    }

    public function test_the_seeder_writes_no_audit_row(): void
    {
        $audits = AuditLog::query()->count();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_the_migration_grants_the_administrators_with_one_system_audit_row_each(): void
    {
        $this->forgetPhase6();
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();

        $this->assertSame($this->names(), $this->grants('super_admin'));
        $this->assertSame($this->names(), $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));

        $rows = AuditLog::query()->where('id', '>', $before)->where('action', 'role_permissions_changed')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            [Role::findByName('super_admin')->id, Role::findByName('admin')->id],
            $rows->pluck('auditable_id')->map(fn ($id) => (int) $id)->all(),
        );

        foreach ($rows as $row) {
            $this->assertStringContainsString(self::MIGRATION, (string) $row->description);
            $this->assertSame($this->names(), $this->sorted(array_diff($row->new_values['permissions'], $row->old_values['permissions'])));
            $this->assertSame([], array_values(array_diff($row->old_values['permissions'], $row->new_values['permissions'])));
        }
    }

    public function test_running_the_migration_twice_adds_nothing_and_writes_nothing(): void
    {
        $this->forgetPhase6();
        Auth::logout();
        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $counts = $this->counts();

        $this->migration()->up();

        $this->assertSame($counts, $this->counts());
        $this->assertSame($this->names(), $this->grants('admin'));
    }

    public function test_the_migration_over_the_seeded_database_writes_nothing(): void
    {
        $counts = $this->counts();

        $this->migration()->up();

        $this->assertSame($counts, $this->counts());
    }

    public function test_the_migration_leaves_an_existing_compliance_officer_exactly_as_it_is(): void
    {
        $role = Role::findByName('compliance_officer');
        $role->revokePermissionTo('amla:approve');
        $before = $this->allGrants('compliance_officer');
        $this->assertNotContains('amla:approve', $before);

        $this->migration()->up();

        $this->assertSame($before, $this->allGrants('compliance_officer'));
    }

    public function test_a_rerun_after_a_rollback_never_regrants_a_key_the_owner_removed(): void
    {
        $this->forgetPhase6();
        Auth::logout();
        $this->migration()->up();
        Role::findByName('admin')->revokePermissionTo('dashboard:export');
        $audits = AuditLog::query()->count();

        $this->migration()->down();
        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNotContains('dashboard:export', $this->grants('admin'));
        $this->assertSame($this->names(), $this->grants('super_admin'));
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_the_migration_skips_a_role_that_does_not_exist_on_this_box(): void
    {
        $this->forgetPhase6();
        Role::findByName('admin')->delete();

        $this->migration()->up();

        $this->assertFalse(Role::query()->where('name', 'admin')->exists());
        $this->assertSame($this->names(), $this->grants('super_admin'));
    }

    // ── Helpers ──

    /** @return list<string> */
    private function names(): array
    {
        return $this->sorted([...self::AMLA, ...self::OTHERS]);
    }

    /**
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /**
     * A database migrated before Phase 6: none of its permissions, so no role
     * holds one. The Compliance Officer role is left in place; the migration
     * never touches a role that already exists.
     */
    private function forgetPhase6(): void
    {
        $ids = DB::table('permissions')->whereIn('name', $this->names())->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Permission::query()->whereIn('name', $this->names())->exists());
    }

    /** @return list<int> */
    private function counts(): array
    {
        return [
            DB::table('permissions')->count(),
            DB::table('roles')->count(),
            DB::table('role_has_permissions')->count(),
            AuditLog::query()->count(),
        ];
    }

    /** @return list<string> */
    private function grants(string $roleName): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::query()->where('name', $roleName)->firstOrFail()
            ->permissions()->whereIn('name', $this->names())->orderBy('name')->pluck('name')->all();
    }

    /** @return list<string> */
    private function allGrants(string $roleName): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::query()->where('name', $roleName)->firstOrFail()->permissions()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Phase 6 grants of every role but the administrators. The Compliance
     * Officer's AMLA keys are its own (ComplianceOfficerRoleTest); any other
     * Phase 6 key on it, or any key on another role, shows here.
     *
     * @return array<string, list<string>>
     */
    private function grantsOfOtherRoles(): array
    {
        return Role::query()->whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => array_values(array_filter(
                $role->permissions()->whereIn('name', $this->names())->orderBy('name')->pluck('name')->all(),
                fn (string $name) => ! ($role->name === 'compliance_officer' && str_starts_with($name, 'amla:')),
            ))])
            ->all();
    }
}
