<?php

namespace Tests\Feature\Phase6\Amla\Cases;

use App\Models\AmlaCtrCandidate;
use App\Models\AmlaInboxItem;
use App\Models\AmlaLogEntry;
use App\Models\AmlaReport;
use App\Models\GCashNonMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * CTR and STR records (task §13–15; AMLA-B6): prepared from a CTR candidate
 * or a case's STR decision, approved by someone other than the preparer
 * (super_admin included), and recorded as submitted by hand with a
 * reference, a date and evidence; the GoTRACS file isn't built, so an export
 * is an internal copy and never a submission. Deadlines run on B1's
 * working-day calendar and are provisional in a year with no confirmed
 * calendar. Every view and export is logged.
 */
class AmlaFilingTest extends TestCase
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

    private function evidence(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('acknowledgement.pdf', "%PDF-1.4\n%%EOF\n");
    }

    public function test_a_ctr_is_due_five_working_days_after_the_transaction_on_the_calendar_and_provisional_until_confirmed(): void
    {
        $candidate = $this->ctrCandidate(occurredOn: '2026-11-20');
        $preparer = $this->amlaUser(['amla:ctr']);

        $report = $this->actingAs($preparer)->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertCreated()->json('data');
        $this->assertSame(['ctr', 'pending_approval', '2026-11-27', true], [$report['type'], $report['status'], $report['due_on'], $report['due_provisional']]);
        $this->assertSame('internal_v1', $report['format']['code']);
        $this->assertFalse($report['format']['file_available']);
        $this->assertSame('report_prepared', $candidate->fresh()->status);
        $this->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertConflict();

        $this->holiday('2026-11-25');
        $this->confirmYear(2026);
        $second = $this->ctrCandidate(occurredOn: '2026-11-20');
        $again = $this->actingAs($preparer)->postJson("/api/amla/ctr-candidates/{$second->id}/report")->assertCreated()->json('data');
        $this->assertSame(['2026-11-30', false], [$again['due_on'], $again['due_provisional']]);
    }

    public function test_missing_ctr_fields_read_data_not_available_for_a_walk_in(): void
    {
        $walkIn = GCashNonMember::factory()->create(['id_type' => null, 'id_number' => null]);
        $event = $this->event($this->member(), '700000.00', ['borrower_id' => null, 'gcash_non_member_id' => $walkIn->id, 'source' => 'gcash', 'kind' => 'gcash_cash_in']);
        $this->detect();
        $candidate = AmlaCtrCandidate::query()->where('event_id', $event->id)->sole();

        $fields = $this->actingAs($this->headOffice(['amla:ctr']))->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertCreated()->json('data.fields');
        $byLabel = collect($fields)->pluck('value', 'label');
        $this->assertSame('Data not available', $byLabel['Date of birth']);
        $this->assertSame('Data not available', $byLabel['Address']);
        $this->assertSame('Data not available', $byLabel['ID type and number']);
        $this->assertSame('₱700,000.00', $byLabel['Amount']);
    }

    public function test_the_preparer_never_approves_not_even_a_super_admin_and_submission_needs_a_reference_a_date_and_evidence(): void
    {
        $candidate = $this->ctrCandidate();
        $id = $this->actingAs($this->admin)->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertCreated()->json('data.id');

        $this->postJson("/api/amla/reports/{$id}/approve")->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:ctr']))->postJson("/api/amla/reports/{$id}/approve")->assertForbidden();

        $submitter = $this->amlaUser(['amla:submit']);
        $this->actingAs($submitter)->post("/api/amla/reports/{$id}/submissions", ['status' => 'submitted', 'reference' => 'GT-1', 'date' => '2026-11-20', 'evidence' => $this->evidence()], ['Accept' => 'application/json'])
            ->assertConflict();

        $approver = $this->amlaUser(['amla:approve']);
        $this->actingAs($approver)->postJson("/api/amla/reports/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by.id', $approver->id);

        $this->actingAs($submitter)->post("/api/amla/reports/{$id}/submissions", ['status' => 'submitted', 'date' => '2026-11-20'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reference', 'evidence']);
        $this->post("/api/amla/reports/{$id}/submissions", ['status' => 'accepted', 'reference' => 'GT-1', 'date' => '2026-11-20', 'evidence' => $this->evidence()], ['Accept' => 'application/json'])
            ->assertConflict();
        $this->post("/api/amla/reports/{$id}/submissions", ['status' => 'submitted', 'reference' => 'GT-2026-0001', 'date' => '2026-11-20', 'evidence' => $this->evidence()], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->post("/api/amla/reports/{$id}/submissions", ['status' => 'accepted', 'reference' => 'ACK-77', 'date' => '2026-11-20', 'evidence' => $this->evidence()], ['Accept' => 'application/json'])
            ->assertCreated();

        $report = $this->getJson("/api/amla/reports/{$id}")->assertOk()->json('data');
        $this->assertSame('accepted', $report['status']);
        $this->assertSame(['submitted', 'accepted'], array_column($report['submissions'], 'status'));
        $this->assertSame('GT-2026-0001', $report['submission_reference']);
    }

    public function test_a_returned_report_goes_back_to_its_preparer_and_a_candidate_can_be_set_aside_with_a_reason(): void
    {
        $candidate = $this->ctrCandidate();
        $preparer = $this->amlaUser(['amla:ctr']);
        $id = $this->actingAs($preparer)->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->json('data.id');

        $this->actingAs($this->amlaUser(['amla:approve']))->postJson("/api/amla/reports/{$id}/return", ['reason' => 'Check the branch.'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->assertSame(1, AmlaInboxItem::query()->where('user_id', $preparer->id)->where('kind', 'report_returned')->count());

        $other = $this->ctrCandidate();
        $this->actingAs($preparer)->postJson("/api/amla/ctr-candidates/{$other->id}/not-reportable")->assertUnprocessable();
        $this->postJson("/api/amla/ctr-candidates/{$other->id}/not-reportable", ['reason' => 'Exempt institution transfer.'])->assertOk()->assertJsonPath('data.status', 'not_reportable');
        $this->postJson("/api/amla/ctr-candidates/{$other->id}/report")->assertConflict();
    }

    public function test_an_str_needs_the_cases_str_decision_and_is_due_the_next_working_day_and_provisional_across_an_unconfirmed_year(): void
    {
        $this->confirmYear(2026);
        $officer = $this->headOffice(['amla:cases', 'amla:str']);
        $case = $this->openCase($officer);

        $this->actingAs($officer)->postJson("/api/amla/cases/{$case->id}/str", ['narrative' => 'x'])->assertConflict();
        $this->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'str', 'reason' => 'No business reason.', 'determination_date' => '2026-11-20'])->assertOk();
        $this->actingAs($this->amlaUser(['amla:str']))->postJson("/api/amla/cases/{$case->id}/str", ['narrative' => 'x'])->assertForbidden();

        $report = $this->actingAs($officer)->postJson("/api/amla/cases/{$case->id}/str", ['narrative' => 'Daily cash deposits just below the CTR line for a week.'])->assertCreated()->json('data');
        $this->assertSame(['str', '2026-11-23', false], [$report['type'], $report['due_on'], $report['due_provisional']]);
        $this->assertNotSame('Daily cash deposits just below the CTR line for a week.', AmlaReport::query()->toBase()->where('id', $report['id'])->value('narrative'));

        Carbon::setTestNow(Carbon::parse('2026-12-31 10:00:00', 'Asia/Manila'));
        $late = $this->openCase($officer);
        $this->actingAs($officer)->postJson("/api/amla/cases/{$late->id}/decision", ['decision' => 'str', 'reason' => 'Year end.', 'determination_date' => '2026-12-31'])->assertOk();
        $yearEnd = $this->postJson("/api/amla/cases/{$late->id}/str", ['narrative' => 'Year end.'])->assertCreated()->json('data');
        $this->assertSame(['2027-01-01', true], [$yearEnd['due_on'], $yearEnd['due_provisional']]);

        $this->actingAs($this->amlaUser(['amla:approve']))->getJson("/api/amla/reports/{$report['id']}")->assertNotFound();
    }

    public function test_every_report_view_and_export_is_logged_and_an_export_is_never_a_submission(): void
    {
        $candidate = $this->ctrCandidate();
        $id = $this->actingAs($this->amlaUser(['amla:ctr']))->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->json('data.id');
        $this->actingAs($this->amlaUser(['amla:approve']))->postJson("/api/amla/reports/{$id}/approve")->assertOk();

        $this->actingAs($this->amlaUser(['amla:ctr']))->get("/api/amla/reports/{$id}/export", ['Accept' => 'application/json'])->assertForbidden();
        $exporter = $this->amlaUser(['amla:export']);
        $response = $this->actingAs($exporter)->get("/api/amla/reports/{$id}/export")->assertOk();
        $this->assertStringContainsString('Internal review copy', $response->streamedContent());

        $this->assertSame('approved', AmlaReport::query()->findOrFail($id)->status);
        $this->getJson("/api/amla/reports/{$id}")->assertOk();
        $this->getJson('/api/amla/reports')->assertOk();
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'report_exported')->where('subject_id', $id)->count());
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'report_viewed')->where('subject_id', $id)->count());
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'reports_viewed')->where('user_id', $exporter->id)->count());
    }

    public function test_the_deadline_run_tells_compliance_staff_once_of_upcoming_due_and_overdue_filings(): void
    {
        $this->confirmYear(2026);
        $ctrOfficer = $this->amlaUser(['amla:ctr']);
        $upcoming = $this->ctrCandidate(occurredOn: '2026-11-17');
        $today = $this->ctrCandidate(occurredOn: '2026-11-13');
        $overdue = $this->ctrCandidate(occurredOn: '2026-11-10');
        foreach ([$upcoming, $today, $overdue] as $candidate) {
            $this->actingAs($ctrOfficer)->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertCreated();
        }
        $notTold = $this->amlaUser(['amla:cdd']);

        $this->artisan('amla:deadlines')->assertSuccessful();
        $this->artisan('amla:deadlines')->assertSuccessful();

        $kinds = AmlaInboxItem::query()->where('user_id', $ctrOfficer->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['filing_due_today', 'filing_overdue', 'filing_upcoming'], array_values(array_filter($kinds, fn ($k) => str_starts_with($k, 'filing_'))));
        $messages = AmlaInboxItem::query()->where('kind', 'filing_overdue')->pluck('message')->all();
        $this->assertStringContainsString('is overdue', $messages[0]);
        $this->assertSame(0, AmlaInboxItem::query()->where('user_id', $notTold->id)->count());

        $this->actingAs($ctrOfficer)->getJson('/api/amla/filing-deadlines')->assertOk()->assertJsonCount(3, 'data');
        $inbox = $this->getJson('/api/amla/inbox')->assertOk()->json('data');
        $this->postJson("/api/amla/inbox/{$inbox[0]['id']}/read")->assertOk()->assertJsonPath('data.read', true);
        $this->actingAs($this->amlaUser(['amla:ctr']))->postJson("/api/amla/inbox/{$inbox[0]['id']}/read")->assertNotFound();
    }
}
