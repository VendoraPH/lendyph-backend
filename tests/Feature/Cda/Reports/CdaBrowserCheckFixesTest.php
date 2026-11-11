<?php

namespace Tests\Feature\Cda\Reports;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\ReportTypes;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The CDA browser check's backend items:
 * - the dashboard, calendar and next deadline leave restricted reports out
 *   entirely for a viewer without their view (rows, counts, readiness);
 * - a new year computes at once, CAPR's unclosed-year Critical included;
 * - profile select values and document rules read in words, with plurals;
 * - the template check's own category, CDA audit targets and attribute words;
 * - document checks needed at filing are live on a locked report, and the
 *   filing refusal names the missing documents.
 */
class CdaBrowserCheckFixesTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    private User $preparer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);
        $this->preparer = $this->cdaStaff();
        $this->approver = $this->cdaStaff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function act(CdaReport $report, string $action, array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => $action, ...$body]);
    }

    /** The test template with the given rules and documents, filled in, submitted and approved. */
    private function approvedReport(array $overrides): CdaReport
    {
        $report = $this->caprWithTestTemplate($overrides);
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [
            ['field_key' => 'employment.employees_regular', 'value' => 5],
        ]])->assertOk();
        $this->act($report, 'submit_for_review')->assertOk();
        $this->act($report, 'approve', [], $this->approver)->assertOk();

        return $report->refresh();
    }

    private function uploadFinal(CdaReport $report, string $category): void
    {
        $document = CdaDocument::factory()->create(['period_id' => $report->period_id, 'category' => $category, 'report_id' => $report->id]);
        CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id, 'final_key' => (string) $document->id]);
    }

    public function test_the_dashboard_and_calendar_leave_restricted_reports_out_entirely(): void
    {
        $period = $this->openYear();
        $engine = app(ReportEngine::class);
        CdaReport::query()->where('period_id', $period->id)->get()->each(fn (CdaReport $report) => $engine->refresh($report));
        // A restricted report with critical issues the viewer must never count.
        CdaReport::query()->where('period_id', $period->id)->where('report_type', 'financial_statements')
            ->update(['critical_count' => 7, 'warning_count' => 5, 'readiness' => 0]);

        $viewer = $this->userWith([BranchAccess::VIEW_ALL, CdaAccess::VIEW]);
        $full = $this->userWith([BranchAccess::VIEW_ALL, CdaAccess::VIEW, CdaAccess::VIEW_AUDIT, CdaAccess::VIEW_TAX, CdaAccess::VIEW_MEDIATION]);

        $visible = CdaReport::query()->where('period_id', $period->id)->get()
            ->filter(fn (CdaReport $report) => ReportTypes::restriction($report->report_type) === null);
        $visibleApplicable = $visible->where('applicable', true);

        $data = $this->actingAs($viewer)->getJson('/api/cda/periods/2026/dashboard')->assertOk()->json('data');
        $types = collect($data['reports'])->pluck('report_type')->unique()->sort()->values()->all();
        $this->assertSame(['capr', 'officers_trainings', 'pesos', 'social_audit', 'sworn_statement'], $types);
        $this->assertFalse(collect($data['reports'])->contains('restricted', true));
        $this->assertSame($visible->count(), $data['counts']['total']);
        $this->assertSame($visibleApplicable->count(), $data['counts']['applicable']);
        $this->assertSame((int) $visibleApplicable->sum('critical_count'), $data['counts']['critical']);
        $this->assertSame((int) $visibleApplicable->sum('warning_count'), $data['counts']['warnings']);
        $this->assertSame($visible->count(), array_sum($data['counts']['by_status']));
        $this->assertSame((int) round($visibleApplicable->avg('readiness')), $data['readiness']);
        $this->assertSame('capr', $data['next_deadline']['report_type'], 'The nearest deadline the viewer may see (mediation H2 is hidden).');
        $text = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach (['Mediation and Conciliation Report', 'Audited Financial Statements', 'Performance Audit Report', 'ATIR', 'ABR'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $text);
        }

        $calendar = $this->getJson('/api/cda/periods/2026/calendar')->assertOk()->json('data');
        $this->assertSame($types, collect($calendar)->pluck('report_type')->unique()->sort()->values()->all());

        $all = $this->actingAs($full)->getJson('/api/cda/periods/2026/dashboard')->assertOk()->json('data');
        $this->assertCount(11, $all['reports']);
        $this->assertSame('mediation', $all['next_deadline']['report_type']);
        $this->assertGreaterThan($data['counts']['critical'], $all['counts']['critical']);
        $this->assertCount(11, $this->getJson('/api/cda/periods/2026/calendar')->json('data'));
    }

    public function test_a_new_year_computes_at_once_with_the_unclosed_year_critical(): void
    {
        $this->actingAs($this->cdaStaff())->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $period = CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();

        $capr = $this->report($period, 'capr');
        $this->assertNotNull($capr->refreshed_at, 'CAPR was refreshed when the year opened.');
        $this->assertGreaterThan(0, $capr->readiness);
        $this->assertGreaterThan(0, $capr->critical_count);
        $yearCritical = collect($capr->issues)->first(fn (array $issue) => $issue['severity'] === 'critical' && $issue['category'] === 'financial_data');
        $this->assertNotNull($yearCritical, 'The unclosed 2026 books raise a Critical issue at once.');
        $this->assertSame(1, $capr->values()->where('field_key', 'membership.members_total')->value('value'));

        $applicable = CdaReport::query()->where('period_id', $period->id)->where('applicable', true)->get();
        $this->assertTrue($applicable->every(fn (CdaReport $report) => $report->refreshed_at !== null));
        $this->assertSame(0, CdaReport::query()->where('period_id', $period->id)->where('applicable', false)->whereNotNull('refreshed_at')->count());

        $dashboard = $this->getJson('/api/cda/periods/2026/dashboard')->assertOk()->json('data');
        $this->assertSame('validation_failed', collect($dashboard['reports'])->firstWhere('report_type', 'capr')['display_status']);
    }

    public function test_profile_select_values_read_in_words_and_the_stored_value_stays_the_key(): void
    {
        CdaRegulatoryProfile::current()->update(['cooperative_category' => 'primary', 'asset_class' => 'micro', 'cte_status' => 'pending', 'cooperative_type' => 'credit']);
        $definition = $this->testDefinition();
        $definition['sections'][0]['fields'] = [
            ...$definition['sections'][0]['fields'],
            ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'source' => ['key' => 'profile.field', 'params' => ['name' => 'cooperative_category']]],
            ['key' => 'asset_class', 'label' => 'Asset size', 'type' => 'text', 'source' => ['key' => 'profile.field', 'params' => ['name' => 'asset_class']]],
            ['key' => 'cte_status', 'label' => 'CTE status', 'type' => 'text', 'source' => ['key' => 'profile.field', 'params' => ['name' => 'cte_status']]],
            ['key' => 'cooperative_type', 'label' => 'Type', 'type' => 'text', 'source' => ['key' => 'profile.field', 'params' => ['name' => 'cooperative_type']]],
        ];
        $report = $this->caprWithTestTemplate(['sections' => $definition['sections']]);

        $fields = collect($this->actingAs($this->preparer)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data.sections.0.fields'))->keyBy('key');
        $this->assertSame('Primary', $fields['category']['value']);
        $this->assertSame('Micro', $fields['asset_class']['value']);
        $this->assertSame('Application pending', $fields['cte_status']['value']);
        $this->assertSame('Credit', $fields['cooperative_type']['value']);
        $this->assertSame('primary', CdaRegulatoryProfile::current()->fresh()->cooperative_category);
    }

    public function test_document_issues_name_the_document_in_words_with_plurals(): void
    {
        $definition = $this->testDefinition();
        $rules = [...$definition['rules'], ['id' => 'signed_capr_uploaded', 'type' => 'document', 'document_category' => 'capr', 'stage' => 'submit', 'final' => true, 'severity' => 'critical', 'category' => 'compliance_document', 'component' => 'documents'],
            ['id' => 'sworn_uploaded', 'type' => 'document', 'document_category' => 'sworn_statement', 'stage' => 'submit', 'final' => true, 'severity' => 'warning', 'category' => 'compliance_document', 'component' => 'documents']];
        $documents = [
            ['key' => 'signed_capr', 'category' => 'capr', 'label' => 'Signed CAPR', 'stage' => 'submit', 'severity' => 'critical'],
            ['key' => 'training_certificates', 'category' => 'certificates', 'label' => 'Training certificates', 'stage' => 'submit', 'severity' => 'warning'],
        ];
        $report = $this->caprWithTestTemplate(['rules' => $rules, 'documents' => $documents]);

        $messages = collect($report->issues)->keyBy('id')->map(fn (array $issue) => $issue['message']);
        $this->assertSame('The signed CAPR (final copy) hasn\'t been uploaded.', $messages['rule:signed_capr_uploaded']);
        $this->assertSame('The sworn statement of compliance (final copy) hasn\'t been uploaded.', $messages['rule:sworn_uploaded']);
        $this->assertSame('Signed CAPR hasn\'t been uploaded.', $messages['document:signed_capr']);
        $this->assertSame('Training certificates haven\'t been uploaded.', $messages['document:training_certificates']);
    }

    public function test_the_template_check_reads_as_template_not_yet_verified(): void
    {
        $report = $this->caprWithTestTemplate();

        $issue = collect($report->issues)->firstWhere('rule_id', 'template_verified');
        $this->assertSame('template_unverified', $issue['category']);
        $this->assertSame('info', $issue['severity']);
    }

    public function test_cda_audit_targets_read_cda_in_capitals(): void
    {
        $this->assertSame('CDA document', AuditLogResource::typeInWords('CdaDocument'));
        $this->assertSame('CDA officer', AuditLogResource::typeInWords('CdaOfficer'));
        $this->assertSame('CDA reporting period', AuditLogResource::typeInWords('CdaReportingPeriod'));
        $this->assertSame('Member document', AuditLogResource::typeInWords('BorrowerDocument'));

        $document = CdaDocument::factory()->create(['category' => 'mediation', 'title' => 'Hidden']);
        $this->actingAs($this->userWith(['audit_logs:view', BranchAccess::VIEW_ALL]));
        $row = collect($this->getJson('/api/audit-logs?per_page=100')->assertOk()->json('data'))
            ->first(fn (array $row) => ($row['target']['id'] ?? null) === $document->id && $row['auditable_type'] === 'CdaDocument');
        $this->assertNotNull($row);
        $this->assertSame("CDA document #{$document->id}", $row['target']['label']);
        $this->assertTrue(AuditLog::query()->where('auditable_type', CdaDocument::class)->exists());
    }

    public function test_cda_validation_messages_name_fields_in_lower_case_words(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->actingAs($this->preparer);

        $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'general.registration_no', 'value' => 'X', 'override_reason' => ['x']]]])
            ->assertStatus(422)->assertJsonPath('errors', ['fields.0.override_reason' => ['The override reason field must be a string.']]);

        $report->forceFill(['status' => CdaReport::STATUS_FINALIZED])->save();
        $this->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'mark_submitted', 'submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14', 'cda_reference_no' => ['x']]])
            ->assertStatus(422)->assertJsonPath('errors', ['submission.cda_reference_no' => ['The CDA reference number field must be a string.']]);
        $this->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'reopen', 'reason' => ['x']])
            ->assertStatus(422)->assertJsonPath('errors', ['reason' => ['The reason field must be a string.']]);
    }

    public function test_a_locked_report_checks_its_filing_documents_live_and_the_refusal_names_them(): void
    {
        $definition = $this->testDefinition();
        $rules = [...$definition['rules'], ['id' => 'signed_capr_uploaded', 'type' => 'document', 'document_category' => 'capr', 'stage' => 'submit', 'final' => true, 'severity' => 'critical', 'category' => 'compliance_document', 'component' => 'documents']];
        $documents = [
            ['key' => 'signed_capr', 'category' => 'capr', 'label' => 'Signed CAPR', 'stage' => 'submit', 'severity' => 'critical'],
            ['key' => 'training_certificates', 'category' => 'certificates', 'label' => 'Training certificates', 'stage' => 'submit', 'severity' => 'critical'],
        ];
        $report = $this->approvedReport(['rules' => $rules, 'documents' => $documents]);
        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk();

        $view = fn () => $this->actingAs($this->preparer)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data');
        $before = $view();
        $ids = collect($before['issues'])->pluck('id');
        $this->assertTrue($ids->contains('rule:signed_capr_uploaded'));
        $this->assertTrue($ids->contains('document:training_certificates'));
        $this->assertTrue($ids->contains('document:signed_capr'));
        $this->assertSame(3, $before['critical_count']);
        $infoBefore = collect($before['issues'])->firstWhere('rule_id', 'template_verified');

        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertStatus(422)
            ->assertJsonPath('message', 'Upload the signed CAPR (final copy) and training certificates before recording the filing.');

        $this->uploadFinal($report, 'capr');

        $after = $view();
        $ids = collect($after['issues'])->pluck('id');
        $this->assertFalse($ids->contains('rule:signed_capr_uploaded'), 'The missing-document Critical goes once the file is uploaded.');
        $this->assertFalse($ids->contains('document:signed_capr'));
        $this->assertTrue($ids->contains('document:training_certificates'));
        $this->assertSame(1, $after['critical_count']);
        $this->assertSame($infoBefore, collect($after['issues'])->firstWhere('rule_id', 'template_verified'), 'Other issues stay frozen.');
        $this->assertSame('finalized', $after['status']);

        $full = $this->userWith([BranchAccess::VIEW_ALL, CdaAccess::VIEW]);
        $row = collect($this->actingAs($full)->getJson('/api/cda/periods/2026/dashboard')->json('data.reports'))->firstWhere('id', $report->id);
        $this->assertSame(1, $row['critical']);

        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertStatus(422)
            ->assertJsonPath('message', 'Upload training certificates before recording the filing.');

        $this->uploadFinal($report, 'certificates');
        $this->assertSame(0, $view()['critical_count']);
        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertOk();
        $this->assertSame(3, CdaReport::query()->whereKey($report->id)->value('critical_count'), 'The stored issues are never rewritten.');
    }
}
