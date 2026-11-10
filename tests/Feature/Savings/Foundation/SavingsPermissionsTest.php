<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SavingsProduct;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The seven `savings:*` permissions (design §3): admin and super_admin only,
 * from the seeder on a fresh database and from the migration on a deployed
 * one, with one system audit row per role it grants to. Each foundation
 * endpoint answers 403 to a role without its permission.
 */
class SavingsPermissionsTest extends TestCase
{
    use SetupLendyPH;

    private const NAMES = [
        'savings:approve', 'savings:close', 'savings:create', 'savings:post',
        'savings:settings', 'savings:transact', 'savings:view',
    ];

    private const MIGRATION = '2026_11_10_500300_add_savings_permissions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_seven_exist_and_only_the_administrators_hold_them(): void
    {
        $this->assertSame(self::NAMES, Permission::query()->where('name', 'like', 'savings:%')->orderBy('name')->pluck('name')->all());
        $this->assertSame(self::NAMES, $this->grants('super_admin'));
        $this->assertSame(self::NAMES, $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));
    }

    public function test_the_migration_grants_the_administrators_with_one_system_audit_row_each(): void
    {
        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Permission::query()->where('name', 'like', 'savings:%')->exists());
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
        foreach ($rows as $row) {
            $this->assertStringContainsString(self::MIGRATION, (string) $row->description);
            $this->assertSame([], array_values(array_diff(self::NAMES, $row->new_values['permissions'])));
        }
    }

    public function test_a_rerun_adds_nothing_and_writes_no_audit_row(): void
    {
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

    public function test_each_foundation_endpoint_answers_403_without_its_permission(): void
    {
        $product = SavingsProduct::factory()->create();
        $calls = [
            ['getJson', '/api/savings/settings', [], ['savings:view', 'savings:settings']],
            ['putJson', '/api/savings/settings', ['dormancy_months' => 12], ['savings:settings', 'branches:view_all']],
            ['postJson', '/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '0', 'effective_from' => now()->toDateString()], ['savings:settings', 'branches:view_all']],
            ['getJson', '/api/savings/products', [], ['savings:view']],
            ['postJson', '/api/savings/products', ['code' => 'NEW', 'name' => 'New', 'kind' => 'regular', 'annual_interest_rate' => '1'], ['savings:settings', 'branches:view_all']],
            ['putJson', "/api/savings/products/{$product->id}", ['name' => 'Renamed'], ['savings:settings', 'branches:view_all']],
            ['getJson', "/api/savings/products/{$product->id}/rates", [], ['savings:view']],
            ['postJson', "/api/savings/products/{$product->id}/rates", ['annual_rate' => '1', 'effective_from' => now()->toDateString()], ['savings:settings', 'branches:view_all']],
            ['deleteJson', "/api/savings/products/{$product->id}", [], ['savings:settings', 'branches:view_all']],
        ];

        $everyOther = array_values(array_diff(self::NAMES, ['savings:view', 'savings:settings']));
        $this->actingAs($this->userWith(['dashboard:view', ...$everyOther]));

        foreach ($calls as [$method, $uri, $body]) {
            $this->{$method}($uri, $body)->assertForbidden();
        }

        foreach ($calls as [$method, $uri, $body, $allowed]) {
            $this->actingAs($this->userWith($allowed));
            $status = $this->{$method}($uri, $body)->status();
            $this->assertNotSame(403, $status, "{$method} {$uri} refused a caller holding ".implode(' and ', $allowed).'.');
        }

        $this->actingAs($this->userWith(['savings:view']));
        $this->putJson('/api/savings/settings', ['dormancy_months' => 12])->assertForbidden();
    }

    /** A user whose only role holds exactly these permissions. */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'savings_probe_'.count(Role::all()), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
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
            ->permissions()->where('name', 'like', 'savings:%')->orderBy('name')->pluck('name')->all();
    }

    /** @return array<string, list<string>> */
    private function grantsOfOtherRoles(): array
    {
        return Role::query()->whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions()->where('name', 'like', 'savings:%')->pluck('name')->all()])
            ->all();
    }
}
