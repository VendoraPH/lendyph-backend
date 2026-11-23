<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaReportTemplate;
use App\Models\CdaReportValue;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\TemplateDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The ATIR template 2026.3 (finish run, Phase 3 task "ATIR and ABR"): the
 * sector, subsector, region, province and city come from the regulatory
 * profile instead of being typed. It follows the 2026.2 update's pattern for
 * ATIR alone: draft ATIR reports move to 2026.3 with a system audit row each,
 * a report past draft keeps 2026.2, down() moves the drafts back, `--dry-run`
 * writes nothing, and the form stays "to verify".
 */
class CdaAtirTemplate20263MigrationTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_24_200000_activate_cda_atir_template_2026_3.php';

    /** Field => the profile column it reads. */
    private const SOURCED = [
        'tax.sector' => 'sector',
        'tax.subsector' => 'subsector',
        'tax.geographic_region' => 'address_region',
        'tax.geographic_province' => 'address_province',
        'tax.geographic_city' => 'address_city',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    private function template(string $version): CdaReportTemplate
    {
        return CdaReportTemplate::query()->where('report_type', 'atir')->where('version', $version)->firstOrFail();
    }

    /** The state before the migration ran: ATIR on 2026.2, no 2026.3. */
    private function before(): void
    {
        DB::table('cda_report_templates')->where('report_type', 'atir')->where('version', '2026.3')->delete();
        DB::table('cda_report_templates')->where('report_type', 'atir')->where('version', '2026.2')->update(['status' => 'active', 'reason' => null]);
    }

    private function atir(CdaReportingPeriod $period): CdaReport
    {
        return CdaReport::query()->where('period_id', $period->id)->where('report_type', 'atir')->firstOrFail();
    }

    /** @return array<string, array<string, mixed>> full key => field */
    private function fields(CdaReportTemplate $template): array
    {
        $fields = [];

        foreach ($template->definition['sections'] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[$section['key'].'.'.$field['key']] = $field;
            }
        }

        return $fields;
    }

    public function test_2026_3_is_active_still_to_verify_and_reads_the_five_fields_from_the_profile(): void
    {
        $new = $this->template('2026.3');
        $old = $this->template('2026.2');

        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $new->status);
        $this->assertFalse($new->definition['meta']['verified'], 'Still to verify against the official form.');
        $this->assertSame('2026-01-01', $new->effective_from->toDateString());
        $this->assertSame([], app(TemplateDefinitionValidator::class)->validate($new->definition, 'atir'));
        $this->assertSame(CdaReportTemplate::STATUS_RETIRED, $old->status);
        $this->assertStringContainsString('2026.3', (string) $old->reason);

        $fields = $this->fields($new);

        foreach (self::SOURCED as $key => $column) {
            $this->assertEquals(['key' => 'profile.field', 'params' => ['name' => $column]], $fields[$key]['source'] ?? null, $key);
            $this->assertArrayNotHasKey('overridable', $fields[$key], "{$key} is fixed at its source, the profile.");
        }

        foreach ($this->fields($old) as $key => $field) {
            $this->assertArrayHasKey($key, $fields, "2026.3 keeps {$key}, so a moved draft keeps its value.");
            $this->assertSame($field['type'], $fields[$key]['type'], "{$key} keeps its type.");
        }
    }

    public function test_a_refreshed_atir_report_reads_them_from_the_profile(): void
    {
        $this->withCte('valid', '2027-12-31');
        CdaRegulatoryProfile::current()->update([
            'sector' => 'Agriculture',
            'subsector' => 'Crop production',
            'address_region' => 'Region XIII (Caraga)',
            'address_province' => 'Agusan del Norte',
            'address_city' => 'Butuan City',
        ]);
        $report = $this->atir(app(PeriodOpener::class)->open(2026, $this->admin));
        $this->assertSame('2026.3', $report->template()->value('version'));

        app(ReportEngine::class)->refresh($report);

        $values = CdaReportValue::query()->where('report_id', $report->id)->whereIn('field_key', array_keys(self::SOURCED))->pluck('value', 'field_key')->all();
        ksort($values);
        $this->assertSame([
            'tax.geographic_city' => 'Butuan City',
            'tax.geographic_province' => 'Agusan del Norte',
            'tax.geographic_region' => 'Region XIII (Caraga)',
            'tax.sector' => 'Agriculture',
            'tax.subsector' => 'Crop production',
        ], $values);
    }

    public function test_a_draft_atir_report_moves_to_2026_3_with_a_system_audit_row_and_other_reports_stay(): void
    {
        $this->before();
        $period = app(PeriodOpener::class)->open(2026, $this->admin);
        $atir = $this->atir($period);
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        $caprTemplate = $capr->template_id;
        $this->assertSame($this->template('2026.2')->id, $atir->template_id);
        $lastAudit = (int) AuditLog::query()->max('id');

        $this->migration()->up();

        $new = $this->template('2026.3');
        $this->assertSame($new->id, $atir->refresh()->template_id);
        $this->assertNotNull($atir->stale_at, 'The moved draft asks for a refresh.');
        $this->assertSame($caprTemplate, $capr->refresh()->template_id, 'Only ATIR moves.');

        $rows = AuditLog::query()->where('id', '>', $lastAudit)->where('auditable_type', CdaReport::class)->where('action', 'cda_workflow')->get();
        $this->assertCount(1, $rows, 'One audit row for the one moved draft.');
        $row = $rows->first();
        $this->assertSame($atir->id, (int) $row->auditable_id);
        $this->assertNull($row->user_id, 'A system change.');
        // Compared as data: MySQL stores JSON with its keys reordered.
        $this->assertEquals(['status' => 'draft', 'template_id' => $this->template('2026.2')->id, 'template_version' => '2026.2'], $row->old_values);
        $this->assertEquals(['status' => 'draft', 'template_id' => $new->id, 'template_version' => '2026.3', 'system_change' => 'cda_atir_template_2026_3'], $row->new_values);
        $this->assertSame('System change: the Annual Tax Incentives Report (ATIR) 2026 draft moved from template 2026.2 to 2026.3, which reads the sector, '
            .'subsector, region, province and city from the regulatory profile (still to verify against the official form). Refresh the report to fill them '
            .'from the profile in Settings → CDA.', $row->description);

        $templateRows = AuditLog::query()->where('id', '>', $lastAudit)->where('auditable_type', CdaReportTemplate::class)->orderBy('id')->pluck('description')->all();
        $this->assertSame([
            'System change: CDA template Annual Tax Incentives Report (ATIR) 2026.3 activated (still to verify against the official form).',
            'System change: CDA template Annual Tax Incentives Report (ATIR) 2026.2 retired: Replaced by 2026.3, which reads the sector, subsector, region, '
                .'province and city from the regulatory profile (still to verify against the official form).',
        ], $templateRows);
    }

    public function test_a_report_past_draft_keeps_2026_2_and_a_second_run_changes_nothing(): void
    {
        $this->before();
        $atir = $this->atir(app(PeriodOpener::class)->open(2026, $this->admin));
        $atir->forceFill(['status' => CdaReport::STATUS_FOR_REVIEW])->save();
        $migration = $this->migration();

        $migration->up();

        $this->assertSame($this->template('2026.2')->id, $atir->refresh()->template_id, 'A report in review keeps the form it was prepared on.');
        $this->assertNull(AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $atir->id)->where('action', 'cda_workflow')->first());
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('2026.3')->status);

        $templates = DB::table('cda_report_templates')->orderBy('id')->get()->toJson();
        $reports = DB::table('cda_reports')->orderBy('id')->get(['id', 'template_id', 'status'])->toJson();
        $audit = AuditLog::query()->count();

        $migration->up();

        $this->assertSame($templates, DB::table('cda_report_templates')->orderBy('id')->get()->toJson());
        $this->assertSame($reports, DB::table('cda_reports')->orderBy('id')->get(['id', 'template_id', 'status'])->toJson());
        $this->assertSame($audit, AuditLog::query()->count());
    }

    public function test_down_moves_the_drafts_back_and_restores_2026_2(): void
    {
        $this->before();
        $atir = $this->atir(app(PeriodOpener::class)->open(2026, $this->admin));
        $migration = $this->migration();
        $migration->up();
        $this->assertSame($this->template('2026.3')->id, $atir->refresh()->template_id);
        $audit = AuditLog::query()->count();

        $migration->down();

        $this->assertSame($this->template('2026.2')->id, $atir->refresh()->template_id);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('2026.2')->status);
        $this->assertNull($this->template('2026.2')->reason);
        $this->assertNull(CdaReportTemplate::query()->where('report_type', 'atir')->where('version', '2026.3')->first());
        $this->assertGreaterThan($audit, AuditLog::query()->count(), 'Moving back is audited; no audit row is removed.');

        $migration->up();
        $this->assertSame($this->template('2026.3')->id, $atir->refresh()->template_id, 'Up again moves it again.');
    }

    public function test_an_atir_template_made_in_settings_is_left_as_it_is(): void
    {
        $this->before();
        $own = CdaReportTemplate::query()->create([
            'report_type' => 'atir', 'version' => '2026.2-own', 'name' => 'Own ATIR', 'effective_from' => '2026-06-01',
            'cooperative_types' => [], 'asset_classes' => [], 'definition' => $this->template('2026.2')->definition,
            'status' => CdaReportTemplate::STATUS_ACTIVE, 'activated_at' => now(), 'created_by' => $this->admin->id,
        ]);

        $this->artisan('cda:activate-atir-template-2026-3', ['--dry-run' => true])
            ->expectsOutputToContain('atir: left as it is: the template 2026.2-own, not 2026.2, is active.')
            ->assertSuccessful();
        $this->migration()->up();

        $this->assertNull(CdaReportTemplate::query()->where('report_type', 'atir')->where('version', '2026.3')->first());
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $this->template('2026.2')->status);
        $this->assertSame(CdaReportTemplate::STATUS_ACTIVE, $own->refresh()->status);
    }

    public function test_the_dry_run_lists_the_change_and_writes_nothing(): void
    {
        $this->before();
        app(PeriodOpener::class)->open(2026, $this->admin);
        $templates = DB::table('cda_report_templates')->orderBy('id')->get()->toJson();
        $reports = DB::table('cda_reports')->orderBy('id')->get()->toJson();
        $audit = AuditLog::query()->count();

        $this->artisan('cda:activate-atir-template-2026-3', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('atir: would retire 2026.2 and activate 2026.3; 1 draft report(s) would move.')
            ->assertSuccessful();

        $this->assertSame($templates, DB::table('cda_report_templates')->orderBy('id')->get()->toJson());
        $this->assertSame($reports, DB::table('cda_reports')->orderBy('id')->get()->toJson());
        $this->assertSame($audit, AuditLog::query()->count());
    }
}
