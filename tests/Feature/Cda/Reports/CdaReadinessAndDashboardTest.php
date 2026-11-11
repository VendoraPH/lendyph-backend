<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportValue;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportEngine;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Readiness (design §4): equal-weighted checks (each required field, each
 * rule, each required document, one approval check), Info never fails, a
 * breakdown by section and component. The nightly `cda:revalidate` refreshes
 * drafts only, with one audit row per report whose values changed. The
 * dashboard (§3): the period's "Lendy CDA Readiness", counts, the next
 * deadline, a row per report, and the issues, without the issues of reports
 * the caller can't open. The calendar: one row per instance.
 */
class CdaReadinessAndDashboardTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_readiness_is_equal_weighted_and_info_never_fails(): void
    {
        $report = $this->caprWithTestTemplate();

        // Checks: 3 required fields, 2 rules, 0 documents, 1 approval. Passing:
        // the name and the member total, the gender rule and the info rule.
        $this->assertSame(67, $report->readiness);
        $this->assertSame(4, $report->readiness_breakdown['passed']);
        $this->assertSame(6, $report->readiness_breakdown['total']);
        $this->assertEqualsCanonicalizing(['passed' => 1, 'total' => 2, 'percent' => 50], $report->readiness_breakdown['by_component']['fields']);
        $this->assertEqualsCanonicalizing(['passed' => 0, 'total' => 1, 'percent' => 0], $report->readiness_breakdown['by_component']['approvals']);
        $this->assertEqualsCanonicalizing(['passed' => 0, 'total' => 1, 'percent' => 0], $report->readiness_breakdown['by_section']['employment']);
        $info = collect($report->issues)->firstWhere('rule_id', 'template_verified');
        $this->assertSame('info', $info['severity']);

        $this->actingAs($this->cdaStaff())->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 3]]])
            ->assertOk()->assertJsonPath('data.readiness', 83);

        $this->member(['gender' => null]);
        $this->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'refresh'])->assertOk()
            ->assertJsonPath('data.readiness', 67)
            ->assertJsonPath('data.warning_count', 1);
    }

    public function test_the_nightly_run_refreshes_drafts_only_and_audits_changes_only(): void
    {
        $report = $this->caprWithTestTemplate();
        $other = CdaReport::query()->where('period_id', $report->period_id)->where('report_type', 'social_audit')->sole();
        $other->forceFill(['status' => CdaReport::STATUS_FINALIZED])->save();

        auth()->forgetUser();
        $this->artisan('cda:revalidate')->assertSuccessful();
        $audits = fn () => AuditLog::query()->where('action', 'cda_report_refreshed')->where('auditable_id', $report->id)->count();
        $this->assertSame(1, $audits(), 'Only the first refresh changed values.');
        $this->assertSame(0, CdaReportValue::query()->where('report_id', $other->id)->count());

        $this->member();
        $this->artisan('cda:revalidate')->assertSuccessful();
        $this->assertSame(2, $audits());
        $row = AuditLog::query()->where('action', 'cda_report_refreshed')->where('auditable_id', $report->id)->latest('id')->first();
        $this->assertNull($row->user_id);
        $this->assertSame(1, $row->old_values['values']['membership.members_total']);
        $this->assertSame(2, $row->new_values['values']['membership.members_total']);
    }

    public function test_the_dashboard_and_calendar(): void
    {
        $report = $this->caprWithTestTemplate();
        $engine = app(ReportEngine::class);
        CdaReport::query()->where('period_id', $report->period_id)->where('id', '!=', $report->id)->get()
            ->each(fn (CdaReport $r) => $engine->refresh($r));

        $viewer = $this->userWith(['branches:view_all', CdaAccess::VIEW]);
        $data = $this->actingAs($viewer)->getJson('/api/cda/periods/2026/dashboard')->assertOk()->json('data');

        $this->assertSame('Lendy CDA Readiness', $data['readiness_label']);
        $this->assertSame(2026, $data['period']['year']);
        $this->assertCount(11, $data['reports']);
        $applicable = CdaReport::query()->where('period_id', $report->period_id)->where('applicable', true)->get();
        $this->assertSame((int) round($applicable->avg('readiness')), $data['readiness']);
        $this->assertSame(9, $data['counts']['applicable']);

        $capr = collect($data['reports'])->firstWhere('report_type', 'capr');
        $this->assertSame(['id', 'type', 'report_type', 'part', 'title', 'title_part', 'status', 'display_status', 'sub_status', 'readiness', 'critical', 'warnings', 'applicable', 'restricted', 'effective_deadline', 'days_remaining', 'updated_at'], array_keys($capr));
        $this->assertSame('validation_failed', $capr['display_status']);
        $this->assertSame('2027-04-30', $capr['effective_deadline']);

        $mediation = collect($data['reports'])->where('report_type', 'mediation');
        $this->assertTrue($mediation->every(fn ($row) => $row['restricted'] === true));
        $issueTypes = collect($data['issues'])->pluck('report_type')->unique();
        $this->assertTrue($issueTypes->contains('capr'));
        $this->assertFalse($issueTypes->contains('mediation'));
        $this->assertFalse($issueTypes->contains('financial_statements'));
        $this->assertSame('mediation', $data['next_deadline']['report_type']);
        $this->assertSame('2027-01-30', $data['next_deadline']['effective_deadline']);

        $auditor = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::VIEW_AUDIT]);
        $issueTypes = collect($this->actingAs($auditor)->getJson('/api/cda/periods/2026/dashboard')->json('data.issues'))->pluck('report_type')->unique();
        $this->assertTrue($issueTypes->contains('financial_statements'));

        $calendar = $this->actingAs($viewer)->getJson('/api/cda/periods/2026/calendar')->assertOk()->json('data');
        $this->assertCount(11, $calendar);
        $this->assertSame(['2026-07-30', '2027-01-30'], collect($calendar)->where('report_type', 'mediation')->pluck('effective_deadline')->sort()->values()->all());
        $this->assertSame(collect($calendar)->pluck('effective_deadline')->sort()->values()->all(), collect($calendar)->pluck('effective_deadline')->all());

        $this->getJson('/api/cda/periods/2031/dashboard')->assertNotFound();
    }
}
