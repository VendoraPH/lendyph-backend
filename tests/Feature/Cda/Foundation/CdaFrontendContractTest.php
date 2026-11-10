<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\CdaReportTemplate;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The C1 shapes against lendyph-frontend #510's `src/types/cda.ts`, where they
 * align, with the renames of CDA design §3:
 * - `CdaReportingPeriod`: id, year, default_deadline, extended_deadline, plus
 *   `effective_deadline` and `days_remaining` (the fiscal-year fields and
 *   `status` are dropped);
 * - `CdaTemplate`: every field, `report_type` with the renamed types;
 * - `CdaSettings`: `reminder_days` (`deadline_days` is `annual_deadline_days`
 *   and `notify_roles` is dropped);
 * - `CdaRegulatoryProfile`: `cte_status` none|valid|pending|expired,
 *   `cooperative_category` primary…union, `asset_class` micro…large;
 * - users as `{id, name}`, dates as YYYY-MM-DD.
 */
class CdaFrontendContractTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_period_carries_the_reporting_period_fields(): void
    {
        $period = $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated()->json('data');

        foreach (['id', 'year', 'period_start', 'period_end', 'default_deadline', 'extended_deadline', 'extension_reference', 'effective_deadline', 'days_remaining', 'year_end_captured_at', 'created_by', 'reports'] as $key) {
            $this->assertArrayHasKey($key, $period, $key);
        }
        $this->assertIsInt($period['id']);
        $this->assertSame(['id' => $this->admin->id, 'name' => 'Super Admin'], $period['created_by']);
        $this->assertNull($period['extended_deadline']);

        $report = $period['reports'][0];
        foreach (['id', 'report_type', 'part', 'title', 'title_part', 'status', 'sub_status', 'applicable', 'not_applicable_reason', 'restricted', 'template', 'default_due_on', 'due_override_on', 'due_override_reference', 'effective_deadline', 'days_remaining'] as $key) {
            $this->assertArrayHasKey($key, $report, $key);
        }

        $list = $this->getJson('/api/cda/periods')->assertOk()->json('data');
        $this->assertSame(2026, $list[0]['year']);
        $this->assertSame(11, $list[0]['report_count']);
    }

    public function test_a_template_carries_every_cda_template_field(): void
    {
        $data = $this->getJson('/api/cda/templates?report_type=officers_trainings')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $template = $data[0];

        foreach (['id', 'report_type', 'name', 'version', 'effective_from', 'effective_until', 'cooperative_types', 'asset_classes', 'status', 'definition', 'updated_at', 'verified', 'reason', 'excel_template', 'activated_at', 'created_by'] as $key) {
            $this->assertArrayHasKey($key, $template, $key);
        }
        $this->assertSame('officers_trainings', $template['report_type']);
        $this->assertSame([], $template['cooperative_types']);
        $this->assertSame('2026-01-01', $template['effective_from']);
        $this->assertIsArray($template['definition']['sections']);

        $types = collect($this->getJson('/api/cda/templates')->assertOk()->json('data'))->pluck('report_type')->unique()->sort()->values()->all();
        $this->assertSame(['abr', 'atir', 'capr', 'financial_statements', 'mediation', 'officers_trainings', 'performance_audit', 'pesos', 'social_audit', 'sworn_statement'], $types);

        $single = CdaReportTemplate::query()->where('report_type', 'capr')->firstOrFail();
        $this->getJson("/api/cda/templates/{$single->id}")->assertOk()->assertJsonPath('data.report_type', 'capr');
    }

    public function test_settings_and_profile_use_the_renamed_fields(): void
    {
        $settings = $this->getJson('/api/cda/settings')->assertOk()->json('data');
        $this->assertSame([30, 15, 7, 3], $settings['reminder_days']);
        $this->assertArrayHasKey('annual_deadline_days', $settings);
        $this->assertArrayNotHasKey('notify_roles', $settings);

        $profile = $this->putJson('/api/cda/regulatory-profile', [
            'cte_status' => 'valid', 'cooperative_category' => 'primary', 'asset_class' => 'micro',
            'cda_registration_date' => '2015-06-01', 'satellite_offices' => 2,
        ])->assertOk()->json('data');

        $this->assertSame('valid', $profile['stored']['cte_status']);
        $this->assertSame('primary', $profile['stored']['cooperative_category']);
        $this->assertSame('micro', $profile['stored']['asset_class']);
        $this->assertSame('2015-06-01', $profile['stored']['cda_registration_date']);
        $this->assertSame(2, $profile['stored']['satellite_offices']);
        foreach (['name', 'address', 'contact', 'branch_count', 'chairperson', 'general_manager', 'secretary', 'treasurer'] as $key) {
            $this->assertArrayHasKey('source', $profile['pulled'][$key], $key);
        }
        $this->assertArrayHasKey('asset_class_suggested', $profile);
    }
}
