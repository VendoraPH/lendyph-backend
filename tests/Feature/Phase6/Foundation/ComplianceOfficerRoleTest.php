<?php

namespace Tests\Feature\Phase6\Foundation;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Rules\UniqueRoleDisplayName;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Compliance Officer role (plan §2, decision 12; AMLA analysis §4,
 * decision 12): every `amla:*` permission, every branch, member details,
 * reports, and read-only lending, savings and GCash. No lending, savings or
 * GCash write, no settings or users, and no `audit_logs:view` (AMLA keeps its
 * own log). Nobody gets it from a seeder or a migration; the owner assigns it.
 *
 * Both paths are covered: the seeder for a fresh database, the migration for
 * one already migrated.
 */
class ComplianceOfficerRoleTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = Phase6PermissionsTest::MIGRATION;

    private const DESCRIPTION = 'Anti-money laundering compliance: alerts, cases, due diligence, screening, sanctions, covered and suspicious transaction reports and the AMLA log, with read-only lending, savings and GCash in every branch. Assigned by the owner.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── The seeder (a fresh database) ──

    public function test_the_list_is_the_one_decided(): void
    {
        $this->assertSame($this->sorted([
            ...Phase6PermissionsTest::AMLA,
            'branches:view_all', 'borrowers:view', 'reports:view',
            'dashboard:view', 'loans:view', 'loan_adjustments:view', 'payments:view', 'collaterals:view',
            'fees:view', 'share_capital:view', 'auto_pay:view',
            'savings:view', 'gcash:view',
        ]), $this->sorted(RoleAndPermissionSeeder::COMPLIANCE_OFFICER_PERMISSIONS));

        foreach ([
            'audit_logs:view', 'audit_logs:export', 'reports:export', 'users:view', 'settings:update',
            'borrowers:create', 'borrowers:update', 'loans:create', 'loans:update', 'loans:approve', 'loans:release',
            'payments:create', 'payments:void', 'savings:transact', 'savings:approve', 'gcash:transact',
            'dashboard:analytics', 'branches:manage', 'borrowers:transfer', 'loan_origination:view',
        ] as $permission) {
            $this->assertNotContains($permission, RoleAndPermissionSeeder::COMPLIANCE_OFFICER_PERMISSIONS);
        }
    }

    public function test_it_is_a_system_role_with_exactly_its_list(): void
    {
        $role = Role::findByName('compliance_officer');

        $this->assertTrue($role->is_system);
        $this->assertTrue($role->is_active);
        $this->assertSame('Compliance Officer', $role->display_name);
        $this->assertSame(self::DESCRIPTION, $role->description);
        $this->assertSame($this->sorted(RoleAndPermissionSeeder::COMPLIANCE_OFFICER_PERMISSIONS), $this->grants('compliance_officer'));
    }

    public function test_the_seeder_gives_nobody_the_role(): void
    {
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', Role::findByName('compliance_officer')->id)->count());
    }

    public function test_the_seeder_keeps_an_edited_display_name(): void
    {
        Role::findByName('compliance_officer')->update(['display_name' => 'AML Officer']);

        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame('AML Officer', Role::findByName('compliance_officer')->fresh()->display_name);
    }

    public function test_the_seeder_fills_a_blank_display_name_with_a_label_no_other_role_reads_as(): void
    {
        Role::findByName('manager')->update(['display_name' => 'Compliance Officer']);
        Role::findByName('compliance_officer')->update(['display_name' => null]);

        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame('Compliance Officer (AMLA)', Role::findByName('compliance_officer')->fresh()->display_name);
    }

    public function test_the_two_labels_never_read_as_each_other(): void
    {
        $this->assertSame(['Compliance Officer', 'Compliance Officer (AMLA)'], RoleAndPermissionSeeder::COMPLIANCE_OFFICER_DISPLAY_NAMES);
        $this->assertFalse(UniqueRoleDisplayName::readsAsAnotherRole(
            [(object) ['id' => 1, 'name' => 'compliance_officer', 'display_name' => 'Compliance Officer']],
            'Compliance Officer (AMLA)',
        ));
    }

    // ── The migration (an already-migrated database) ──

    public function test_the_migration_creates_the_role_with_one_system_audit_row(): void
    {
        $this->forgetTheRole();
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findByName('compliance_officer');
        $this->assertTrue($role->is_system);
        $this->assertTrue($role->is_active);
        $this->assertSame('Compliance Officer', $role->display_name);
        $this->assertSame(self::DESCRIPTION, $role->description);
        $this->assertSame($this->sorted(RoleAndPermissionSeeder::COMPLIANCE_OFFICER_PERMISSIONS), $this->grants('compliance_officer'));
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', $role->id)->count());

        $created = AuditLog::query()->where('id', '>', $before)->where('action', 'created')->sole();
        $this->assertNull($created->user_id);
        $this->assertSame(Role::class, $created->auditable_type);
        $this->assertSame($role->id, (int) $created->auditable_id);
        $this->assertSame('compliance_officer', $created->new_values['name']);
        $this->assertSame($this->sorted(RoleAndPermissionSeeder::COMPLIANCE_OFFICER_PERMISSIONS), $created->new_values['permissions']);
        $this->assertStringContainsString(self::MIGRATION, (string) $created->description);
    }

    public function test_a_second_run_changes_nothing_and_writes_nothing(): void
    {
        $this->forgetTheRole();
        Auth::logout();
        $this->migration()->up();

        $counts = $this->counts();

        $this->migration()->up();

        $this->assertSame($counts, $this->counts());
    }

    public function test_an_existing_role_under_that_key_is_left_as_it_is_and_named_once(): void
    {
        $this->forgetTheRole();
        $custom = Role::query()->create(['name' => 'compliance_officer', 'guard_name' => 'web', 'description' => 'Built by hand', 'is_system' => false, 'is_active' => false]);
        $custom->givePermissionTo('loans:view');
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = $custom->fresh();
        $this->assertFalse($role->is_system);
        $this->assertSame('Built by hand', $role->description);
        $this->assertSame(['loans:view'], $this->grants('compliance_officer'));

        $kept = AuditLog::query()->where('id', '>', $before)->where('auditable_id', $role->id)->where('auditable_type', Role::class)->sole();
        $this->assertSame('role_kept_by_migration', $kept->action);
        $this->assertNull($kept->user_id);
        $this->assertStringContainsString(self::MIGRATION, (string) $kept->description);

        $audits = AuditLog::query()->count();
        $this->migration()->up();
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_a_display_name_another_role_reads_as_takes_the_second_label(): void
    {
        $this->forgetTheRole();
        Role::findByName('manager')->update(['display_name' => ' compliance  OFFICER ']);
        Auth::logout();

        $this->migration()->up();

        $this->assertSame('Compliance Officer (AMLA)', Role::findByName('compliance_officer')->display_name);
    }

    public function test_when_both_labels_clash_the_role_is_not_created_and_that_is_audited_once(): void
    {
        $this->forgetTheRole();
        Role::findByName('manager')->update(['display_name' => 'Compliance Officer']);
        Role::findByName('viewer')->update(['display_name' => 'Compliance Officer (AMLA)']);
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();

        $this->assertFalse(DB::table('roles')->where('name', 'compliance_officer')->exists());
        $skipped = AuditLog::query()->where('id', '>', $before)->where('action', 'role_not_created_by_migration')->sole();
        $this->assertNull($skipped->user_id);
        $this->assertStringContainsString('(manager)', (string) $skipped->description);
        $this->assertStringContainsString('(viewer)', (string) $skipped->description);

        $audits = AuditLog::query()->count();
        $this->migration()->up();
        $this->assertSame($audits, AuditLog::query()->count());
    }

    // ── What a Compliance Officer can do ──

    public function test_a_compliance_officer_reads_members_and_loans_but_writes_nothing(): void
    {
        $user = $this->userWithRole('compliance_officer');
        $this->actingAs($user);

        $this->assertTrue($user->can('amla:view'));
        $this->assertTrue($user->can('branches:view_all'));

        $this->getJson('/api/borrowers')->assertOk();
        $this->getJson('/api/loans')->assertOk();
        $this->postJson('/api/loans', [])->assertForbidden();
        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    // ── Helpers ──

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /** A database migrated before Phase 6: no `compliance_officer` role. */
    private function forgetTheRole(): void
    {
        $id = DB::table('roles')->where('name', 'compliance_officer')->value('id');

        if ($id !== null) {
            DB::table('role_has_permissions')->where('role_id', $id)->delete();
            DB::table('roles')->where('id', $id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $user->assignRole($role);

        return $user;
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
}
