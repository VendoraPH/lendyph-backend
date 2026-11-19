<?php

namespace Tests\Feature\Phase6\Amla\Detection;

use App\Models\AmlaAlert;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaLogEntry;
use App\Models\Branch;
use App\Models\GCashNonMember;
use Illuminate\Support\Carbon;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The detection endpoints (AMLA-B3): alerts list and show with `amla:alerts`,
 * CTR candidates with `amla:ctr`, the rules with any AMLA view. Branch users
 * see only their branches' records, and anything else answers 404. Every
 * view is written to the AMLA log, and nothing goes outside AMLA.
 */
class AmlaAlertsApiTest extends TestCase
{
    use AmlaTestHelpers, DetectionTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->startDetection();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A same-day structuring alert for a member of `$branchId`. */
    private function alertIn(int $branchId): AmlaAlert
    {
        $member = $this->member($branchId);
        $this->event($member, '300000.00');
        $this->event($member, '300000.00', ['reference' => null, 'channel' => 'cash']);
        $this->detect();

        return AmlaAlert::query()->where('borrower_id', $member->id)->where('rule_code', 'A')->sole();
    }

    public function test_the_list_and_show_need_amla_alerts_and_are_logged(): void
    {
        $alert = $this->alertIn($this->branch->id);
        $viewer = $this->amlaUser(['amla:view']);
        $officer = $this->amlaUser(['amla:alerts']);
        $outside = $this->outsideAmlaCounts();

        $this->actingAs($viewer);
        $this->getJson('/api/amla/alerts')->assertForbidden();
        $this->getJson("/api/amla/alerts/{$alert->id}")->assertForbidden();

        $this->actingAs($officer);
        $list = $this->getJson('/api/amla/alerts?rule=A')->assertOk()->json('data');
        $this->assertSame([$alert->reference], array_column($list, 'reference'));
        $this->assertSame('Transaction structuring', $list[0]['rule']);

        $shown = $this->getJson("/api/amla/alerts/{$alert->id}")->assertOk()->json('data');
        $this->assertSame(1, $shown['rule_version']);
        $this->assertSame('High', $shown['priority_words']);
        $this->assertCount(2, $shown['transactions']);
        $this->assertSame('₱600,000.00', $shown['amount']);
        $this->assertContains('Data not available', array_column($shown['transactions'], 'reference'));
        $this->assertSame('Data not available', $shown['subject']['account']);

        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'alerts_viewed')->where('user_id', $officer->id)->count());
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'alert_viewed')->where('subject_type', 'alert')->where('subject_id', $alert->id)->count());
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_branch_users_see_only_their_branches_alerts_and_others_answer_404(): void
    {
        $other = Branch::factory()->create();
        $mine = $this->alertIn($this->branch->id);
        $theirs = $this->alertIn($other->id);
        $walkIn = GCashNonMember::factory()->create(['id_type' => null, 'id_number' => null]);
        $this->event($this->member(), '60000.00', ['borrower_id' => null, 'gcash_non_member_id' => $walkIn->id, 'source' => 'gcash', 'kind' => 'gcash_cash_in']);
        $this->detect();
        $walkInAlert = AmlaAlert::query()->where('gcash_non_member_id', $walkIn->id)->firstOrFail();

        $this->actingAs($this->amlaUser(['amla:alerts']));
        $ids = array_column($this->getJson('/api/amla/alerts?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($walkInAlert->id, $ids);
        $this->getJson("/api/amla/alerts/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/amla/alerts/{$walkInAlert->id}")->assertNotFound();
        $this->getJson('/api/amla/alerts/999999999')->assertNotFound();
        $this->getJson('/api/amla/alerts/1234567890123456789')->assertNotFound();
        $this->getJson("/api/amla/alerts?branch_id={$other->id}")->assertForbidden();

        $this->actingAs($this->amlaUser(['amla:alerts', 'branches:view_all']));
        $all = array_column($this->getJson('/api/amla/alerts?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($theirs->id, $all);
        $this->assertContains($walkInAlert->id, $all);
    }

    public function test_ctr_candidates_need_amla_ctr_and_follow_the_branch_scope(): void
    {
        $other = Branch::factory()->create();
        $this->event($this->member(), '650000.00');
        $this->event($this->member($other->id), '700000.00');
        $this->detect();
        [$mine, $theirs] = AmlaCtrCandidate::query()->orderBy('id')->get()->all();

        $this->actingAs($this->amlaUser(['amla:alerts']));
        $this->getJson('/api/amla/ctr-candidates')->assertForbidden();

        $this->actingAs($this->amlaUser(['amla:ctr']));
        $rows = $this->getJson('/api/amla/ctr-candidates')->assertOk()->json('data');
        $this->assertSame([$mine->reference], array_column($rows, 'reference'));
        $this->assertSame('₱650,000.00', $rows[0]['amount']);
        $this->assertSame('Detected', $rows[0]['status_words']);
        $this->getJson("/api/amla/ctr-candidates/{$mine->id}")->assertOk()->assertJsonPath('data.settings_version', 1);
        $this->getJson("/api/amla/ctr-candidates/{$theirs->id}")->assertNotFound();
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'ctr_candidate_viewed')->where('subject_id', $mine->id)->count());
    }

    public function test_the_rules_read_in_words_with_their_version_for_any_amla_holder(): void
    {
        $this->actingAs($this->amlaUser(['amla:cdd']));
        $this->getJson('/api/amla/detection-rules')->assertForbidden();

        $this->actingAs($this->amlaUser(['amla:alerts', 'branches:view_all']));
        $data = $this->getJson('/api/amla/detection-rules')->assertOk()->json('data');
        $this->assertSame(1, $data['version']);
        $this->assertTrue($data['to_verify']);
        $rules = collect($data['rules'])->keyBy('code');
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'], $rules->keys()->all());
        $this->assertFalse($rules['G']['active']);
        $this->assertSame('Not available: Lendy records no payer or counterparty.', $rules['G']['availability']);
        $this->assertStringContainsString('watchlist screening', $rules['L']['availability']);
        $this->assertStringContainsString('starting point', $data['note']);
        $this->getJson('/api/amla/detection-rules/versions')->assertForbidden();

        $this->actingAs($this->amlaUser(['amla:settings', 'branches:view_all']));
        $this->assertCount(1, $this->getJson('/api/amla/detection-rules/versions')->assertOk()->json('data'));
    }
}
