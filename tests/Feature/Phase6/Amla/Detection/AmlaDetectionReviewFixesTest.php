<?php

namespace Tests\Feature\Phase6\Amla\Detection;

use App\Models\AmlaAlert;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaDetectionLock;
use App\Models\AmlaEvent;
use App\Models\AmlaEventScreening;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Events\Coverage;
use App\Services\Amla\Events\Harvester;
use Illuminate\Support\Carbon;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * PR #343 money review: screening is recorded per event (so an event saved
 * below another, later, is still screened), coverage counts unscreened
 * events, rules E and I find every receipt or release, branch users see only
 * alerts wholly in their branches, and each event is judged by the rule and
 * settings versions in force when it was recorded.
 */
class AmlaDetectionReviewFixesTest extends TestCase
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

    public function test_an_event_saved_below_already_screened_ones_is_still_screened(): void
    {
        $member = $this->member();
        $high = AmlaEvent::query()->forceCreate([...$this->event($member, '1000.00')->only(['source', 'kind', 'direction', 'is_internal', 'channel', 'borrower_id', 'branch_id', 'occurred_on', 'recorded_at', 'harvested_by']),
            'id' => 50_000, 'source_id' => 777_001, 'amount' => '1000.00']);
        $this->detect();
        $this->assertTrue(AmlaEventScreening::query()->where('event_id', $high->id)->exists());

        $late = AmlaEvent::query()->forceCreate([...$high->only(['source', 'kind', 'direction', 'is_internal', 'channel', 'borrower_id', 'branch_id', 'occurred_on', 'recorded_at', 'harvested_by']),
            'id' => 40_000, 'source_id' => 777_002, 'amount' => '900000.00']);
        $this->detect();

        $this->assertSame(1, AmlaCtrCandidate::query()->where('event_id', $late->id)->count());
        $this->assertSame(1, AmlaEventScreening::query()->where('event_id', $late->id)->count());
        $this->detect();
        $this->assertSame(AmlaEvent::query()->count(), AmlaEventScreening::query()->count());
    }

    public function test_coverage_counts_harvested_events_not_yet_screened_as_unscreened(): void
    {
        app(Harvester::class)->harvest();
        $this->travelTo(Carbon::parse('2026-11-20 10:01:00', 'Asia/Manila'));
        GCashTransaction::factory()->create(['borrower_id' => $this->member()->id, 'status' => 'paid', 'paid_at' => now()]);
        $this->travelTo(Carbon::parse('2026-11-20 10:05:00', 'Asia/Manila'));
        app(Harvester::class)->harvest();

        $before = app(Coverage::class)->check('2026-11-20', store: false);
        $this->detect();
        $after = app(Coverage::class)->check('2026-11-20', store: false);

        $this->assertSame(['rows' => 1, 'events' => 1, 'unscreened' => 1], array_intersect_key($before['gcash'], array_flip(['rows', 'events', 'unscreened'])));
        $this->assertSame(0, $after['gcash']['unscreened']);
    }

    public function test_rapid_movement_alerts_each_receipt_and_a_void_clears_only_the_one_that_no_longer_holds(): void
    {
        $member = $this->member();
        $first = $this->event($member, '100000.00', ['occurred_on' => '2026-11-18', 'channel' => 'bank_transfer']);
        $this->event($member, '80000.00', ['direction' => 'out', 'kind' => 'savings_withdrawal', 'channel' => 'bank_transfer', 'occurred_on' => '2026-11-18']);
        $second = $this->event($member, '100000.00', ['occurred_on' => '2026-11-20', 'channel' => 'bank_transfer']);
        $this->event($member, '80000.00', ['direction' => 'out', 'kind' => 'savings_withdrawal', 'channel' => 'bank_transfer', 'occurred_on' => '2026-11-20']);
        $this->detect();

        $alerts = collect($this->alerts('E'));
        $this->assertCount(2, $alerts);

        $first->markReversed('savings_transaction', 1, now());
        $this->detect();

        $byReceipt = fn (AmlaEvent $receipt) => AmlaAlert::query()->where('rule_code', 'E')->whereHas('events', fn ($e) => $e->whereKey($receipt->id))
            ->get()->first(fn (AmlaAlert $alert) => $alert->window_start->toDateString() === $receipt->occurred_on->toDateString());
        $this->assertSame('cleared', $byReceipt($first)->status);
        $this->assertSame('open', $byReceipt($second)->status);
    }

    public function test_a_loan_alert_is_kept_for_each_release_it_still_matches(): void
    {
        $member = $this->member();
        $older = $this->event($member, '200000.00', ['source' => 'loan', 'kind' => 'loan_release', 'direction' => 'out', 'occurred_on' => '2026-11-05']);
        $newer = $this->event($member, '150000.00', ['source' => 'loan', 'kind' => 'loan_release', 'direction' => 'out', 'occurred_on' => '2026-11-12']);
        $this->event($member, '120000.00', ['source' => 'repayment', 'kind' => 'loan_payment']);
        $this->detect();

        $this->assertCount(2, $this->alerts('I'));
        $newer->markReversed('loan', 1, now());
        $this->detect();

        $statuses = collect($this->alerts('I'))->mapWithKeys(fn (AmlaAlert $alert) => [$alert->window_start->toDateString() => $alert->status])->sortKeys()->all();
        $this->assertSame(['2026-11-05' => 'open', '2026-11-12' => 'cleared'], $statuses);
        $this->assertNotNull($older->id);
    }

    public function test_branch_users_never_see_an_alert_with_a_transaction_outside_their_branches(): void
    {
        $other = Branch::factory()->create();
        $member = $this->member();
        $this->event($member, '300000.00');
        $this->event($member, '300000.00', ['branch_id' => $other->id]);
        $noBranch = $this->member();
        $this->event($noBranch, '300000.00');
        $this->event($noBranch, '300000.00', ['branch_id' => null]);
        $inside = $this->member();
        $this->event($inside, '300000.00');
        $this->event($inside, '300000.00');
        $this->detect();

        $mixed = AmlaAlert::query()->where('rule_code', 'A')->where('borrower_id', $member->id)->sole();
        $partial = AmlaAlert::query()->where('rule_code', 'A')->where('borrower_id', $noBranch->id)->sole();
        $mine = AmlaAlert::query()->where('rule_code', 'A')->where('borrower_id', $inside->id)->sole();

        foreach ([$this->branch, $other] as $branch) {
            $user = $this->amlaUser(['amla:alerts']);
            $user->branches()->sync([$branch->id]);
            $this->actingAs($user->fresh());
            $listed = array_column($this->getJson('/api/amla/alerts?per_page=100')->assertOk()->json('data'), 'id');
            $this->assertNotContains($mixed->id, $listed);
            $this->assertNotContains($partial->id, $listed);
            $this->getJson("/api/amla/alerts/{$mixed->id}")->assertNotFound();
            $this->getJson("/api/amla/alerts/{$partial->id}")->assertNotFound();
        }

        $this->actingAs($this->amlaUser(['amla:alerts']));
        $this->getJson("/api/amla/alerts/{$mine->id}")->assertOk();
        $this->actingAs($this->amlaUser(['amla:alerts', 'branches:view_all']));
        $this->getJson("/api/amla/alerts/{$mixed->id}")->assertOk();
    }

    public function test_reading_the_rules_in_force_needs_head_office_access(): void
    {
        $this->actingAs($this->amlaUser(['amla:alerts']));
        $this->getJson('/api/amla/detection-rules')->assertForbidden();

        $this->actingAs($this->amlaUser(['amla:alerts', 'branches:view_all']));
        $this->getJson('/api/amla/detection-rules')->assertOk();
    }

    public function test_an_event_voided_before_it_was_screened_still_gets_its_ctr_candidate_marked_reversed(): void
    {
        $event = $this->event($this->member(), '800000.00', ['reversed_at' => '2026-11-20 09:30:00', 'reversal_source' => 'repayment_void', 'reversal_source_id' => 1]);
        $this->detect();

        $candidate = AmlaCtrCandidate::query()->where('event_id', $event->id)->sole();
        $this->assertSame('2026-11-20 09:30:00', $candidate->event_reversed_at->format('Y-m-d H:i:s'));
    }

    public function test_structuring_watches_the_ctr_cash_channels_in_the_settings(): void
    {
        $maker = $this->settingsHolder();
        $this->actingAs($maker);
        $id = $this->postJson('/api/amla/change-requests', ['type' => 'settings', 'reason' => 'GCash counts as cash', 'values' => ['ctr_cash_channels' => ['cash', 'gcash']]])->assertCreated()->json('data.id');
        $this->actingAs($this->settingsHolder());
        $this->postJson("/api/amla/change-requests/{$id}/approve")->assertOk();

        $member = $this->member();
        $this->event($member, '300000.00', ['channel' => 'gcash']);
        $this->event($member, '300000.00', ['channel' => 'gcash']);
        $this->detect();

        $this->assertCount(1, $this->alerts('A'));
        $this->assertArrayNotHasKey('channels', app(DetectionRules::class)->current()->definition['A']['params']);
    }

    public function test_an_event_is_judged_by_the_rule_version_in_force_when_it_was_recorded(): void
    {
        $old = $this->member();
        foreach (range(1, 3) as $i) {
            $this->event($old, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in', 'recorded_at' => '2026-11-20 09:00:00']);
        }

        $definition = app(DetectionRules::class)->current()->definition;
        $definition['H']['params']['min_transactions'] = 3;
        $this->actingAs($this->settingsHolder());
        $this->postJson('/api/amla/change-requests', ['type' => 'detection_rules', 'reason' => 'Tune GCash', 'definition' => $definition])->assertCreated();
        $this->actingAs($this->settingsHolder());
        $this->postJson('/api/amla/change-requests/'.AmlaChangeRequest::query()->where('type', 'detection_rules')->value('id').'/approve')->assertOk();

        $new = $this->member();
        foreach (range(1, 3) as $i) {
            $this->event($new, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
        }
        $this->detect();

        $this->assertSame(0, AmlaAlert::query()->where('rule_code', 'H')->where('borrower_id', $old->id)->count());
        $this->assertSame(2, AmlaAlert::query()->where('rule_code', 'H')->where('borrower_id', $new->id)->sole()->rule_version);
        $this->assertSame(1, AmlaEventScreening::query()->whereIn('event_id', AmlaEvent::query()->where('borrower_id', $old->id)->select('id'))->max('rule_version'));
    }

    public function test_a_gcash_counter_row_is_a_gcash_anomaly_and_a_cash_ctr_but_a_gcash_loan_payment_is_neither(): void
    {
        $counter = $this->member();
        foreach (range(1, 4) as $i) {
            $this->event($counter, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in', 'channel' => 'cash']);
        }
        $bigCounter = $this->event($counter, '600000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in', 'channel' => 'cash']);
        $payer = $this->member();
        foreach (range(1, 4) as $i) {
            $this->event($payer, '1000.00', ['source' => 'repayment', 'kind' => 'loan_payment', 'channel' => 'gcash']);
        }
        $bigPayment = $this->event($payer, '600000.00', ['source' => 'repayment', 'kind' => 'loan_payment', 'channel' => 'gcash']);
        $this->detect();

        $this->assertSame(1, AmlaAlert::query()->where('rule_code', 'H')->where('borrower_id', $counter->id)->count());
        $this->assertSame(0, AmlaAlert::query()->where('rule_code', 'H')->where('borrower_id', $payer->id)->count());
        $this->assertTrue(AmlaCtrCandidate::query()->where('event_id', $bigCounter->id)->exists());
        $this->assertFalse(AmlaCtrCandidate::query()->where('event_id', $bigPayment->id)->exists());
    }

    public function test_the_detector_locks_the_row_the_migration_wrote_and_never_creates_one(): void
    {
        $this->assertContains('detector', AmlaDetectionLock::query()->pluck('name')->all());
        $locks = AmlaDetectionLock::query()->count();

        $this->event($this->member(), '800000.00');
        $this->detect();
        $this->detect();

        $this->assertSame($locks, AmlaDetectionLock::query()->count());
        $this->assertSame(1, AmlaCtrCandidate::query()->count());
    }
}
