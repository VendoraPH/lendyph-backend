<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaSubmission;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\CanonicalJson;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The report workflow (design §5): draft → for review → approved → finalized
 * (snapshot and checksum) → submitted → accepted or for correction, return
 * and reopen with a reason. Each action needs its own permission, the
 * approver can't be the submitter, a stale status answers 409, Critical
 * issues block, warnings need confirming, a figure that moved since approval
 * stops the finalize, the snapshot never changes afterwards, and a reopen
 * keeps it as v1.
 */
class CdaWorkflowTest extends TestCase
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
        $this->member(['gender' => 'female']);
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

    /** The test template, filled in, submitted by the preparer and approved by the approver. */
    private function approvedReport(array $overrides = []): CdaReport
    {
        $report = $this->caprWithTestTemplate($overrides);
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [
            ['field_key' => 'employment.employees_regular', 'value' => 5],
        ]])->assertOk();
        $this->act($report, 'submit_for_review')->assertOk()->assertJsonPath('data.status', 'for_review');
        $this->act($report, 'approve', [], $this->approver)->assertOk()->assertJsonPath('data.status', 'approved');

        return $report->refresh();
    }

    public function test_the_whole_path_with_its_audit_rows_and_history(): void
    {
        $report = $this->approvedReport();

        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk()
            ->assertJsonPath('data.status', 'finalized')
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.snapshot.version', 1);
        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14', 'cda_reference_no' => 'CAIS-2027-001']])->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->act($report, 'mark_accepted')->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->act($report, 'mark_for_correction', ['reason' => 'CDA asked for the employee count by sex'])->assertOk()->assertJsonPath('data.status', 'for_correction');

        $rows = AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $report->id)->where('action', 'cda_workflow')->orderBy('id')->get();
        $this->assertSame(
            ['draft→for_review', 'for_review→approved', 'approved→finalized', 'finalized→submitted', 'submitted→accepted', 'accepted→for_correction'],
            $rows->map(fn ($row) => $row->old_values['status'].'→'.$row->new_values['status'])->all(),
        );
        $this->assertSame('CDA asked for the employee count by sex', $rows->last()->new_values['reason']);
        $this->assertSame(CdaReportSnapshot::query()->sole()->checksum, $rows[2]->new_values['checksum']);

        $history = $this->getJson("/api/cda/reports/{$report->id}")->json('data.history');
        $this->assertSame(['save', 'refresh'], collect($history)->pluck('action')->slice(-2)->values()->all(), 'Newest first: the first refresh is the oldest entry.');
        $this->assertCount(8, $history);
        $this->assertSame('mark_for_correction', $history[0]['action']);
        $this->assertSame('for_correction', $history[0]['to_status']);
        $this->assertSame('accepted', $history[0]['from_status']);
        $this->assertSame('CDA asked for the employee count by sex', $history[0]['reason']);
        $this->assertSame($this->preparer->id, $history[0]['user']['id']);
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $report = $this->caprWithTestTemplate();
        $viewer = $this->userWith(['branches:view_all', CdaAccess::VIEW]);
        $noViewAll = $this->userWith([CdaAccess::VIEW, CdaAccess::PREPARE]);

        $this->act($report, 'submit_for_review', [], $viewer)->assertForbidden();
        $this->act($report, 'refresh', [], $noViewAll)->assertForbidden();

        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 5]]])->assertOk();
        $this->act($report, 'submit_for_review')->assertOk();
        $this->act($report, 'approve', [], $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::REVIEW]))->assertForbidden();
        $this->act($report, 'return', ['reason' => 'Check the payroll'], $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE]))->assertForbidden();
        $this->act($report, 'return', ['reason' => 'Check the payroll'], $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::REVIEW]))->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.sub_status', 'returned')
            ->assertJsonPath('data.display_status', 'returned');
    }

    public function test_an_unclear_warnings_answer_is_refused_in_words(): void
    {
        $report = $this->approvedReport();

        $this->act($report, 'finalize', ['confirm_warnings' => 'perhaps'])->assertStatus(422)
            ->assertJsonPath('errors.confirm_warnings.0', 'Say whether to go ahead despite the warnings.');
        $this->assertSame('approved', $report->refresh()->status);
    }

    public function test_the_approver_cant_be_the_submitter(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 5]]]);
        $this->act($report, 'submit_for_review')->assertOk();

        $this->act($report, 'approve')->assertForbidden()
            ->assertJsonPath('message', 'You submitted this report for review, so someone else has to approve it.');
        $this->assertSame('for_review', $report->refresh()->status);
    }

    public function test_reasons_are_required_and_stale_actions_answer_409(): void
    {
        $report = $this->approvedReport();

        $this->act($report, 'return', [], $this->approver)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->act($report, 'approve', [], $this->approver)->assertStatus(409)
            ->assertJsonPath('message', 'This report is approved, so it can\'t be approved now. Reload it to see its current status.');
        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertStatus(409);
        $this->act($report, 'teleport')->assertStatus(422)->assertJsonValidationErrors(['action']);
    }

    public function test_critical_issues_block_and_warnings_need_confirming(): void
    {
        Borrower::query()->first()->update(['gender' => null]);
        $report = $this->caprWithTestTemplate();

        $this->act($report, 'submit_for_review')->assertStatus(422)
            ->assertJsonPath('message', 'This report has 1 critical issue to fix first.');
        $this->assertSame('draft', $report->refresh()->status);

        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 5]]])->assertOk();
        $this->act($report, 'submit_for_review')->assertOk();
        $this->act($report, 'approve', [], $this->approver)->assertOk();

        $this->act($report, 'finalize')->assertStatus(422)
            ->assertJsonPath('message', 'This report has 1 warning. Confirm the warnings to finalize it.');
        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'cda_workflow')->latest('id')->first()->new_values['warnings_confirmed']);
    }

    public function test_a_figure_that_changed_since_approval_stops_the_finalize(): void
    {
        $report = $this->approvedReport();
        $this->member(['gender' => 'male']);

        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertStatus(409)
            ->assertJsonPath('message', 'Figures changed since this report was approved (Total members, Male). Return it to draft, refresh and approve it again.');
        $this->assertSame('approved', $report->refresh()->status);
        $this->assertSame(0, CdaReportSnapshot::query()->count());
    }

    public function test_the_checksum_is_reproducible_and_later_changes_leave_the_snapshot_alone(): void
    {
        $report = $this->approvedReport();
        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk();
        $snapshot = CdaReportSnapshot::query()->sole();

        $this->assertSame(hash('sha256', $snapshot->payload), $snapshot->checksum);
        $this->assertSame($snapshot->payload, CanonicalJson::encode(json_decode($snapshot->payload, true)));
        $payload = json_decode($snapshot->payload, true);
        $this->assertSame('Binhi Multi-Purpose Cooperative', $payload['values']['general.cooperative_name']['value']);
        $this->assertSame(2, $payload['values']['membership.members_total']['value']);

        $this->brandAs('Renamed Cooperative');
        $this->member(['membership_date' => '2027-01-10']);
        Borrower::query()->first()->update(['first_name' => 'Changed', 'gender' => 'female']);

        $data = $this->actingAs($this->preparer)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data');
        $this->assertSame('Binhi Multi-Purpose Cooperative', collect($data['sections'][0]['fields'])->firstWhere('key', 'cooperative_name')['value']);
        $this->assertSame($snapshot->checksum, $data['snapshot']['checksum']);
        $this->assertSame($snapshot->payload, CdaReportSnapshot::query()->sole()->payload);

        $this->postJson("/api/cda/reports/{$report->id}/snapshots/1/verify")->assertOk()
            ->assertJsonPath('data.checksum_matches', true)
            ->assertJsonPath('data.checksum', $snapshot->checksum);
        $this->getJson("/api/cda/reports/{$report->id}/snapshots")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(2, $this->getJson("/api/cda/reports/{$report->id}/snapshots/1")->assertOk()->json('data.payload.values')['membership.members_total']['value']);
    }

    public function test_reopen_gives_version_two_and_keeps_version_one(): void
    {
        $report = $this->approvedReport();
        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk();
        $v1 = CdaReportSnapshot::query()->sole();

        $this->act($report, 'reopen')->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->act($report, 'reopen', ['reason' => 'The employee count was wrong'])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.snapshot', null);

        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 6]]])->assertOk();
        $this->act($report, 'submit_for_review')->assertOk();
        $this->act($report, 'approve', [], $this->approver)->assertOk();
        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk()->assertJsonPath('data.snapshot.version', 2);

        $v1->refresh();
        $this->assertNotNull($v1->superseded_at);
        $this->assertSame('The employee count was wrong', $v1->reopen_reason);
        $this->assertSame($this->preparer->id, $v1->reopened_by);
        $this->assertSame(5, json_decode($v1->payload, true)['values']['employment.employees_regular']['value']);
        $this->assertSame([1, 2], CdaReportSnapshot::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame(CdaReportSnapshot::query()->where('version', 2)->value('id'), $report->refresh()->current_snapshot_id);
    }

    public function test_mark_submitted_creates_one_submission_row_linked_to_the_snapshot(): void
    {
        $report = $this->approvedReport();
        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk();

        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'fax', 'submitted_on' => '2027-01-14']])->assertStatus(422)->assertJsonValidationErrors(['submission.method']);
        $this->act($report, 'mark_submitted', ['submission' => [
            'method' => 'ecoophub', 'submitted_on' => '2027-01-14', 'submitted_by_name' => 'Maria Santos',
            'cda_reference_no' => 'ECH-1', 'acknowledgment_no' => 'ACK-9', 'remarks' => 'Filed online',
        ]])->assertOk();
        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-13']])->assertStatus(409);

        $submission = CdaSubmission::query()->sole();
        $this->assertSame($report->refresh()->current_snapshot_id, $submission->snapshot_id);
        $this->assertSame('submitted', $submission->status);
        $this->assertSame('ECH-1', $submission->cda_reference_no);
        $this->assertSame($this->preparer->id, $submission->recorded_by);

        $this->getJson('/api/cda/periods/2026/submissions')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.report_type', 'capr')
            ->assertJsonPath('data.0.snapshot.version', 1)
            ->assertJsonPath('data.0.method', 'ecoophub');

        $this->putJson("/api/cda/submissions/{$submission->id}", ['acknowledgment_no' => 'ACK-10', 'remarks' => 'Acknowledged by email'])->assertOk()
            ->assertJsonPath('data.acknowledgment_no', 'ACK-10');
        $this->putJson("/api/cda/submissions/{$submission->id}", ['method' => 'cais'])->assertStatus(422)->assertJsonValidationErrors(['method']);

        $this->act($report, 'mark_accepted')->assertOk();
        $this->assertSame('accepted', $submission->refresh()->status);
    }

    public function test_a_document_needed_at_submission_blocks_only_the_submission(): void
    {
        $definition = $this->testDefinition();
        $definition['rules'][] = ['id' => 'signed_copy', 'type' => 'document', 'document_category' => 'capr', 'stage' => 'submit', 'final' => true, 'severity' => 'critical', 'category' => 'compliance_document', 'component' => 'documents'];
        $report = $this->approvedReport(['rules' => $definition['rules']]);

        $this->act($report, 'finalize', ['confirm_warnings' => true])->assertOk();
        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertStatus(422)
            ->assertJsonPath('message', 'Upload the Cooperative Annual Progress Report (CAPR) (final copy) before recording the filing.');

        $document = CdaDocument::factory()->create(['period_id' => $report->period_id, 'category' => 'capr', 'report_id' => $report->id]);
        CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id, 'final_key' => (string) $document->id]);

        $this->act($report, 'mark_submitted', ['submission' => ['method' => 'cais', 'submitted_on' => '2027-01-14']])->assertOk();
    }

    public function test_set_stage_moves_the_financial_statements_forward(): void
    {
        $period = $this->openYear();
        $fs = $this->report($period, 'financial_statements');
        $auditor = $this->cdaStaff([CdaAccess::VIEW_AUDIT]);

        $this->act($fs, 'set_stage', ['stage' => 'for_external_audit'], $this->preparer)->assertForbidden();
        $this->act($fs, 'set_stage', ['stage' => 'signed'], $auditor)->assertStatus(422)->assertJsonValidationErrors(['stage']);
        $this->act($fs, 'set_stage', ['stage' => 'for_external_audit'], $auditor)->assertOk()->assertJsonPath('data.stage', 'for_external_audit');
        $this->act($fs, 'set_stage', ['stage' => 'audited'], $auditor)->assertStatus(422)
            ->assertJsonPath('message', 'Audited needs the auditor\'s name, accreditation number, opinion and report date first.');
    }
}
