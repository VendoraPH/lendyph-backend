<?php

namespace Tests\Feature\Portal\Foundation;

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
 * The six `member_portal:*` permissions (portal design §4): admin and
 * super_admin only, from the seeder and from the migration, with one system
 * audit row per role granted and nothing on a re-run.
 */
class PortalPermissionsTest extends TestCase
{
    use SetupLendyPH;

    private const NAMES = [
        'member_portal:announcements', 'member_portal:invite', 'member_portal:manage',
        'member_portal:requests', 'member_portal:settings', 'member_portal:view',
    ];

    private const MIGRATION = '2026_11_11_700200_add_member_portal_permissions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_six_exist_and_only_the_administrators_hold_them(): void
    {
        $this->assertSame(self::NAMES, Permission::query()->where('name', 'like', 'member_portal:%')->orderBy('name')->pluck('name')->all());
        $this->assertSame(self::NAMES, $this->grants('super_admin'));
        $this->assertSame(self::NAMES, $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));
    }

    public function test_the_migration_grants_the_administrators_with_one_system_audit_row_each_and_a_rerun_adds_nothing(): void
    {
        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Permission::query()->where('name', 'like', 'member_portal:%')->exists());
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();

        $this->assertSame(self::NAMES, $this->grants('super_admin'));
        $this->assertSame(self::NAMES, $this->grants('admin'));
        $rows = AuditLog::query()->where('id', '>', $before)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['role_permissions_changed'], $rows->pluck('action')->unique()->values()->all());
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());
        foreach ($rows as $row) {
            $this->assertStringContainsString(self::MIGRATION, (string) $row->description);
        }

        $counts = [DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()];
        $this->migration()->up();
        $this->assertSame($counts, [DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()]);
    }

    public function test_the_seeder_on_a_fresh_database_matches_the_migration(): void
    {
        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame(self::NAMES, $this->grants('super_admin'));
        $this->assertSame(self::NAMES, $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /** @return list<string> */
    private function grants(string $roleName): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::query()->where('name', $roleName)->firstOrFail()
            ->permissions()->where('name', 'like', 'member_portal:%')->orderBy('name')->pluck('name')->all();
    }

    /** @return array<string, list<string>> */
    private function grantsOfOtherRoles(): array
    {
        return Role::query()->whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions()->where('name', 'like', 'member_portal:%')->pluck('name')->all()])
            ->all();
    }
}
