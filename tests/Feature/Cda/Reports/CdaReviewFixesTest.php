<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\AccountingYearClose;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportValue;
use App\Models\PortfolioDailySnapshot;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\Rational;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The C2 review's findings: restricted values and table rows never reach
 * audit rows (H1); request limits match the columns (M2); a refresh never
 * rewrites a report that left draft (M3); filing is blocked only by
 * documents needed at filing, a locked report's issues stay as finalized,
 * missing-data counts take only members who existed (M4); the stage lives
 * outside the snapshot (M5); a snapshot day's loan count is provisional
 * (M6); oversized numbers answer 422 (M7); the save audit row says what
 * else changed (L8); revert takes 1 (L9); a report that doesn't apply can't
 * be saved (L10); and a restricted report's figure stays out of another
 * report's issue messages.
 */
class CdaReviewFixesTest extends TestCase
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
        $this->preparer = $this->cdaStaff([CdaAccess::VIEW_MEDIATION, CdaAccess::VIEW_TAX, CdaAccess::VIEW_AUDIT]);
        $this->approver = $this->cdaStaff([CdaAccess::VIEW_MEDIATION, CdaAccess::VIEW_TAX, CdaAccess::VIEW_AUDIT]);
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

    private function save(CdaReport $report, array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => $fields]);
    }

    private function auditJson(CdaReport $report, string $action): string
    {
        return AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $report->id)->where('action', $action)
            ->get()->map(fn (AuditLog $row) => json_encode([$row->old_values, $row->new_values, $row->description], JSON_UNESCAPED_UNICODE))->implode("\n");
    }

    private function finalized(CdaReport $report, array $fields): CdaReport
    {
        $this->save($report, $fields)->assertOk();
        $this->act($report, 'submit_for_review')->assertOk();
        $this->act($report, 'approve', [], $this->approver)->assertOk();
        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk();

        return $report->refresh();
    }

    private function mediationDefinition(): array
    {
        return [
            'schema_version' => 1, 'meta' => ['verified' => true], 'parts' => ['h1', 'h2'],
            'deadline' => ['basis' => 'half_end', 'days_setting' => 'mediation_deadline_days'],
            'sections' => [
                ['key' => 'cases', 'title' => 'Cases', 'kind' => 'table', 'editable' => true, 'columns' => [
                    ['key' => 'case_number', 'label' => 'Case', 'format' => 'text'],
                    ['key' => 'complainant', 'label' => 'Complainant', 'format' => 'text'],
                ]],
                ['key' => 'summary', 'title' => 'Summary', 'kind' => 'fields', 'fields' => [
                    ['key' => 'cooperative', 'label' => 'Cooperative', 'type' => 'text', 'source' => ['key' => 'coop.name']],
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ]],
            ],
            'rules' => [],
        ];
    }

    public function test_h1_a_mediation_reports_audit_rows_hold_no_case_text(): void
    {
        $this->activeTemplate('mediation', $this->mediationDefinition());
        $report = $this->report($this->openYear(), 'mediation', 'h1');
        app(ReportEngine::class)->refresh($report);

        $this->save($report, [
            ['field_key' => 'cases', 'value' => [['case_number' => 'MC-2026-0001', 'complainant' => 'Juan Dela Cruz']]],
            ['field_key' => 'summary.notes', 'value' => 'Land dispute between neighbours', 'remarks' => 'Settled at the barangay'],
        ])->assertOk();

        $refreshed = $this->auditJson($report, 'cda_report_refreshed');
        $saved = $this->auditJson($report, 'cda_report_saved');
        $this->assertNotSame('', $refreshed);
        $this->assertStringNotContainsString('Binhi', $refreshed);
        foreach (['Juan Dela Cruz', 'MC-2026-0001', 'Land dispute', 'Settled at the barangay'] as $text) {
            $this->assertStringNotContainsString($text, $saved);
        }

        $row = AuditLog::query()->where('auditable_id', $report->id)->where('action', 'cda_report_saved')->sole();
        $this->assertEqualsCanonicalizing(['cases', 'summary.notes'], $row->new_values['fields']);
        $this->assertSame(2, $row->new_values['count']);
        $this->assertArrayNotHasKey('values', $row->new_values);
    }

    public function test_h1_a_tax_or_audit_reports_audit_rows_hold_no_figures(): void
    {
        $this->withCte('valid', '2028-12-31');
        $this->activeTemplate('atir', [
            'schema_version' => 1, 'meta' => ['verified' => true], 'parts' => ['annual'], 'applies' => ['requires_cte' => true],
            'sections' => [['key' => 'tax', 'title' => 'Tax', 'kind' => 'fields', 'fields' => [
                ['key' => 'members', 'label' => 'Members', 'type' => 'number', 'source' => ['key' => 'members.count']],
                ['key' => 'income_tax_exempt', 'label' => 'Income tax exempt', 'type' => 'money'],
            ]]],
            'rules' => [],
        ]);
        $report = $this->report($this->openYear(), 'atir');
        app(ReportEngine::class)->refresh($report);
        $this->save($report, [['field_key' => 'tax.income_tax_exempt', 'value' => '12345.67']])->assertOk();

        $refresh = AuditLog::query()->where('auditable_id', $report->id)->where('action', 'cda_report_refreshed')->sole();
        $this->assertSame(['tax.members'], $refresh->new_values['fields']);
        $this->assertArrayNotHasKey('values', $refresh->new_values);
        $this->assertArrayNotHasKey('values', $refresh->old_values);
        $this->assertStringNotContainsString('12345.67', $this->auditJson($report, 'cda_report_saved'));
    }

    public function test_h1_table_rows_never_reach_an_audit_row(): void
    {
        $definition = $this->testDefinition();
        $definition['sections'][] = ['key' => 'programs', 'title' => 'Programs', 'kind' => 'table', 'editable' => true, 'columns' => [
            ['key' => 'name', 'label' => 'Program', 'format' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
        ]];
        $report = $this->caprWithTestTemplate(['sections' => $definition['sections']]);

        $this->save($report, [['field_key' => 'programs', 'value' => [['name' => 'Scholarship for Ana Reyes', 'amount' => '5000'], ['name' => 'Tree planting', 'amount' => '1000']]]])->assertOk();

        $row = AuditLog::query()->where('auditable_id', $report->id)->where('action', 'cda_report_saved')->sole();
        $this->assertSame(['rows' => 2], $row->new_values['values']['programs']);
        $this->assertSame(['rows' => 0], $row->old_values['values']['programs']);
        $this->assertStringNotContainsString('Ana Reyes', $this->auditJson($report, 'cda_report_saved'));
    }

    public function test_m2_request_limits_match_the_columns(): void
    {
        $report = $this->caprWithTestTemplate();
        $report = $this->finalized($report, [['field_key' => 'employment.employees_regular', 'value' => 2]]);

        $this->act($report, 'reopen', ['reason' => str_repeat('a', 501)])->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'A reason takes up to 500 characters.']);
        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14', 'cda_reference_no' => str_repeat('R', 81)]])->assertStatus(422)
            ->assertJsonValidationErrors(['submission.cda_reference_no' => 'The CDA reference number takes up to 80 characters.']);
        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertOk();

        $submission = $report->submissions()->sole();
        $this->putJson("/api/cda/submissions/{$submission->id}", ['acknowledgment_no' => str_repeat('A', 81)])->assertStatus(422)
            ->assertJsonValidationErrors(['acknowledgment_no' => 'The acknowledgment number takes up to 80 characters.']);
    }

    public function test_m3_a_refresh_never_rewrites_a_report_that_left_draft(): void
    {
        $report = $this->caprWithTestTemplate();
        $stale = CdaReport::query()->findOrFail($report->id);
        CdaReport::query()->whereKey($report->id)->update(['status' => CdaReport::STATUS_FOR_REVIEW]);
        $this->member(['gender' => 'female']);

        $this->assertSame([], app(ReportEngine::class)->refresh($stale));
        $this->assertSame(1, CdaReportValue::query()->where('report_id', $report->id)->where('field_key', 'membership.members_total')->sole()->value);
        $this->assertSame(1, AuditLog::query()->where('auditable_id', $report->id)->where('action', 'cda_report_refreshed')->count());
    }

    public function test_m4_filing_is_blocked_only_by_documents_and_a_locked_reports_issues_stay(): void
    {
        $this->closeBooks(2026);
        $definition = $this->testDefinition();
        $definition['rules'][] = ['id' => 'year_closed', 'type' => 'check', 'check' => 'accounting.year_closed', 'severity' => 'critical', 'category' => 'financial_data', 'component' => 'accounting'];
        $report = $this->finalized($this->caprWithTestTemplate(['rules' => $definition['rules']]), [['field_key' => 'employment.employees_regular', 'value' => 2]]);
        $issues = $report->issues;

        AccountingYearClose::query()->where('closed_year', 2026)->update(['status' => AccountingYearClose::STATUS_REOPENED, 'closed_year' => null]);

        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertOk();
        $this->assertSame($issues, $report->refresh()->issues);
        $this->assertNull(collect($report->issues)->firstWhere('rule_id', 'year_closed'));
    }

    public function test_m4_missing_data_counts_only_members_who_existed_by_then(): void
    {
        $before = $this->member(['membership_date' => null]);
        $before->forceFill(['created_at' => '2026-03-01 09:00:00'])->save();
        $after = $this->member(['membership_date' => null]);
        $after->forceFill(['created_at' => '2026-08-01 09:00:00'])->save();
        $period = $this->openYear();

        $count = app(SourceRegistry::class)->resolve('members.missing', ['field' => 'membership_date', 'as_of' => '2026-06-30'], new SourceContext($period));

        $this->assertSame(1, $count->value);
    }

    public function test_m5_the_stage_lives_outside_the_snapshot(): void
    {
        $this->activeTemplate('sworn_statement', [
            'schema_version' => 1, 'meta' => ['verified' => true], 'parts' => ['annual'],
            'sections' => [['key' => 'statement', 'title' => 'Statement', 'kind' => 'fields', 'fields' => [
                ['key' => 'signatory_name', 'label' => 'Signatory', 'type' => 'text', 'required' => true],
            ]]],
            'rules' => [],
        ]);
        $report = $this->report($this->openYear(), 'sworn_statement');
        $report = $this->finalized($report, [['field_key' => 'statement.signatory_name', 'value' => 'Maria Santos']]);
        $snapshot = CdaReportSnapshot::query()->sole();
        $this->assertArrayNotHasKey('stage', json_decode($snapshot->payload, true));

        $this->act($report, 'set_stage', ['stage' => 'generated'])->assertOk()->assertJsonPath('data.stage', 'generated');
        $this->act($report, 'set_stage', ['stage' => 'for_signature'])->assertOk();
        $this->getJson("/api/cda/reports/{$report->id}")->assertOk()->assertJsonPath('data.stage', 'for_signature')->assertJsonPath('data.locked', true);
        $this->assertSame($snapshot->payload, $snapshot->refresh()->payload);
        $this->postJson("/api/cda/reports/{$report->id}/snapshots/1/verify")->assertJsonPath('data.checksum_matches', true);
    }

    public function test_m5_financial_statements_move_through_their_stages_after_finalize(): void
    {
        $this->activeTemplate('financial_statements', [
            'schema_version' => 1, 'meta' => ['verified' => true], 'parts' => ['annual'],
            'sections' => [['key' => 'auditor', 'title' => 'Auditor', 'kind' => 'fields', 'fields' => [
                ['key' => 'auditor_name', 'label' => 'Auditor', 'type' => 'text', 'required' => true],
                ['key' => 'auditor_accreditation_no', 'label' => 'Accreditation', 'type' => 'text', 'required' => true],
                ['key' => 'audit_opinion', 'label' => 'Opinion', 'type' => 'text', 'required' => true],
                ['key' => 'audit_report_date', 'label' => 'Report date', 'type' => 'date', 'required' => true],
            ]]],
            'rules' => [],
        ]);
        $report = $this->report($this->openYear(), 'financial_statements');
        $report = $this->finalized($report, [
            ['field_key' => 'auditor.auditor_name', 'value' => 'Reyes & Co.'],
            ['field_key' => 'auditor.auditor_accreditation_no', 'value' => 'BOA-1234'],
            ['field_key' => 'auditor.audit_opinion', 'value' => 'unmodified'],
            ['field_key' => 'auditor.audit_report_date', 'value' => '2027-01-10'],
        ]);

        $this->act($report, 'set_stage', ['stage' => 'for_external_audit'])->assertOk()->assertJsonPath('data.stage', 'for_external_audit');
        $this->act($report, 'set_stage', ['stage' => 'audited'])->assertOk()->assertJsonPath('data.stage', 'audited')->assertJsonPath('data.status', 'finalized');
    }

    public function test_m6_a_snapshot_days_loan_count_is_provisional_with_a_note(): void
    {
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => '2026-06-30', 'overdue_loans_count' => 1, 'overdue_amount' => '10.00', 'total_outstanding' => '100.00',
            'outstanding_principal' => '90.00', 'par_1_amount' => '0', 'par_30_amount' => '0', 'par_90_amount' => '0', 'created_at' => now(),
        ]);
        $period = $this->openYear();
        $registry = app(SourceRegistry::class);

        $count = $registry->resolve('loans.outstanding', ['measure' => 'count', 'as_of' => '2026-06-30'], new SourceContext($period));
        $this->assertTrue($count->provisional);
        $this->assertStringContainsString('loan count', (string) $count->meta['note']);
        $this->assertFalse($registry->resolve('loans.outstanding', ['as_of' => '2026-06-30'], new SourceContext($period))->provisional);
    }

    public function test_m7_oversized_numbers_answer_422_never_500(): void
    {
        $report = $this->caprWithTestTemplate();

        $this->save($report, [['field_key' => 'employment.payroll', 'value' => '123456789012345.123456789']])->assertStatus(422)->assertJsonValidationErrors(['fields.0.value']);
        $this->save($report, [['field_key' => 'employment.employees_regular', 'value' => '99999999999999999999']])->assertStatus(422)->assertJsonValidationErrors(['fields.0.value']);
        $this->save($report, [['field_key' => 'employment.payroll', 'value' => '9999999999999999.99']])->assertStatus(422);
        $this->save($report, [['field_key' => 'employment.payroll', 'value' => '999999999999.99']])->assertOk();

        $this->expectException(InvalidArgumentException::class);
        Rational::of('1234567890.123456789');
    }

    public function test_l8_the_save_audit_row_says_what_else_changed(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->save($report, [
            ['field_key' => 'general.registration_no', 'value' => '9520-1', 'override_reason' => 'As on the certificate'],
            ['field_key' => 'membership.members_total', 'remarks' => 'Checked against the register'],
            ['field_key' => 'employment.employees_regular', 'evidence' => [['label' => 'Payroll list']]],
        ])->assertOk();

        $row = AuditLog::query()->where('auditable_id', $report->id)->where('action', 'cda_report_saved')->sole();
        $this->assertSame(['is_override' => true, 'override_reason' => 'As on the certificate'], $row->new_values['overrides']['general.registration_no']);
        $this->assertSame(['is_override' => false, 'override_reason' => null], $row->old_values['overrides']['general.registration_no']);
        $this->assertSame(['membership.members_total'], $row->new_values['remarks_changed']);
        $this->assertSame(['employment.employees_regular'], $row->new_values['evidence_changed']);
        $this->assertStringNotContainsString('Checked against the register', json_encode($row->new_values));
    }

    public function test_l9_revert_takes_one(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->save($report, [['field_key' => 'general.registration_no', 'value' => '9520-1', 'override_reason' => 'As on the certificate']])->assertOk();

        $this->save($report, [['field_key' => 'general.registration_no', 'revert' => '1']])->assertOk();

        $this->assertFalse(CdaReportValue::query()->where('report_id', $report->id)->where('field_key', 'general.registration_no')->sole()->is_override);
    }

    public function test_l10_a_report_that_doesnt_apply_cant_be_saved(): void
    {
        $period = $this->openYear();
        $atir = $this->report($period, 'atir');
        $this->assertFalse($atir->applicable);

        $this->save($atir, [['field_key' => 'tax.income_tax_exempt', 'value' => '1.00']])->assertStatus(422)
            ->assertJsonPath('message', 'Applies only to a cooperative holding a valid Certificate of Tax Exemption (CTE) for the period.');
    }

    public function test_l11_an_empty_sex_reads_as_not_recorded(): void
    {
        $period = $this->openYear();
        Borrower::query()->update(['gender' => null]);
        $registry = app(SourceRegistry::class);

        $this->assertSame(1, $registry->resolve('members.count', ['gender' => 'unspecified'], new SourceContext($period))->value);
        $this->assertSame(1, $registry->resolve('members.missing', ['field' => 'gender'], new SourceContext($period))->value);
    }

    public function test_an_issue_never_shows_a_restricted_reports_figure(): void
    {
        $definition = $this->testDefinition();
        $definition['sections'][2]['fields'][] = ['key' => 'total_assets', 'label' => 'Total assets', 'type' => 'money'];
        $definition['rules'][] = ['id' => 'assets_match_fs', 'type' => 'compare', 'left' => 'total_assets', 'op' => 'eq', 'right' => 'report:financial_statements.statements.total_assets', 'severity' => 'warning', 'category' => 'accounting_mismatch', 'component' => 'accounting'];
        $report = $this->caprWithTestTemplate(['sections' => $definition['sections'], 'rules' => $definition['rules']]);
        $this->report($report->period, 'financial_statements')->values()->create(['field_key' => 'statements.total_assets', 'value' => '987654.32']);

        $issues = $this->save($report, [['field_key' => 'employment.total_assets', 'value' => '1000.00']], $this->cdaStaff())->assertOk()->json('data.issues');

        $message = collect($issues)->firstWhere('rule_id', 'assets_match_fs')['message'];
        $this->assertSame('Total assets (1000.00) doesn\'t match the Audited Financial Statements.', $message);
        $this->assertStringNotContainsString('987654', json_encode($report->refresh()->issues));
    }
}
