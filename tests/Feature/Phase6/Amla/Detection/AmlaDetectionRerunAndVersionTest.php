<?php

namespace Tests\Feature\Phase6\Amla\Detection;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaAlert;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaEvent;
use App\Models\AmlaRuleVersion;
use App\Models\Borrower;
use App\Models\Repayment;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Events\Harvester;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reruns, versions and the whole path (AMLA-B3): a void or reversal marks its
 * event (AMLA-B2) and the rules run again, so an alert that no longer holds
 * is cleared with a note and a CTR candidate records the reversal; a rule
 * change is a second person's approved change request and adds a version
 * that later alerts cite; real money rows go from the harvester to alerts,
 * writing nothing outside AMLA.
 */
class AmlaDetectionRerunAndVersionTest extends TestCase
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

    public function test_a_void_reruns_the_rules_clearing_an_alert_that_no_longer_holds_and_marking_the_ctr_candidate(): void
    {
        $member = $this->member();
        $kept = $this->event($member, '300000.00');
        $voided = $this->event($member, '300000.00', ['source' => 'repayment', 'kind' => 'loan_payment']);
        $big = $this->event($this->member(), '800000.00', ['source' => 'repayment', 'kind' => 'loan_payment']);
        $this->detect();
        $alert = AmlaAlert::query()->where('rule_code', 'A')->sole();

        $voided->markReversed('repayment_void', $voided->source_id, now());
        $big->markReversed('repayment_void', $big->source_id, now());
        $this->detect();
        $this->detect();

        $alert->refresh();
        $this->assertSame('cleared', $alert->status);
        $this->assertNotNull($alert->cleared_at);
        $this->assertStringContainsString('no longer meets the rule', $alert->cleared_note);
        $this->assertStringContainsString($voided->reference, $alert->cleared_note);
        $this->assertSame(2, $alert->events()->count());
        $this->assertNotNull(AmlaCtrCandidate::query()->where('event_id', $big->id)->sole()->event_reversed_at);
        $this->assertNull(AmlaEvent::query()->find($kept->id)->reversed_at);
    }

    public function test_an_alert_that_still_holds_after_a_reversal_stays_open(): void
    {
        $member = $this->member();
        foreach (range(1, 6) as $i) {
            $events[] = $this->event($member, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
        }
        $this->detect();
        $events[0]->markReversed('gcash', 1, now());
        $this->detect();

        $this->assertSame('open', AmlaAlert::query()->where('rule_code', 'H')->sole()->status);
    }

    public function test_a_rule_change_needs_a_second_settings_holder_and_later_alerts_cite_the_new_version(): void
    {
        $maker = $this->settingsHolder();
        $checker = $this->settingsHolder();
        $definition = app(DetectionRules::class)->current()->definition;
        $definition['H']['params']['min_transactions'] = 3;

        $this->actingAs($maker);
        $this->postJson('/api/amla/change-requests', ['type' => 'detection_rules', 'reason' => 'Tune GCash', 'definition' => $definition])->assertCreated();
        $request = AmlaChangeRequest::query()->where('type', 'detection_rules')->sole();
        $this->postJson("/api/amla/change-requests/{$request->id}/approve")->assertForbidden();

        $bad = $definition;
        $bad['G']['active'] = true;
        $this->actingAs($checker);
        $this->postJson('/api/amla/change-requests', ['type' => 'detection_rules', 'reason' => 'x', 'definition' => $bad])->assertUnprocessable();
        $this->postJson("/api/amla/change-requests/{$request->id}/approve")->assertOk();

        $this->assertSame([1, 2], AmlaRuleVersion::query()->orderBy('version')->pluck('version')->all());
        $member = $this->member();
        foreach (range(1, 3) as $i) {
            $this->event($member, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
        }
        $this->detect();
        $this->assertSame(2, AmlaAlert::query()->where('rule_code', 'H')->sole()->rule_version);
        $this->assertSame(3, AmlaRuleVersion::query()->where('version', 2)->sole()->definition['H']['params']['min_transactions']);
    }

    public function test_real_money_rows_go_from_the_harvester_to_alerts_and_ctr_candidates_writing_nothing_outside_amla(): void
    {
        app(Harvester::class)->harvest();
        $this->travelTo(Carbon::parse('2026-11-20 10:01:00', 'Asia/Manila'));
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $account = SavingsAccount::factory()->create(['borrower_id' => $member->id, 'product_id' => SavingsProduct::factory()->create()->id, 'branch_id' => $this->branch->id, 'opened_on' => '2026-11-01']);
        DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'deposit', 50_000_001, ['method' => 'cash']));
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['payment_date' => '2026-11-20', 'amount_paid' => 30000, 'method' => 'cash'])->assertCreated();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['payment_date' => '2026-11-20', 'amount_paid' => 30000, 'method' => 'cash'])->assertCreated();

        $sums = collect(['loans', 'repayments', 'savings_transactions', 'gcash_transactions', 'share_capital_ledger', 'time_deposits', 'borrowers'])
            ->mapWithKeys(fn (string $table) => [$table => (string) DB::selectOne("CHECKSUM TABLE `{$table}`")->Checksum])->all();
        $outside = $this->outsideAmlaCounts();
        $this->travelTo(Carbon::parse('2026-11-20 10:05:00', 'Asia/Manila'));
        app(Harvester::class)->harvest();
        $this->detect();

        $candidate = AmlaCtrCandidate::query()->sole();
        $this->assertSame('500000.01', $candidate->amount);
        $this->assertSame($member->id, $candidate->borrower_id);
        $this->assertSame(1, AmlaAlert::query()->where('rule_code', 'K')->where('borrower_id', $member->id)->count());

        $repayment = Repayment::query()->where('loan_id', $loan->id)->orderBy('id')->first();
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Duplicate entry'])->assertOk();
        $this->travelTo(Carbon::parse('2026-11-20 10:10:00', 'Asia/Manila'));
        $sums['repayments'] = (string) DB::selectOne('CHECKSUM TABLE `repayments`')->Checksum;
        $sums['loans'] = (string) DB::selectOne('CHECKSUM TABLE `loans`')->Checksum;
        $sums['share_capital_ledger'] = (string) DB::selectOne('CHECKSUM TABLE `share_capital_ledger`')->Checksum;
        $outside = $this->outsideAmlaCounts();
        app(Harvester::class)->harvest();
        $this->detect();

        $this->assertSame($sums, collect(array_keys($sums))->mapWithKeys(fn (string $table) => [$table => (string) DB::selectOne("CHECKSUM TABLE `{$table}`")->Checksum])->all());
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_alerts_and_candidates_are_kept_forever(): void
    {
        $this->event($this->member(), '800000.00');
        $this->detect();

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        AmlaCtrCandidate::query()->sole()->delete();
    }
}
