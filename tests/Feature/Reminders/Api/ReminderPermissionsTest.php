<?php

namespace Tests\Feature\Reminders\Api;

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
 * `reminders:view`, `send`, `pause` and `settings`: admin and super_admin only,
 * from the seeder on a fresh database and from the migration on a deployed one,
 * with one system audit row per role the migration grants to.
 */
class ReminderPermissionsTest extends TestCase
{
    use SetupLendyPH;

    private const NAMES = ['reminders:pause', 'reminders:send', 'reminders:settings', 'reminders:view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_four_permissions_exist_on_the_web_guard(): void
    {
        $this->assertSame(self::NAMES, Permission::query()->where('name', 'like', 'reminders:%')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['web'], Permission::query()->where('name', 'like', 'reminders:%')->pluck('guard_name')->unique()->values()->all());
    }

    public function test_only_the_administrators_hold_them(): void
    {
        $this->assertSame(self::NAMES, $this->grants('super_admin'));
        $this->assertSame(self::NAMES, $this->grants('admin'));
        $this->assertSame([], $this->grants('loan_officer'));
        $this->assertSame([], $this->grants('manager'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));
    }

    public function test_the_migration_grants_the_administrators_only_with_one_system_audit_row_each(): void
    {
        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Permission::query()->where('name', 'like', 'reminders:%')->exists());
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->migration()->up();

        $this->assertSame(self::NAMES, $this->grants('super_admin'));
        $this->assertSame(self::NAMES, $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));

        $rows = AuditLog::query()->where('id', '>', $before)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['role_permissions_changed'], $rows->pluck('action')->unique()->values()->all());
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            [Role::findByName('super_admin')->id, Role::findByName('admin')->id],
            $rows->pluck('auditable_id')->map(fn ($id) => (int) $id)->all(),
        );
        foreach ($rows as $row) {
            $this->assertStringContainsString('2026_11_09_100000_add_reminders_permissions', (string) $row->description);
            $this->assertSame([], array_values(array_diff(self::NAMES, $row->new_values['permissions'])));
            $this->assertSame([], array_values(array_intersect(self::NAMES, $row->old_values['permissions'])));
        }
    }

    public function test_a_rerun_adds_nothing_and_writes_no_audit_row(): void
    {
        $this->migration()->down();
        $this->migration()->up();
        $permissions = DB::table('permissions')->count();
        $grants = DB::table('role_has_permissions')->count();
        $audits = AuditLog::query()->count();

        $this->migration()->up();

        $this->assertSame($permissions, DB::table('permissions')->count());
        $this->assertSame($grants, DB::table('role_has_permissions')->count());
        $this->assertSame($audits, AuditLog::query()->count());
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

    public function test_down_removes_the_four(): void
    {
        $this->migration()->down();

        $this->assertFalse(Permission::query()->where('name', 'like', 'reminders:%')->exists());
    }

    public function test_a_role_can_be_saved_with_the_four_ticked(): void
    {
        $role = Role::create(['name' => 'reminder_clerk', 'guard_name' => 'web']);

        $this->putJson("/api/roles/{$role->id}", ['permissions' => ['dashboard:view', ...self::NAMES]])->assertOk();

        $this->assertSame(self::NAMES, $this->grants('reminder_clerk'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_09_100000_add_reminders_permissions.php');
    }

    /**
     * @return list<string>
     */
    private function grants(string $roleName): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::query()->where('name', $roleName)->firstOrFail()
            ->permissions()->where('name', 'like', 'reminders:%')->orderBy('name')->pluck('name')->all();
    }

    /**
     * @return array<string, list<string>>
     */
    private function grantsOfOtherRoles(): array
    {
        return Role::query()->whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->pluck('name')
            ->mapWithKeys(fn (string $name) => [$name => $this->grants($name)])
            ->all();
    }
}
