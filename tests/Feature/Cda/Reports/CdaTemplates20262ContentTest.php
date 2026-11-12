<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaOfficer;
use App\Models\CdaReport;
use App\Models\CdaReportValue;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\ReportTypes;
use App\Services\Cda\TemplateDefinitionValidator;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The 2026.2 templates' new content (gap round B4) on a seeded year: every
 * sourced field of every report resolves, the new CAPR, financial statement,
 * performance audit, social audit, sworn statement, ATIR and ABR fields are
 * there, and the Audited stage needs the auditor fields the template names
 * (`meta.auditor_fields`), falling back to the four fixed ones.
 */
class CdaTemplates20262ContentTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    private User $preparer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->preparer = $this->cdaStaff([CdaAccess::VIEW_MEDIATION, CdaAccess::VIEW_TAX, CdaAccess::VIEW_AUDIT]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> field key => value */
    private function refreshed(CdaReport $report): array
    {
        app(ReportEngine::class)->refresh($report);

        return CdaReportValue::query()->where('report_id', $report->id)->pluck('value', 'field_key')->all();
    }

    private function fieldKeys(CdaReport $report): array
    {
        $keys = [];

        foreach ($report->template->definition['sections'] as $section) {
            if ($section['kind'] === 'table') {
                $keys[] = $section['key'];

                continue;
            }

            foreach ($section['fields'] as $field) {
                $keys[] = $section['key'].'.'.$field['key'];
            }
        }

        return $keys;
    }

    public function test_every_sourced_field_of_every_2026_2_report_resolves_on_a_seeded_year(): void
    {
        $this->withCte();
        $loan = $this->createReleasedLoan(['start_date' => '2026-03-01']);
        $loan->borrower->update(['membership_date' => '2025-01-10']);
        $this->member(['membership_date' => '2025-05-01']);
        $this->member(['membership_date' => '2026-02-01']);
        $period = $this->openYear();

        foreach (CdaReport::query()->with('template')->where('period_id', $period->id)->get() as $report) {
            $this->assertSame('2026.2', $report->template->version, $report->report_type);
            $this->refreshed($report);

            foreach (CdaReportValue::query()->where('report_id', $report->id)->whereNotNull('source_key')->get() as $row) {
                $this->assertArrayNotHasKey('error', (array) $row->source_value, "{$report->report_type} {$row->field_key}: ".json_encode($row->source_value));
            }
        }
    }

    public function test_capr_reads_activity_growth_active_loans_and_share_movements(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => '2026-03-01']);
        $loan->forceFill(['released_at' => '2026-03-01 09:00:00'])->save();
        $loan->borrower->update(['membership_date' => '2025-01-10']);
        $this->member(['membership_date' => '2025-05-01']);
        $this->member(['membership_date' => '2026-02-01']);
        $this->member(['membership_date' => '2026-04-01']);
        ShareCapitalLedger::query()->create(['borrower_id' => $loan->borrower_id, 'date' => '2026-02-01', 'description' => 'Paid', 'credit' => '1500.50', 'debit' => '0']);
        ShareCapitalLedger::query()->create(['borrower_id' => $loan->borrower_id, 'date' => '2026-04-01', 'description' => 'Withdrawn', 'credit' => '0', 'debit' => '200.00']);

        $values = $this->refreshed($this->report($this->openYear(), 'capr'));

        $this->assertSame(4, $values['membership.members_total']);
        $this->assertSame(1, $values['membership.members_active']);
        $this->assertSame(3, $values['membership.members_inactive']);
        $this->assertSame(2, $values['membership.members_prior_year']);
        $this->assertSame(2, $values['membership.members_growth']);
        $this->assertSame('100.00', $values['membership.members_growth_rate']);
        $this->assertSame(1, $values['loans.loans_active_count']);
        $this->assertSame('1500.50', $values['capital.share_capital_received']);
        $this->assertSame('200.00', $values['capital.share_capital_withdrawn']);
        $this->assertSame('1300.50', $values['capital.share_capital_net_movement']);
    }

    public function test_the_new_fields_of_each_report_are_on_its_2026_2_template(): void
    {
        $period = $this->openYear();
        $expect = [
            'financial_statements' => ['income_statement', 'equity_changes', 'cash_flow', 'auditor.auditor_name', 'auditor.auditor_firm', 'auditor.auditor_cpa_no',
                'auditor.auditor_accreditation_no', 'auditor.accreditation_valid_until', 'auditor.audit_date', 'auditor.audit_opinion', 'auditor.audit_remarks'],
            'performance_audit' => ['checklist.general_assembly', 'checklist.board_of_directors', 'checklist.committees', 'checklist.management',
                'checklist.organizational_structure', 'checklist.policies', 'checklist.internal_controls', 'checklist.operations',
                'checklist.compliance_with_laws', 'checklist.human_resources', 'financial.pesos_score', 'financial.pesos_rating',
                'summary.audit_summary', 'summary.findings', 'summary.recommendations', 'summary.management_response', 'summary.target_date',
                'auditor.auditor_name', 'auditor.audit_date'],
            'social_audit' => ['assessment.observations', 'assessment.findings', 'assessment.recommendations', 'assessment.impact_assessment'],
            'sworn_statement' => ['officers', 'notary.notary_public', 'notary.notary_commission_no', 'notary.notary_commission_valid_until',
                'notary.doc_no', 'notary.page_no', 'notary.book_no', 'notary.series_of', 'notary.notarized_on', 'notary.notarized_at'],
            'atir' => ['tax.sector', 'tax.subsector', 'tax.gross_sales', 'tax.geographic_region', 'tax.geographic_province', 'tax.geographic_city', 'tax.geographic_coverage'],
            'abr' => ['benefits.patronage_refund', 'employees.employees_benefited', 'employees.employee_benefits', 'community.community_beneficiaries',
                'community.community_benefits', 'other_beneficiaries.other_beneficiaries_count', 'other_beneficiaries.other_benefits'],
        ];

        foreach ($expect as $type => $keys) {
            $report = $this->report($period, $type)->load('template');
            $this->assertSame([], array_values(array_diff($keys, $this->fieldKeys($report))), $type);
        }

        $fs = $this->report($period, 'financial_statements')->load('template')->template->definition;
        $this->assertCount(5, $fs['documents']);
        $this->assertSame(['financial_statements', 'audit_report', 'management_representation', 'management_responsibility', 'fs_notes'], array_column($fs['documents'], 'category'));

        foreach (['capr', 'financial_statements'] as $type) {
            $rules = $this->report($period, $type)->load('template')->template->definition['rules'];
            $this->assertContains('savings.gl_matches', array_column($rules, 'check'), $type);
        }
    }

    public function test_the_sworn_statement_lists_the_officers_serving_in_the_year(): void
    {
        CdaOfficer::factory()->create(['name' => 'Rosa Lim', 'position' => 'chairperson']);

        $values = $this->refreshed($this->report($this->openYear(), 'sworn_statement'));

        $this->assertSame('Rosa Lim', $values['officers'][0]['name']);
    }

    private function fsReady(array $fields): CdaReport
    {
        $report = $this->report($this->openYear(), 'financial_statements');

        foreach ($fields as $key => $value) {
            CdaReportValue::query()->create(['report_id' => $report->id, 'field_key' => $key, 'value' => $value]);
        }

        foreach (['financial_statements', 'audit_report', 'management_representation', 'management_responsibility', 'fs_notes'] as $category) {
            $document = CdaDocument::factory()->create(['period_id' => $report->period_id, 'category' => $category, 'report_id' => $report->id]);
            CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id]);
        }

        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'set_stage', 'stage' => 'for_external_audit'])->assertOk();

        return $report;
    }

    private const OLD_FOUR = [
        'auditor.auditor_name' => 'Reyes & Co.',
        'auditor.auditor_accreditation_no' => 'BOA-1234',
        'auditor.audit_opinion' => 'unmodified',
        'auditor.audit_report_date' => '2027-01-10',
    ];

    public function test_audited_needs_every_auditor_field_the_template_names(): void
    {
        $report = $this->fsReady(self::OLD_FOUR);

        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'set_stage', 'stage' => 'audited'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.stage.0', 'Audited needs these auditor details first: audit firm, CPA number, accreditation valid until and audit date.');

        foreach (['auditor.auditor_firm' => 'Reyes & Co. CPAs', 'auditor.auditor_cpa_no' => '0123456', 'auditor.accreditation_valid_until' => '2028-12-31', 'auditor.audit_date' => '2027-01-08'] as $key => $value) {
            CdaReportValue::query()->create(['report_id' => $report->id, 'field_key' => $key, 'value' => $value]);
        }

        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'set_stage', 'stage' => 'audited'])
            ->assertOk()->assertJsonPath('data.stage', 'audited');
    }

    public function test_audited_needs_each_of_the_five_documents(): void
    {
        $values = self::OLD_FOUR + ['auditor.auditor_firm' => 'F', 'auditor.auditor_cpa_no' => '1', 'auditor.accreditation_valid_until' => '2028-12-31', 'auditor.audit_date' => '2027-01-08'];
        $report = $this->fsReady($values);
        CdaDocument::query()->where('report_id', $report->id)->where('category', 'fs_notes')->update(['status' => 'withdrawn']);

        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'set_stage', 'stage' => 'audited'])
            ->assertUnprocessable()->assertJsonPath('errors.stage.0', 'Audited needs Notes to the financial statements uploaded first.');
    }

    public function test_a_template_without_auditor_fields_keeps_the_four_fixed_ones(): void
    {
        $this->activeTemplate('financial_statements', [
            'schema_version' => 1, 'meta' => ['verified' => true], 'parts' => ['annual'],
            'sections' => [['key' => 'auditor', 'title' => 'Auditor', 'kind' => 'fields', 'fields' => [
                ['key' => 'auditor_name', 'label' => 'Auditor', 'type' => 'text'],
                ['key' => 'auditor_accreditation_no', 'label' => 'Accreditation', 'type' => 'text'],
                ['key' => 'audit_opinion', 'label' => 'Opinion', 'type' => 'text'],
                ['key' => 'audit_report_date', 'label' => 'Report date', 'type' => 'date'],
            ]]],
            'rules' => [],
        ]);
        $report = $this->report($this->openYear(), 'financial_statements');
        CdaReportValue::query()->create(['report_id' => $report->id, 'field_key' => 'auditor.auditor_name', 'value' => 'Reyes & Co.']);
        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'set_stage', 'stage' => 'for_external_audit'])->assertOk();

        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'set_stage', 'stage' => 'audited'])
            ->assertUnprocessable()->assertJsonPath('errors.stage.0', 'Audited needs the auditor\'s name, accreditation number, opinion and report date first.');
    }

    public function test_the_validator_checks_the_auditor_fields_list(): void
    {
        $validator = app(TemplateDefinitionValidator::class);
        $period = $this->openYear();
        $fs = $this->report($period, 'financial_statements')->load('template')->template->definition;

        $bad = $fs;
        $bad['meta']['auditor_fields'] = ['auditor.nobody'];
        $this->assertStringContainsString('auditor.nobody', collect($validator->validate($bad, 'financial_statements'))->pluck('message')->implode(' '));

        $bad['meta']['auditor_fields'] = 'auditor.auditor_name';
        $this->assertContains('meta.auditor_fields', collect($validator->validate($bad, 'financial_statements'))->pluck('path')->all());

        $bad['meta']['auditor_fields'] = ['balance_sheet'];
        $this->assertNotSame([], $validator->validate($bad, 'financial_statements'), 'A table is not an auditor field.');

        foreach (ReportTypes::ALL as $type) {
            $this->assertSame([], $validator->validate($this->report($period, $type, $type === 'mediation' ? 'h1' : 'annual')->load('template')->template->definition, $type), $type);
        }
    }
}
