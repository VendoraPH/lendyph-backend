<?php

namespace Tests\Feature\Phase6\Amla\Cases;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaCase;
use App\Models\AmlaCaseEvidence;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaInboxItem;
use App\Models\AmlaLogEntry;
use App\Models\AmlaReport;
use App\Models\AmlaRiskAssessment;
use App\Models\Borrower;
use App\Services\BorrowerPurgeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * PR #347 security review: the four-eyes rules (a High risk judged on a fresh
 * score, approver never the preparer at the model, a CTR set aside only by a
 * second person, a case's no-STR decision never its opener's or assignee's),
 * reminders for every filing still to make, case ids for head office only,
 * downloads under the stored file's extension, and purges refused for any
 * member AMLA holds records on.
 */
class AmlaSeparationOfDutiesTest extends TestCase
{
    use CaseTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_downgrade_counts_from_high_when_the_fresh_score_is_high_and_is_rechecked_on_approval(): void
    {
        $member = $this->member();
        $maker = $this->amlaUser(['amla:cdd']);
        $checker = $this->amlaUser(['amla:cdd']);
        $this->actingAs($maker)->putJson("/api/amla/cdd/members/{$member->id}", ['occupation' => 'Trader'])->assertOk();
        $this->assertSame('high', AmlaRiskAssessment::query()->latest('id')->firstOrFail()->computed_level, 'this scenario needs a computed High');

        $first = $this->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments", ['override_level' => 'medium', 'override_reason' => 'Mostly salary.'])->assertStatus(202)->json('data.id');
        $this->actingAs($checker)->postJson("/api/amla/risk-downgrades/{$first}/approve")->assertOk();
        $this->assertSame('medium', AmlaRiskAssessment::query()->latest('id')->firstOrFail()->level);

        // Saved Medium, computed High: still a downgrade from High.
        $second = $this->actingAs($maker)->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments", ['override_level' => 'low', 'override_reason' => 'Salary only.'])
            ->assertStatus(202)->json('data');
        $this->assertSame('high', $second['from_level']);

        // The risk moved after it was asked for: approving it now is refused.
        $this->actingAs($maker)->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments")->assertCreated();
        $this->actingAs($checker)->postJson("/api/amla/risk-downgrades/{$second['id']}/approve")->assertConflict();
    }

    public function test_the_model_refuses_an_approval_by_the_preparer(): void
    {
        $candidate = $this->ctrCandidate();
        $preparer = $this->amlaUser(['amla:ctr']);
        $id = $this->actingAs($preparer)->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertCreated()->json('data.id');
        $report = AmlaReport::query()->findOrFail($id);

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $report->forceFill(['status' => AmlaReport::APPROVED, 'approved_by' => $preparer->id, 'approved_at' => now()])->save();
    }

    public function test_setting_a_ctr_candidate_aside_waits_for_a_second_approver_and_a_preparer_never_returns_their_own(): void
    {
        $candidate = $this->ctrCandidate();
        $rejected = $this->ctrCandidate();
        $own = $this->ctrCandidate();
        $requester = $this->amlaUser(['amla:ctr', 'amla:approve']);
        $approver = $this->amlaUser(['amla:approve']);
        $outside = $this->outsideAmlaCounts();

        $this->actingAs($requester)->postJson("/api/amla/ctr-candidates/{$candidate->id}/not-reportable", ['reason' => 'Exempt institution transfer.'])
            ->assertStatus(202)->assertJsonPath('data.status', 'set_aside_pending');
        $this->postJson("/api/amla/ctr-candidates/{$candidate->id}/not-reportable/approve")->assertForbidden();
        $this->actingAs($approver)->postJson("/api/amla/ctr-candidates/{$candidate->id}/not-reportable/approve")->assertOk()->assertJsonPath('data.status', 'not_reportable');
        $this->actingAs($requester)->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertConflict();

        $this->postJson("/api/amla/ctr-candidates/{$rejected->id}/not-reportable", ['reason' => 'Exempt.'])->assertStatus(202);
        $this->actingAs($approver)->postJson("/api/amla/ctr-candidates/{$rejected->id}/not-reportable/reject", ['reason' => 'It is reportable.'])->assertOk()->assertJsonPath('data.status', 'detected');

        $reportId = $this->actingAs($requester)->postJson("/api/amla/ctr-candidates/{$own->id}/report")->assertCreated()->json('data.id');
        $this->postJson("/api/amla/reports/{$reportId}/return", ['reason' => 'Mine.'])->assertForbidden();
        $this->assertSame(AmlaCtrCandidate::REPORT_PREPARED, $own->fresh()->status);
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_deciding_a_case_needs_approval_and_a_no_str_decision_is_never_the_openers_or_assignees(): void
    {
        $opener = $this->headOffice(['amla:cases', 'amla:approve']);
        $assignee = $this->headOffice(['amla:cases', 'amla:approve']);
        $reviewer = $this->headOffice(['amla:cases', 'amla:approve']);
        $case = $this->openCase($opener);
        $this->actingAs($opener)->postJson("/api/amla/cases/{$case->id}/assign", ['assignee_id' => $assignee->id])->assertOk();

        $this->actingAs($this->headOffice(['amla:cases']))->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'no_str', 'reason' => 'Fine.'])->assertForbidden();
        $this->actingAs($opener)->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'no_str', 'reason' => 'Fine.'])->assertForbidden();
        $this->actingAs($assignee)->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'no_str', 'reason' => 'Fine.'])->assertForbidden();
        $this->actingAs($reviewer)->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'no_str', 'reason' => 'Salary documented.'])->assertOk();

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $other = $this->openCase($opener);
        $other->forceFill(['status' => AmlaCase::CLOSED_NO_REPORT, 'decision' => 'no_str', 'decision_reason' => 'x', 'decided_by' => $opener->id, 'decided_at' => now(), 'closed_at' => now()])->save();
    }

    public function test_reminders_cover_candidates_with_no_ctr_and_cases_with_no_str_dated_by_the_settings_then_in_force(): void
    {
        $this->confirmYear(2026);
        $ctrOfficer = $this->amlaUser(['amla:ctr']);
        $strOfficer = $this->headOffice(['amla:str']);
        $candidate = $this->ctrCandidate(occurredOn: '2026-11-13');
        $caseOfficer = $this->headOffice(['amla:cases', 'amla:approve']);
        $case = $this->openCase($caseOfficer);
        $this->actingAs($this->headOffice(['amla:cases', 'amla:approve']))->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'str', 'reason' => 'Established.', 'determination_date' => '2026-11-18'])->assertOk();

        $id = $this->actingAs($this->settingsHolder())->postJson('/api/amla/change-requests', ['type' => 'settings', 'reason' => 'Longer STR window.', 'values' => ['str_deadline_working_days' => 3]])->assertCreated()->json('data.id');
        $this->actingAs($this->settingsHolder())->postJson("/api/amla/change-requests/{$id}/approve")->assertOk();
        $outside = $this->outsideAmlaCounts();

        $this->artisan('amla:deadlines')->assertSuccessful();

        $this->assertSame(['filing_due_today'], AmlaInboxItem::query()->where('user_id', $ctrOfficer->id)->where('subject_type', 'ctr_candidate')->pluck('kind')->all());
        $this->assertSame(['filing_overdue'], AmlaInboxItem::query()->where('user_id', $strOfficer->id)->where('subject_type', 'case')->pluck('kind')->all());
        $this->assertSame(0, AmlaInboxItem::query()->where('user_id', $ctrOfficer->id)->where('subject_type', 'case')->count());
        $this->assertNothingWrittenOutsideAmla($outside);

        $rows = collect($this->actingAs($strOfficer)->getJson('/api/amla/filing-deadlines')->assertOk()->json('data'))->keyBy('kind');
        $this->assertSame('2026-11-19', $rows['case']['due_on']);
        $this->assertNotNull($candidate->id);
    }

    public function test_an_alerts_case_shows_only_to_head_office_case_holders(): void
    {
        $alert = $this->structuringAlert();
        $this->actingAs($this->headOffice(['amla:alerts', 'amla:cases']))->postJson("/api/amla/alerts/{$alert->id}/escalate", ['title' => 'Structuring'])->assertCreated();

        $this->actingAs($this->amlaUser(['amla:alerts']));
        $this->assertNull($this->getJson("/api/amla/alerts/{$alert->id}")->assertOk()->json('data.case'));
        $this->assertSame([null], array_column($this->getJson("/api/amla/alerts/{$alert->id}/history")->assertOk()->json('data'), 'case_id'));

        $this->actingAs($this->headOffice(['amla:alerts', 'amla:cases']));
        $this->assertNotNull($this->getJson("/api/amla/alerts/{$alert->id}")->json('data.case.reference'));
    }

    public function test_downloads_use_the_stored_extension_and_filing_evidence_has_its_own_logged_download(): void
    {
        $officer = $this->headOffice(['amla:cases']);
        $case = $this->openCase($officer);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $id = $this->actingAs($officer)->post("/api/amla/cases/{$case->id}/evidence", ['file' => UploadedFile::fake()->createWithContent('receipt.jpeg', $png)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $stored = pathinfo(AmlaCaseEvidence::query()->findOrFail($id)->file_path, PATHINFO_EXTENSION);
        $disposition = (string) $this->get("/api/amla/cases/{$case->id}/evidence/{$id}")->assertOk()->headers->get('Content-Disposition');
        $this->assertStringContainsString("receipt.{$stored}", $disposition);
        $this->assertNotSame('jpeg', $stored, 'the stored extension comes from the bytes, not the name');

        $candidate = $this->ctrCandidate();
        $reportId = $this->actingAs($this->amlaUser(['amla:ctr']))->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->json('data.id');
        $this->actingAs($this->amlaUser(['amla:approve']))->postJson("/api/amla/reports/{$reportId}/approve")->assertOk();
        $submitter = $this->amlaUser(['amla:submit']);
        $this->actingAs($submitter)->post("/api/amla/reports/{$reportId}/submissions", ['status' => 'submitted', 'reference' => 'GT-1', 'date' => '2026-11-20', 'evidence' => UploadedFile::fake()->createWithContent('ack.pdf', "%PDF-1.4\n%%EOF\n")], ['Accept' => 'application/json'])->assertCreated();
        $submissionId = $this->getJson("/api/amla/reports/{$reportId}")->json('data.submissions.0.id');

        $this->get("/api/amla/reports/{$reportId}/submissions/{$submissionId}/evidence")->assertOk()->assertDownload();
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'report_evidence_downloaded')->where('subject_id', $reportId)->count());
        $this->actingAs($this->amlaUser(['amla:cdd']))->get("/api/amla/reports/{$reportId}/submissions/{$submissionId}/evidence", ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_member_amla_holds_any_record_on_is_never_purged(): void
    {
        $member = $this->member();
        $this->actingAs($this->headOffice(['amla:cases']))->postJson('/api/amla/cases', ['title' => 'Review', 'borrower_id' => $member->id])->assertCreated();

        try {
            app(BorrowerPurgeService::class)->purge($member->fresh());
            $this->fail('The member was purged.');
        } catch (ValidationException $refused) {
            $this->assertSame([BorrowerPurgeService::RECORDS_KEPT], $refused->errors()['borrower']);
        }

        $this->assertTrue(Borrower::query()->whereKey($member->id)->exists());
    }
}
