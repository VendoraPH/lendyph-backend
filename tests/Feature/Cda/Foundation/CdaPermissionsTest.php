<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportTemplate;
use App\Models\Role;
use App\Services\BranchAccess;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The sixteen `cda:*` permissions (CDA design §3): admin and super_admin only,
 * from the seeder and from the migration, with one system audit row per role
 * granted and nothing on a re-run. Every C1 route answers 403 without its
 * permission and without `branches:view_all`; a restricted report needs its
 * own view permission; the template manager is super_admin's alone.
 */
class CdaPermissionsTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private const NAMES = [
        'cda:approve', 'cda:export', 'cda:finalize', 'cda:manage_mediation', 'cda:manage_officers',
        'cda:manage_social', 'cda:prepare', 'cda:reopen', 'cda:review', 'cda:settings', 'cda:submit',
        'cda:upload', 'cda:view', 'cda:view_audit', 'cda:view_mediation', 'cda:view_tax',
    ];

    private const MIGRATION = '2026_11_11_100200_add_cda_permissions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_sixteen_exist_and_only_the_administrators_hold_them(): void
    {
        $this->assertSame(self::NAMES, Permission::query()->where('name', 'like', 'cda:%')->orderBy('name')->pluck('name')->all());
        $this->assertSame(self::NAMES, $this->grants('super_admin'));
        $this->assertSame(self::NAMES, $this->grants('admin'));
        $this->assertSame([], array_filter($this->grantsOfOtherRoles()));
    }

    public function test_the_migration_grants_the_administrators_with_one_system_audit_row_each_and_a_rerun_adds_nothing(): void
    {
        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Permission::query()->where('name', 'like', 'cda:%')->exists());
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

    public function test_every_route_answers_403_without_its_permission_or_without_view_all(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $capr = CdaReport::query()->where('report_type', 'capr')->firstOrFail();
        $template = CdaReportTemplate::query()->where('report_type', 'capr')->firstOrFail();

        $calls = [
            ['getJson', '/api/cda/periods', [], 'cda:view'],
            ['postJson', '/api/cda/periods', ['year' => 2025], 'cda:prepare'],
            ['getJson', '/api/cda/periods/2026', [], 'cda:view'],
            ['patchJson', '/api/cda/periods/2026', ['extended_deadline' => '2027-05-31', 'extension_reference' => 'CDA MC 2027-01'], 'cda:settings'],
            ['patchJson', "/api/cda/reports/{$capr->id}/deadline", ['override_on' => '2027-05-15', 'reference' => 'Letter'], 'cda:settings'],
            ['getJson', '/api/cda/regulatory-profile', [], 'cda:view'],
            ['putJson', '/api/cda/regulatory-profile', ['tin' => '123-456-789-000'], 'cda:settings'],
            ['getJson', '/api/cda/settings', [], 'cda:view'],
            ['putJson', '/api/cda/settings', ['annual_deadline_days' => 120], 'cda:settings'],
            ['getJson', '/api/cda/templates', [], 'cda:view'],
            ['getJson', "/api/cda/templates/{$template->id}", [], 'cda:view'],
        ];

        $everyOtherCda = fn (string $allowed) => array_values(array_diff(self::NAMES, [$allowed]));

        foreach ($calls as [$method, $uri, $body, $permission]) {
            $this->actingAs($this->userWith([BranchAccess::VIEW_ALL, ...$everyOtherCda($permission)]));
            $this->{$method}($uri, $body)->assertForbidden();

            $this->actingAs($this->userWith([$permission]));
            $this->{$method}($uri, $body)->assertForbidden();

            $this->actingAs($this->userWith([$permission, BranchAccess::VIEW_ALL]));
            $status = $this->{$method}($uri, $body)->status();
            $this->assertNotSame(403, $status, "{$method} {$uri} refused a caller holding {$permission} and branches:view_all.");
        }
    }

    public function test_a_restricted_report_needs_its_own_view_permission(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $h1 = CdaReport::query()->where('report_type', 'mediation')->where('part', 'h1')->firstOrFail();
        $atir = CdaReport::query()->where('report_type', 'atir')->firstOrFail();
        $fs = CdaReport::query()->where('report_type', 'financial_statements')->firstOrFail();
        $body = ['override_on' => '2026-08-15', 'reference' => 'Letter'];

        $this->actingAs($this->userWith(['cda:settings', 'cda:view', BranchAccess::VIEW_ALL]));
        foreach ([$h1, $atir, $fs] as $report) {
            $this->patchJson("/api/cda/reports/{$report->id}/deadline", $body)->assertForbidden();
        }

        $this->actingAs($this->userWith(['cda:settings', 'cda:view_mediation', BranchAccess::VIEW_ALL]));
        $this->patchJson("/api/cda/reports/{$h1->id}/deadline", $body)->assertOk();
        $this->patchJson("/api/cda/reports/{$atir->id}/deadline", $body)->assertForbidden();

        $this->actingAs($this->userWith(['cda:settings', 'cda:view_tax', BranchAccess::VIEW_ALL]));
        $this->patchJson("/api/cda/reports/{$atir->id}/deadline", $body)->assertOk();

        $this->actingAs($this->userWith(['cda:settings', 'cda:view_audit', BranchAccess::VIEW_ALL]));
        $this->patchJson("/api/cda/reports/{$fs->id}/deadline", $body)->assertOk();

        $this->actingAs($this->userWith(['cda:view', BranchAccess::VIEW_ALL]));
        $rows = collect($this->getJson('/api/cda/periods/2026')->assertOk()->json('data.reports'))->keyBy(fn ($r) => $r['report_type'].':'.$r['part']);
        $this->assertArrayNotHasKey('mediation:h1', $rows, 'A restricted report is left out of the period, not marked.');
        $this->assertArrayNotHasKey('atir:annual', $rows);
        $this->assertFalse($rows['capr:annual']['restricted']);
    }

    public function test_the_template_manager_answers_403_for_admin_and_200_for_super_admin(): void
    {
        $admin = $this->userWith([]);
        $admin->syncRoles(['admin']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $draft = CdaReportTemplate::factory()->create(['report_type' => 'capr', 'version' => '2027.1', 'status' => 'draft']);
        $definition = CdaReportTemplate::query()->where('report_type', 'capr')->where('status', 'active')->firstOrFail()->definition;

        $writes = [
            ['postJson', '/api/cda/templates', ['report_type' => 'capr', 'version' => '2028.1', 'name' => 'CAPR 2028', 'effective_from' => '2028-01-01', 'definition' => $definition]],
            ['putJson', "/api/cda/templates/{$draft->id}", ['name' => 'Renamed']],
            ['postJson', '/api/cda/templates/validate', ['report_type' => 'capr', 'definition' => $definition]],
            ['postJson', "/api/cda/templates/{$draft->id}/activate", []],
            ['postJson', "/api/cda/templates/{$draft->id}/retire", ['reason' => 'x']],
        ];

        $this->actingAs($admin);
        $this->getJson('/api/cda/templates')->assertOk();
        foreach ($writes as [$method, $uri, $body]) {
            $this->{$method}($uri, $body)->assertForbidden();
        }

        $this->actingAs($this->admin);
        $this->postJson('/api/cda/templates/validate', ['report_type' => 'capr', 'definition' => $definition])->assertOk()->assertJsonPath('data.valid', true);
        $this->putJson("/api/cda/templates/{$draft->id}", ['name' => 'Renamed'])->assertOk();
        $this->postJson('/api/cda/templates', $writes[0][2])->assertCreated();
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
            ->permissions()->where('name', 'like', 'cda:%')->orderBy('name')->pluck('name')->all();
    }

    /** @return array<string, list<string>> */
    private function grantsOfOtherRoles(): array
    {
        return Role::query()->whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions()->where('name', 'like', 'cda:%')->pluck('name')->all()])
            ->all();
    }
}
