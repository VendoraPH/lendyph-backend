<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportTemplate;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\ReportTypes;
use App\Services\Cda\TemplateDefinitionValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The 2026.2 template update (gap round B4): for each report type it retires
 * 2026.1 with a reason, activates 2026.2 (unverified, effective 2026-01-01),
 * moves the draft reports of the years it covers to 2026.2 with a system
 * audit row each, and leaves every other report on 2026.1. It runs safely
 * twice, `down()` puts things back, and `--dry-run` writes nothing.
 */
class CdaTemplates20262MigrationTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_13_130000_activate_cda_templates_2026_2.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /** The state before the migration ran: 2026.1 active, no 2026.2. */
    private function before(): void
    {
        DB::table('cda_report_templates')->where('version', '2026.2')->delete();
        DB::table('cda_report_templates')->where('version', '2026.1')->update(['status' => 'active', 'reason' => null]);
    }

    private function template(string $type, string $version): CdaReportTemplate
    {
        return CdaReportTemplate::query()->where('report_type', $type)->where('version', $version)->firstOrFail();
    }

    private function reportsAudit(): Collection
    {
        return AuditLog::query()->where('auditable_type', CdaReport::class)->where('action', 'cda_workflow')->orderBy('id')->get();
    }

    public function test_every_type_has_2026_2_active_unverified_and_2026_1_retired_with_a_reason(): void
    {
        $validator = app(TemplateDefinitionValidator::class);

        foreach (ReportTypes::ALL as $type) {
            $new = $this->template($type, '2026.2');
            $old = $this->template($type, '2026.1');

            $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $new->status, $type);
            $this->assertFalse($new->definition['meta']['verified'], "{$type} 2026.2 is still to verify.");
            $this->assertSame('2026-01-01', $new->effective_from->toDateString());
            $this->assertSame([], $validator->validate($new->definition, $type), "{$type} 2026.2 is valid.");
            $this->assertSame(CdaReportTemplate::STATUS_RETIRED, $old->status, $type);
            $this->assertStringContainsString('2026.2', (string) $old->reason);
        }
    }

    public function test_2026_2_keeps_every_2026_1_field_with_its_type(): void
    {
        foreach (ReportTypes::ALL as $type) {
            $old = $this->fieldTypes($this->template($type, '2026.1')->definition);
            $new = $this->fieldTypes($this->template($type, '2026.2')->definition);

            foreach ($old as $key => $fieldType) {
                $this->assertArrayHasKey($key, $new, "{$type} keeps {$key}, so a moved draft keeps its value.");
                $this->assertSame($fieldType, $new[$key], "{$type} {$key} keeps its type.");
            }
        }
    }

    /** @return array<string, string> */
    private function fieldTypes(array $definition): array
    {
        $types = [];

        foreach ($definition['sections'] as $section) {
            if ($section['kind'] === 'table') {
                $types[$section['key']] = 'table';

                continue;
            }

            foreach ($section['fields'] as $field) {
                $types[$section['key'].'.'.$field['key']] = $field['type'];
            }
        }

        return $types;
    }

    public function test_drafts_move_to_2026_2_with_a_system_audit_row_and_other_reports_stay(): void
    {
        $this->before();
        $period = app(PeriodOpener::class)->open(2026, $this->admin);
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        $fs = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'financial_statements')->firstOrFail();
        $fs->forceFill(['status' => CdaReport::STATUS_FINALIZED])->save();
        $review = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'social_audit')->firstOrFail();
        $review->forceFill(['status' => CdaReport::STATUS_FOR_REVIEW])->save();
        $this->assertSame($this->template('capr', '2026.1')->id, $capr->template_id);
        $auditBefore = $this->reportsAudit()->count();
        $lastAudit = (int) AuditLog::query()->max('id');

        $this->migration()->up();

        $this->assertSame($this->template('capr', '2026.2')->id, $capr->refresh()->template_id);
        $this->assertNotNull($capr->stale_at, 'The moved draft asks for a refresh.');
        $this->assertSame($this->template('financial_statements', '2026.1')->id, $fs->refresh()->template_id, 'A finalized report keeps its template.');
        $this->assertSame($this->template('social_audit', '2026.1')->id, $review->refresh()->template_id, 'A report in review keeps its template.');

        $drafts = CdaReport::query()->where('period_id', $period->id)->where('status', CdaReport::STATUS_DRAFT)->whereNotNull('template_id')->get();
        $this->assertNotEmpty($drafts);
        foreach ($drafts as $draft) {
            $this->assertSame('2026.2', $draft->template()->value('version'), $draft->report_type);
        }

        $rows = $this->reportsAudit()->slice($auditBefore)->values();
        $this->assertCount($drafts->count(), $rows, 'One audit row per moved draft.');
        $row = $rows->firstWhere('auditable_id', $capr->id);
        $this->assertNull($row->user_id, 'A system change.');
        $this->assertSame(['status' => 'draft', 'template_id' => $this->template('capr', '2026.1')->id, 'template_version' => '2026.1'], $row->old_values);
        $this->assertSame('2026.2', $row->new_values['template_version']);
        $this->assertStringStartsWith('System change:', $row->description);
        $this->assertStringContainsString('2026.2', $row->description);
        $this->assertNull(AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $fs->id)->where('action', 'cda_workflow')->first());

        $templateRows = AuditLog::query()->where('id', '>', $lastAudit)->where('auditable_type', CdaReportTemplate::class)->where('description', 'like', 'System change:%')->get();
        $this->assertCount(2 * count(ReportTypes::ALL), $templateRows, 'Each retire and each activation is audited.');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->before();
        app(PeriodOpener::class)->open(2026, $this->admin);
        $this->migration()->up();
        $templates = DB::table('cda_report_templates')->orderBy('id')->get()->toJson();
        $reports = DB::table('cda_reports')->orderBy('id')->get(['id', 'template_id', 'status'])->toJson();
        $audit = AuditLog::query()->count();

        $this->migration()->up();

        $this->assertSame($templates, DB::table('cda_report_templates')->orderBy('id')->get()->toJson());
        $this->assertSame($reports, DB::table('cda_reports')->orderBy('id')->get(['id', 'template_id', 'status'])->toJson());
        $this->assertSame($audit, AuditLog::query()->count());
    }

    /** A "2026.2" someone made in CDA Settings before the migration ran. */
    private function foreign20262(string $type, ?int $createdBy, ?array $definition = null): CdaReportTemplate
    {
        $definition ??= $this->template($type, '2026.1')->definition;

        return CdaReportTemplate::query()->create([
            'report_type' => $type, 'version' => '2026.2', 'name' => 'Own '.$type, 'effective_from' => '2026-01-01',
            'cooperative_types' => [], 'asset_classes' => [], 'definition' => $definition,
            'status' => CdaReportTemplate::STATUS_DRAFT, 'created_by' => $createdBy,
        ]);
    }

    public function test_a_2026_2_made_in_cda_settings_is_never_taken_for_the_migrations_own(): void
    {
        $this->before();
        $period = app(PeriodOpener::class)->open(2026, $this->admin);
        $byUser = $this->foreign20262('capr', $this->admin->id);
        $shipped = json_decode((string) file_get_contents(database_path('cda-templates/2026.2/sworn_statement.json')), true)['definition'];
        $shipped['meta']['source_doc'] = 'Edited in CDA Settings';
        $edited = $this->foreign20262('sworn_statement', null, $shipped);
        $byUser->forceFill(['status' => CdaReportTemplate::STATUS_ACTIVE, 'activated_at' => now()])->save();

        $this->artisan('cda:activate-templates-2026-2', ['--dry-run' => true])
            ->expectsOutputToContain('capr: left as it is: a 2026.2 made in CDA Settings is already there')
            ->expectsOutputToContain('sworn_statement: left as it is: a 2026.2 made in CDA Settings is already there')
            ->assertSuccessful();

        $this->migration()->up();

        foreach (['capr' => $byUser, 'sworn_statement' => $edited] as $type => $theirs) {
            $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template($type, '2026.1')->status, $type);
            $this->assertSame($this->template($type, '2026.1')->id, CdaReport::query()->where('period_id', $period->id)->where('report_type', $type)->value('template_id'), $type);
            $fields = ['status', 'definition', 'name', 'created_by', 'reason'];
            $this->assertEquals($theirs->only($fields), $theirs->fresh()->only($fields), "{$type}: their 2026.2 is untouched.");
        }

        $this->assertSame(CdaReportTemplate::STATUS_RETIRED, $this->template('abr', '2026.1')->status, 'Other types still move.');

        $this->migration()->down();

        $this->assertNotNull($byUser->fresh(), 'down() never deletes a 2026.2 it did not make.');
        $this->assertNotNull($edited->fresh());
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('abr', '2026.1')->status);
    }

    public function test_down_leaves_2026_1_retired_while_another_template_of_its_type_is_active(): void
    {
        $this->before();
        $migration = $this->migration();
        $migration->up();
        $newer = CdaReportTemplate::query()->create([
            'report_type' => 'abr', 'version' => '2026.3', 'name' => 'Own ABR', 'effective_from' => '2026-06-01',
            'cooperative_types' => [], 'asset_classes' => [], 'definition' => $this->template('abr', '2026.2')->definition,
            'status' => CdaReportTemplate::STATUS_ACTIVE, 'activated_at' => now(), 'created_by' => $this->admin->id,
        ]);

        $migration->down();

        $this->assertNull(CdaReportTemplate::query()->where('report_type', 'abr')->where('version', '2026.2')->first(), 'Its own unused 2026.2 goes.');
        $this->assertSame(CdaReportTemplate::STATUS_RETIRED, $this->template('abr', '2026.1')->status, 'Not two active templates.');
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $newer->fresh()->status);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('capr', '2026.1')->status);
    }

    public function test_a_type_with_its_own_active_template_is_left_as_it_is(): void
    {
        $this->before();
        $own = CdaReportTemplate::query()->create([
            'report_type' => 'abr', 'version' => '2026.1-own', 'name' => 'Own ABR', 'effective_from' => '2026-06-01',
            'cooperative_types' => [], 'asset_classes' => [], 'definition' => $this->template('abr', '2026.1')->definition,
            'status' => CdaReportTemplate::STATUS_ACTIVE, 'activated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertNull(CdaReportTemplate::query()->where('report_type', 'abr')->where('version', '2026.2')->first());
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('abr', '2026.1')->status);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $own->refresh()->status);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('capr', '2026.2')->status, 'Other types still move.');
    }

    public function test_down_moves_the_drafts_back_and_restores_2026_1(): void
    {
        $this->before();
        $period = app(PeriodOpener::class)->open(2026, $this->admin);
        $migration = $this->migration();
        $migration->up();
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        $audit = AuditLog::query()->count();

        $migration->down();

        $this->assertSame($this->template('capr', '2026.1')->id, $capr->refresh()->template_id);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('capr', '2026.1')->status);
        $this->assertNull($this->template('capr', '2026.1')->reason);
        $this->assertSame(0, CdaReportTemplate::query()->where('version', '2026.2')->count());
        $this->assertGreaterThan($audit, AuditLog::query()->count(), 'Moving back is audited; no audit row is removed.');

        $migration->up();
        $this->assertSame($this->template('capr', '2026.2')->id, $capr->refresh()->template_id);
    }

    public function test_down_keeps_2026_2_while_a_report_that_moved_on_uses_it(): void
    {
        $this->before();
        $period = app(PeriodOpener::class)->open(2026, $this->admin);
        $migration = $this->migration();
        $migration->up();
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        $capr->forceFill(['status' => CdaReport::STATUS_FINALIZED])->save();

        $migration->down();

        $this->assertSame($this->template('capr', '2026.2')->id, $capr->refresh()->template_id);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('capr', '2026.2')->status);
        $this->assertSame(CdaReportTemplate::STATUS_RETIRED, $this->template('capr', '2026.1')->status);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('sworn_statement', '2026.1')->status, 'Unused types go back.');
    }

    public function test_the_dry_run_lists_the_changes_and_writes_nothing(): void
    {
        $this->before();
        app(PeriodOpener::class)->open(2026, $this->admin);
        $templates = DB::table('cda_report_templates')->orderBy('id')->get()->toJson();
        $reports = DB::table('cda_reports')->orderBy('id')->get()->toJson();
        $audit = AuditLog::query()->count();

        $this->artisan('cda:activate-templates-2026-2', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('would retire 2026.1 and activate 2026.2')
            ->assertSuccessful();

        $this->assertSame($templates, DB::table('cda_report_templates')->orderBy('id')->get()->toJson());
        $this->assertSame($reports, DB::table('cda_reports')->orderBy('id')->get()->toJson());
        $this->assertSame($audit, AuditLog::query()->count());
    }
}
