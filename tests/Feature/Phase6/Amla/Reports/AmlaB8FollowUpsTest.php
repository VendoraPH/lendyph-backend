<?php

namespace Tests\Feature\Phase6\Amla\Reports;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaExport;
use App\Models\AmlaReport;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA-B8 follow-ups (B7 re-review, checks S1/S2, AMLA-F4's flags): the
 * management summary counts and shows only what the viewer may see, the
 * export sweep looks only at recently expired exports, a case's reports carry
 * their status key, filing deadlines their report type, and a CTR candidate
 * its CTR and whether the caller asked to set it aside.
 */
class AmlaB8FollowUpsTest extends TestCase
{
    use ReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A case whose decision established suspicion on `$day`, with no STR prepared yet. */
    private function strRequiredCase(string $day, ?User $officer = null): AmlaCase
    {
        $officer ??= $this->headOffice(['amla:cases', 'amla:str', 'amla:approve']);
        $case = $this->openCase($officer);
        $this->actingAs($officer)->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'str', 'reason' => 'No business reason.', 'determination_date' => $day])->assertOk();

        return $case->fresh();
    }

    /** @return array<string, string> metric => figure */
    private function summary(User $viewer): array
    {
        return collect($this->actingAs($viewer)->getJson('/api/amla/compliance-reports/management_summary')->assertOk()->json('data.rows'))
            ->pluck('value', 'metric')->all();
    }

    public function test_the_management_summary_counts_only_missed_filings_the_viewer_may_see(): void
    {
        $this->confirmYear(2026);
        $this->ctrCandidate(occurredOn: '2026-11-10');
        $this->strRequiredCase('2026-11-18');

        $this->assertSame('1', $this->summary($this->headOffice(['amla:view', 'amla:ctr']))['Missed deadlines']);
        $this->assertSame('1', $this->summary($this->headOffice(['amla:view', 'amla:str']))['Missed deadlines']);
        $this->assertSame('2', $this->summary($this->headOffice(['amla:view', 'amla:ctr', 'amla:str']))['Missed deadlines']);
        $this->assertArrayNotHasKey('Missed deadlines', $this->summary($this->headOffice(['amla:view'])));
    }

    public function test_the_management_summary_shows_alert_and_watchlist_lines_only_with_their_keys(): void
    {
        $this->pendingMatch($this->member());

        $plain = $this->summary($this->headOffice(['amla:view']));
        $this->assertArrayNotHasKey('Alerts raised in the period', $plain);
        $this->assertArrayNotHasKey('Open alerts', $plain);
        $this->assertArrayNotHasKey('Outstanding watchlist reviews', $plain);
        $this->assertArrayHasKey('Transactions monitored', $plain);

        $alerts = $this->summary($this->headOffice(['amla:view', 'amla:alerts']));
        $this->assertArrayHasKey('Alerts raised in the period', $alerts);
        $this->assertArrayHasKey('Open alerts', $alerts);
        $this->assertArrayNotHasKey('Outstanding watchlist reviews', $alerts);

        $screening = $this->summary($this->headOffice(['amla:view', 'amla:screening']));
        $this->assertSame('1', $screening['Outstanding watchlist reviews']);
        $this->assertArrayNotHasKey('Open alerts', $screening);
    }

    public function test_the_management_summary_shows_kyc_completion_only_with_amla_cdd(): void
    {
        $this->assertArrayNotHasKey('KYC completion', $this->summary($this->headOffice(['amla:view'])));
        $this->assertArrayHasKey('KYC completion', $this->summary($this->headOffice(['amla:view', 'amla:cdd'])));
    }

    public function test_the_export_sweep_marks_each_swept_export_and_catches_up_after_a_three_day_gap(): void
    {
        Storage::fake('private');
        $user = $this->amlaUser(['amla:alerts', 'amla:export']);
        $this->actingAs($user)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated();
        $first = AmlaExport::query()->sole();
        $this->actingAs($user)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated();
        $second = AmlaExport::query()->whereKeyNot($first->id)->sole();

        $this->artisan('amla:prune-exports')->assertSuccessful()->expectsOutput('0 expired AMLA export file(s) deleted.');
        $this->assertNull($first->fresh()->file_deleted_at);

        $this->travel(3)->days();
        $this->artisan('amla:prune-exports')->assertSuccessful()->expectsOutput('2 expired AMLA export file(s) deleted.');
        Storage::disk('private')->assertMissing($first->file_path);
        Storage::disk('private')->assertMissing($second->file_path);
        $this->assertNotNull($first->fresh()->file_deleted_at);
        $this->assertSame($first->sha256, $first->fresh()->sha256);

        Storage::disk('private')->put($first->file_path, 'restored by hand');
        $this->artisan('amla:prune-exports')->assertSuccessful()->expectsOutput('0 expired AMLA export file(s) deleted.');
        Storage::disk('private')->assertExists($first->file_path);
    }

    public function test_an_export_whose_file_fails_to_delete_stays_unmarked_for_the_next_run(): void
    {
        Storage::fake('private');
        $this->actingAs($this->amlaUser(['amla:alerts', 'amla:export']))->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated();
        $export = AmlaExport::query()->sole();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->with($export->file_path)->andReturnTrue();
        $disk->shouldReceive('delete')->with($export->file_path)->andReturnFalse();
        Storage::set('private', $disk);

        $this->travel(36)->minutes();
        $this->artisan('amla:prune-exports')->assertSuccessful()->expectsOutput('0 expired AMLA export file(s) deleted.');
        $this->assertNull($export->fresh()->file_deleted_at);
    }

    public function test_the_sixth_export_in_a_minute_answers_429_in_words(): void
    {
        Storage::fake('private');
        $user = $this->amlaUser(['amla:alerts', 'amla:export']);

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($user)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated();
        }

        $this->actingAs($user)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])
            ->assertTooManyRequests()->assertJsonPath('message', 'Too many requests. Wait a minute and try again.');
        $this->assertSame(5, AmlaExport::query()->count());

        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/api/amla/compliance-reports/transaction_monitoring/exports', ['format' => 'csv'])->assertCreated();
    }

    public function test_a_cases_reports_carry_their_status_key_beside_the_words(): void
    {
        $this->confirmYear(2026);
        $officer = $this->headOffice(['amla:cases', 'amla:str', 'amla:approve']);
        $case = $this->strRequiredCase('2026-11-20', $officer);
        $this->actingAs($officer)->postJson("/api/amla/cases/{$case->id}/str", ['narrative' => 'Daily cash deposits just below the CTR line.'])->assertCreated();

        $report = $this->actingAs($officer)->getJson("/api/amla/cases/{$case->id}")->assertOk()->json('data.reports.0');
        $this->assertSame([AmlaReport::PENDING_APPROVAL, AmlaReport::STATUS_WORDS[AmlaReport::PENDING_APPROVAL]], [$report['status'], $report['status_words']]);
    }

    public function test_filing_deadlines_carry_the_reports_type(): void
    {
        $this->confirmYear(2026);
        $candidate = $this->ctrCandidate(occurredOn: '2026-11-19');
        $case = $this->strRequiredCase('2026-11-20');

        $rows = collect($this->actingAs($this->headOffice(['amla:ctr', 'amla:str']))->getJson('/api/amla/filing-deadlines')->assertOk()->json('data'))->keyBy('reference');
        $this->assertSame(['ctr', AmlaReport::TYPE_WORDS['ctr']], [$rows[$candidate->reference]['type'], $rows[$candidate->reference]['type_words']]);
        $this->assertSame(['str', AmlaReport::TYPE_WORDS['str']], [$rows[$case->reference]['type'], $rows[$case->reference]['type_words']]);
    }

    public function test_a_ctr_candidate_carries_its_ctr_and_whether_the_caller_asked_to_set_it_aside(): void
    {
        $preparer = $this->amlaUser(['amla:ctr']);
        $candidate = $this->ctrCandidate();
        $this->actingAs($preparer)->getJson("/api/amla/ctr-candidates/{$candidate->id}")->assertOk()
            ->assertJsonPath('data.ctr_report_id', null)->assertJsonPath('data.set_aside', null);
        $reportId = $this->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertCreated()->json('data.id');
        $this->getJson("/api/amla/ctr-candidates/{$candidate->id}")->assertJsonPath('data.ctr_report_id', $reportId);

        $other = $this->ctrCandidate();
        $asker = $this->amlaUser(['amla:ctr']);
        $this->actingAs($asker)->postJson("/api/amla/ctr-candidates/{$other->id}/not-reportable", ['reason' => 'A loan release booked as cash by mistake.'])->assertStatus(202);

        $mine = $this->getJson("/api/amla/ctr-candidates/{$other->id}")->assertOk()->json('data.set_aside');
        $this->assertTrue($mine['requested_by_me']);
        $this->assertSame('A loan release booked as cash by mistake.', $mine['reason']);

        $theirs = $this->actingAs($preparer)->getJson("/api/amla/ctr-candidates/{$other->id}")->assertOk();
        $this->assertFalse($theirs->json('data.set_aside.requested_by_me'));
        $this->assertNull($theirs->json('data.set_aside.requested_by'));
        $this->assertStringNotContainsString((string) $asker->full_name, $theirs->getContent());

        $approver = $this->actingAs($this->amlaUser(['amla:ctr', 'amla:approve']))->getJson("/api/amla/ctr-candidates/{$other->id}")->assertOk();
        $this->assertFalse($approver->json('data.set_aside.requested_by_me'));
        $this->assertSame($asker->id, $approver->json('data.set_aside.requested_by.id'));
        $this->assertNull($approver->json('data.ctr_report_id'));
    }

    public function test_an_alert_sends_what_its_rule_counted_apart_from_every_linked_transaction(): void
    {
        $member = $this->member(attributes: ['monthly_income' => '150000.00']);
        foreach (['1000.00', '300000.00', '300000.00'] as $amount) {
            $this->event($member, $amount);
        }
        $this->detect();
        $alert = AmlaAlert::query()->where('rule_code', 'D')->where('borrower_id', $member->id)->sole();
        $this->event($member, '600000.00');
        $this->detect();

        $data = $this->actingAs($this->amlaUser(['amla:alerts']))->getJson("/api/amla/alerts/{$alert->id}")->assertOk()->json('data');
        $this->assertStringContainsString('₱601,000.00', $data['reason']);
        $this->assertSame(['₱601,000.00', 3], [$data['counted_amount'], $data['counted_transactions_count']]);
        $this->assertSame(['₱1,201,000.00', 4], [$data['amount'], $data['transactions_count']]);
    }

    public function test_covered_transactions_name_the_savings_account_of_a_savings_event(): void
    {
        $member = $this->member();
        $account = SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $member->branch_id, 'opened_on' => '2026-11-01']);
        $deposit = SavingsTransaction::factory()->create(['account_id' => $account->id]);
        $this->event($member, '650000.00', ['source_id' => $deposit->id]);
        $this->ctrCandidate();
        $this->detect();

        $rows = $this->actingAs($this->amlaUser(['amla:ctr']))->getJson('/api/amla/compliance-reports/covered_transactions?from=2026-11-01&to=2026-11-30')->assertOk()->json('data.rows');
        $this->assertEqualsCanonicalizing([$account->account_number, 'Data not available'], array_column($rows, 'account'));
    }

    public function test_the_amla_log_names_records_by_their_reference(): void
    {
        $candidate = $this->ctrCandidate();
        $reportId = $this->actingAs($this->amlaUser(['amla:ctr']))->postJson("/api/amla/ctr-candidates/{$candidate->id}/report")->assertCreated()->json('data.id');
        $report = AmlaReport::query()->findOrFail($reportId);
        $this->actingAs($this->amlaUser(['amla:ctr']))->getJson("/api/amla/reports/{$reportId}")->assertOk();
        $this->getJson("/api/amla/ctr-candidates/{$candidate->id}")->assertOk();

        $auditor = $this->headOffice(['amla:audit']);
        $entries = collect($this->actingAs($auditor)->getJson('/api/amla/log?per_page=100')->assertOk()->json('data'));
        $this->assertSame("Report {$report->reference}", $entries->firstWhere('subject_type', 'report')['subject_words']);
        $this->assertSame("CTR candidate {$candidate->reference}", $entries->firstWhere('subject_type', 'ctr_candidate')['subject_words']);

        $rows = collect($this->actingAs($auditor)->getJson('/api/amla/compliance-reports/audit_history?from=2026-11-20&to=2026-11-20')->assertOk()->json('data.rows'));
        $this->assertContains("Report {$report->reference}", $rows->pluck('subject')->all());
    }

    public function test_the_amla_log_names_an_orders_reference_only_to_sanctions_holders(): void
    {
        $officer = $this->headOffice(['amla:sanctions']);
        $member = $this->member();
        $id = $this->actingAs($officer)->postJson('/api/amla/orders', [
            'reference' => 'RTC Branch 12 Civil Case 2026-77 v. Santos', 'authority' => 'court', 'received_on' => '2026-11-20', 'borrower_id' => $member->id,
            'required_action' => 'Freeze the funds.', 'responsible_officer_id' => $officer->id, 'holds' => [],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        $auditOnly = $this->actingAs($this->headOffice(['amla:audit']))->getJson('/api/amla/log?subject_type=order')->assertOk();
        $this->assertSame("Order #{$id}", $auditOnly->json('data.0.subject_words'));
        $this->assertStringNotContainsString('Civil Case', $auditOnly->getContent());
        $rows = $this->actingAs($this->headOffice(['amla:audit']))->getJson('/api/amla/compliance-reports/audit_history?from=2026-11-20&to=2026-11-20')->assertOk();
        $this->assertStringNotContainsString('Civil Case', $rows->getContent());

        $this->actingAs($this->headOffice(['amla:audit', 'amla:sanctions']))->getJson('/api/amla/log?subject_type=order')->assertOk()
            ->assertJsonPath('data.0.subject_words', 'Order RTC Branch 12 Civil Case 2026-77 v. Santos');
    }

    public function test_a_case_opened_from_an_alert_keeps_the_opening_note_as_its_first_note(): void
    {
        $officer = $this->headOffice(['amla:alerts', 'amla:cases']);
        $alert = $this->structuringAlert();
        $case = $this->actingAs($officer)->postJson("/api/amla/alerts/{$alert->id}/escalate", ['title' => 'Structuring', 'note' => 'Two same-day cash deposits just below the CTR line.'])->assertCreated()->json('data');

        $this->assertSame([['Two same-day cash deposits just below the CTR line.', $officer->id]], array_map(fn (array $note) => [$note['body'], $note['by']['id']], $case['notes']));
        $this->assertSame('Two same-day cash deposits just below the CTR line.', $alert->actions()->where('action', 'escalated')->sole()->note);

        $this->event($alert->borrower, '300000.00');
        $this->event($alert->borrower, '300000.00');
        $this->detect();
        $second = AmlaAlert::query()->where('borrower_id', $alert->borrower_id)->where('rule_code', 'A')->whereKeyNot($alert->id)->sole();
        $this->actingAs($officer)->postJson("/api/amla/alerts/{$second->id}/escalate", ['case_id' => $case['id'], 'note' => 'Same member again.'])->assertCreated()
            ->assertJsonCount(1, 'data.notes');

        $third = $this->structuringAlert();
        $this->actingAs($officer)->postJson("/api/amla/alerts/{$third->id}/escalate", ['title' => 'No note'])->assertCreated()->assertJsonCount(0, 'data.notes');
    }
}
