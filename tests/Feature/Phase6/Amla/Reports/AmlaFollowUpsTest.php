<?php

namespace Tests\Feature\Phase6\Amla\Reports;

use App\Models\AmlaAlert;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaCtrSetAside;
use App\Models\AmlaEvent;
use App\Models\AmlaEventScreening;
use App\Models\AmlaInboxItem;
use App\Models\AmlaOrder;
use App\Models\AmlaOrderHold;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Detection\Detector;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Follow-ups from the AMLA reviews and browser checks (plan notes, AMLA-B7):
 * "Organisation-wide" for records with no branch, the detection rules'
 * parameter labels, pending change and base versions, rule L's wording,
 * set-asides in their own rows and kept on the deadlines, and the
 * detector's lower-bound hint with a nightly full pass.
 */
class AmlaFollowUpsTest extends TestCase
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

    public function test_a_record_with_no_branch_reads_organisation_wide(): void
    {
        $member = $this->member();
        foreach ([$this->branch->id, Branch::factory()->create()->id, Branch::factory()->create()->id] as $branchId) {
            $this->event($member, '1000.00', ['branch_id' => $branchId]);
        }
        $this->detect();
        $alert = AmlaAlert::query()->where('rule_code', 'J')->sole();

        $this->actingAs($this->headOffice(['amla:alerts']))->getJson("/api/amla/alerts/{$alert->id}")->assertOk()->assertJsonPath('data.branch.name', 'Organisation-wide');
    }

    public function test_the_detection_rules_send_parameter_labels_the_pending_change_and_each_versions_base(): void
    {
        $holder = $this->settingsHolder();
        $definition = app(DetectionRules::class)->current()->definition;
        $definition['H']['params']['min_transactions'] = 4;
        $this->actingAs($holder)->postJson('/api/amla/change-requests', ['type' => 'detection_rules', 'reason' => 'Tune.', 'definition' => $definition])->assertCreated();
        $pending = AmlaChangeRequest::query()->where('type', 'detection_rules')->sole();

        $data = $this->getJson('/api/amla/detection-rules')->assertOk()->json('data');
        $this->assertSame($pending->id, $data['pending_change_request_id']);
        $h = collect($data['rules'])->firstWhere('code', 'H');
        $this->assertSame(['name' => 'min_transactions', 'label' => 'Minimum transactions', 'kind' => 'count', 'value' => 5], $h['parameters'][0]);
        $l = collect($data['rules'])->firstWhere('code', 'L');
        $this->assertStringNotContainsString('AMLA-B', $l['availability']);
        $this->assertStringContainsString('watchlist screening', $l['availability']);

        $this->actingAs($this->settingsHolder())->postJson("/api/amla/change-requests/{$pending->id}/approve")->assertOk();
        $versions = collect($this->actingAs($holder)->getJson('/api/amla/detection-rules/versions')->json('data'))->keyBy('version');
        $this->assertNull($versions[1]['base_version']);
        $this->assertSame(1, $versions[2]['base_version']);
    }

    public function test_set_asides_keep_their_own_rows_and_a_waiting_candidate_stays_on_the_deadlines(): void
    {
        $this->confirmYear(2026);
        $candidate = $this->ctrCandidate(occurredOn: '2026-11-13');
        $first = $this->amlaUser(['amla:ctr']);
        $second = $this->amlaUser(['amla:ctr']);
        $approver = $this->amlaUser(['amla:approve']);

        $this->actingAs($first)->postJson("/api/amla/ctr-candidates/{$candidate->id}/not-reportable", ['reason' => 'First reason.'])->assertStatus(202);
        $this->artisan('amla:deadlines')->assertSuccessful();
        $this->assertSame(1, AmlaInboxItem::query()->where('user_id', $first->id)->where('subject_type', 'ctr_candidate')->where('kind', 'filing_due_today')->count());
        $this->actingAs($first)->getJson('/api/amla/filing-deadlines')->assertOk()->assertJsonPath('data.0.reference', $candidate->reference);

        $this->actingAs($approver)->postJson("/api/amla/ctr-candidates/{$candidate->id}/not-reportable/reject", ['reason' => 'It is reportable.'])->assertOk();
        $this->actingAs($second)->postJson("/api/amla/ctr-candidates/{$candidate->id}/not-reportable", ['reason' => 'Second reason.'])->assertStatus(202);

        $rows = AmlaCtrSetAside::query()->where('ctr_candidate_id', $candidate->id)->orderBy('id')->get();
        $this->assertSame([['First reason.', $first->id, 'rejected', 'It is reportable.'], ['Second reason.', $second->id, 'pending', null]],
            $rows->map(fn (AmlaCtrSetAside $row) => [$row->reason, $row->requested_by, $row->status, $row->decision_note])->all());
        $this->assertNotSame('It is reportable.', AmlaCtrSetAside::query()->toBase()->where('id', $rows[0]->id)->value('decision_note'));

        $this->actingAs($this->amlaUser(['amla:approve']))->postJson("/api/amla/ctr-candidates/{$candidate->id}/not-reportable/approve")->assertOk();
        $this->assertSame(['Second reason.', $second->id], [$candidate->fresh()->not_reportable_reason, $candidate->fresh()->set_aside_requested_by]);
    }

    public function test_the_minute_run_screens_from_a_lower_bound_and_the_nightly_pass_screens_everything(): void
    {
        $member = $this->member();
        $old = $this->event($member, '1000.00');
        AmlaEvent::query()->whereKey($old->id)->toBase()->update(['created_at' => '2026-11-17 10:00:00']);
        $new = $this->event($member, '1000.00');

        $this->detect();
        $this->assertTrue(AmlaEventScreening::query()->where('event_id', $new->id)->exists());
        $this->assertFalse(AmlaEventScreening::query()->where('event_id', $old->id)->exists());

        app(Detector::class)->run(full: true);
        $this->assertTrue(AmlaEventScreening::query()->where('event_id', $old->id)->exists());
    }

    public function test_a_hold_under_a_legal_order_offers_no_release_and_the_hold_names_no_amla_record(): void
    {
        $member = $this->member();
        $account = SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $member->branch_id, 'opened_on' => '2026-11-01']);
        $manual = SavingsHold::factory()->create(['account_id' => $account->id, 'type' => 'manual', 'amount' => '100.00', 'reason' => 'Counter hold.', 'status' => 'active']);
        $legal = SavingsHold::factory()->create(['account_id' => $account->id, 'type' => 'legal', 'amount' => '500.00', 'reason' => 'Held under a legal order.', 'status' => 'active']);
        $order = AmlaOrder::query()->create([
            'reference' => 'FO-2026-1', 'authority' => 'court', 'received_on' => '2026-11-20', 'borrower_id' => $member->id,
            'required_action' => 'Freeze', 'status' => 'active', 'placed_by' => $this->admin->id,
        ]);
        AmlaOrderHold::query()->create(['order_id' => $order->id, 'savings_hold_id' => $legal->id, 'account_id' => $account->id, 'amount' => '500.00']);

        $response = $this->actingAs($this->admin)->getJson("/api/savings/accounts/{$account->id}/holds")->assertOk();
        $holds = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($holds[$manual->id]['can_release']);
        $this->assertFalse($holds[$legal->id]['can_release']);
        $this->assertSame('Held under a legal order.', $holds[$legal->id]['reason']);
        $this->assertStringNotContainsStringIgnoringCase('amla', $response->getContent());
    }
}
