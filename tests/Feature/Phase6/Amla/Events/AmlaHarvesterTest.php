<?php

namespace Tests\Feature\Phase6\Amla\Events;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaEvent;
use App\Models\AmlaEventFailure;
use App\Models\AmlaHarvestCursor;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Models\TimeDeposit;
use App\Services\Amla\Events\Harvester;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The AMLA harvester (AMLA design §4.2–4.4, AMLA-B2): read-only, from go-live,
 * one event per customer money movement keyed by (source, id, kind), the
 * amount being the source row's own as the lists show it, and the branch
 * BranchAttribution's. Internal movements are linked but never new funds.
 */
class AmlaHarvesterTest extends TestCase
{
    use AmlaEventTestHelpers, AmlaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_does_nothing_while_monitoring_is_off(): void
    {
        config(['amla.monitoring_enabled' => false]);
        $this->createReleasedLoan();

        $this->assertNull(app(Harvester::class)->harvest());
        $this->assertSame(0, AmlaHarvestCursor::query()->count());
        $this->assertSame(0, AmlaEvent::query()->count());

        $this->artisan('amla:harvest')->expectsOutputToContain('AMLA monitoring is off')->assertSuccessful();
        $this->assertSame(0, AmlaEvent::query()->count());
    }

    public function test_the_first_run_starts_monitoring_and_takes_nothing_from_before_it(): void
    {
        $this->at('2026-11-20 08:30:00');
        $this->createReleasedLoan();

        $this->goLive();
        $this->harvestLater();

        $this->assertSame(0, AmlaEvent::query()->count());
        $this->assertSame(8, AmlaHarvestCursor::query()->count());
        $cursor = AmlaHarvestCursor::query()->where('source', 'repayments')->sole();
        $this->assertSame('2026-11-20 08:59:55', $cursor->started_at->format('Y-m-d H:i:s'));
    }

    public function test_a_payment_is_one_event_with_payment_historys_amount_and_the_loans_branch(): void
    {
        $this->goLive();
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => '2026-11-20', 'amount_paid' => 5432.10, 'method' => 'gcash', 'reference_number' => 'GC-778',
        ])->assertCreated();
        $repayment = Repayment::query()->where('loan_id', $loan->id)->sole();

        $this->harvestLater();

        $event = $this->soleEvent('repayment', $repayment->id);
        $listed = collect($this->getJson('/api/repayments?per_page=100')->assertOk()->json('data'))->firstWhere('id', $repayment->id);

        $this->assertSame('loan_payment', $event->kind);
        $this->assertSame('5432.10', $event->amount);
        $this->assertSame(number_format((float) $listed['amount_paid'], 2, '.', ''), $event->amount);
        $this->assertSame('in', $event->direction);
        $this->assertFalse($event->is_internal);
        $this->assertSame('gcash', $event->channel);
        $this->assertSame($loan->borrower_id, $event->borrower_id);
        $this->assertSame($loan->branch_id, $event->branch_id);
        $this->assertSame('2026-11-20', $event->occurred_on->toDateString());
        $this->assertSame($repayment->receipt_number, $event->reference);
        $this->assertSame('monitor', $event->harvested_by);
        $this->assertNull($event->reversed_at);
    }

    public function test_a_void_marks_the_payments_event_once_and_keeps_its_amount(): void
    {
        $this->goLive();
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['payment_date' => '2026-11-20', 'amount_paid' => 5000, 'method' => 'cash'])->assertCreated();
        $repayment = Repayment::query()->where('loan_id', $loan->id)->sole();
        $this->harvestLater();

        $this->at('2026-11-20 09:10:00');
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Duplicate entry'])->assertOk();
        $this->harvestLater('2026-11-20 09:12:00');
        $this->harvestLater('2026-11-20 09:13:00');

        $event = $this->soleEvent('repayment', $repayment->id);
        $this->assertSame('5000.00', $event->amount);
        $this->assertNotNull($event->reversed_at);
        $this->assertSame('repayment_void', $event->reversal_source);
        $this->assertSame($repayment->id, $event->reversal_source_id);
    }

    public function test_a_payment_voided_before_it_was_harvested_is_harvested_already_reversed(): void
    {
        $this->goLive();
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['payment_date' => '2026-11-20', 'amount_paid' => 5000, 'method' => 'cash'])->assertCreated();
        $repayment = Repayment::query()->where('loan_id', $loan->id)->sole();
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Duplicate entry'])->assertOk();

        $this->harvestLater();

        $this->assertNotNull($this->soleEvent('repayment', $repayment->id)->reversed_at);
    }

    public function test_a_release_pays_out_its_net_proceeds_and_an_imported_loan_is_not_a_release(): void
    {
        $this->goLive();
        $loan = $this->createReleasedLoan();
        Loan::factory()->create([
            'borrower_id' => $loan->borrower_id, 'branch_id' => $loan->branch_id, 'status' => 'ongoing',
            'external_loan_no' => 'OLD-1', 'released_at' => now(), 'net_proceeds' => 1000,
        ]);

        $this->harvestLater();

        $event = $this->soleEvent('loan', $loan->id);
        $this->assertSame('loan_release', $event->kind);
        $this->assertSame((string) $loan->fresh()->net_proceeds, $event->amount);
        $this->assertSame('out', $event->direction);
        $this->assertFalse($event->is_internal);
        $this->assertSame('cash', $event->channel);
        $this->assertSame($loan->branch_id, $event->branch_id);
        $this->assertSame(1, AmlaEvent::query()->where('source', 'loan')->count());
    }

    public function test_a_restructures_carried_principal_is_internal_and_its_collected_deductions_are_money_in(): void
    {
        $this->goLive();
        $source = $this->createReleasedLoan();
        $restructure = Loan::factory()->create([
            'borrower_id' => $source->borrower_id, 'branch_id' => $source->branch_id, 'source_loan_id' => $source->id,
            'status' => 'released', 'released_at' => now(), 'release_method' => 'bank_transfer',
            'principal_amount' => 40000, 'total_deductions' => 850.25, 'net_proceeds' => 39149.75,
        ]);

        $this->harvestLater();

        $events = collect($this->eventsFor('loan', $restructure->id))->keyBy('kind');
        $this->assertSame(['loan_restructure', 'restructure_deductions_collected'], $events->keys()->sort()->values()->all());
        $this->assertTrue($events['loan_restructure']->is_internal);
        $this->assertSame('40000.00', $events['loan_restructure']->amount);
        $this->assertSame('loan', $events['loan_restructure']->linked_source);
        $this->assertSame($source->id, $events['loan_restructure']->linked_source_id);
        $this->assertFalse($events['restructure_deductions_collected']->is_internal);
        $this->assertSame('in', $events['restructure_deductions_collected']->direction);
        $this->assertSame('850.25', $events['restructure_deductions_collected']->amount);
        $this->assertSame('bank_transfer', $events['restructure_deductions_collected']->channel);
    }

    public function test_savings_rows_keep_their_own_amounts_and_internal_movements_are_never_new_funds(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $account = $this->savingsAccountFor($member->id, $this->branch->id);
        $other = $this->savingsAccountFor($member->id, $this->branch->id);

        $deposit = $this->savingsRow($account, 'credit', 'deposit', 1_234_567, ['method' => 'cash']);
        $withdrawal = $this->savingsRow($account, 'debit', 'withdrawal', 20_050, ['method' => 'bank_transfer']);
        $out = $this->savingsRow($account, 'debit', 'transfer_out', 10_000, ['method' => 'savings']);
        $in = $this->savingsRow($other, 'credit', 'transfer_in', 10_000, ['method' => 'savings', 'counterpart_transaction_id' => $out->id]);
        $interest = $this->savingsRow($account, 'credit', 'interest', 125, []);

        $this->harvestLater();

        $listed = collect($this->getJson("/api/savings/accounts/{$account->id}/transactions?per_page=100")->assertOk()->json('data'))->keyBy('id');

        $depositEvent = $this->soleEvent('savings_transaction', $deposit->id);
        $this->assertSame(['savings_deposit', 'in', false, 'cash', '12345.67'], [$depositEvent->kind, $depositEvent->direction, $depositEvent->is_internal, $depositEvent->channel, $depositEvent->amount]);
        $this->assertSame($listed[$deposit->id]['credit'], $depositEvent->amount);

        $withdrawalEvent = $this->soleEvent('savings_transaction', $withdrawal->id);
        $this->assertSame(['savings_withdrawal', 'out', false, '200.50'], [$withdrawalEvent->kind, $withdrawalEvent->direction, $withdrawalEvent->is_internal, $withdrawalEvent->amount]);
        $this->assertSame($listed[$withdrawal->id]['debit'], $withdrawalEvent->amount);

        foreach ([$out, $in, $interest] as $row) {
            $this->assertTrue($this->soleEvent('savings_transaction', $row->id)->is_internal, "{$row->type} must be internal");
        }

        $this->assertSame($this->branch->id, $depositEvent->branch_id);
        $this->assertSame($member->id, $depositEvent->borrower_id);
    }

    public function test_a_savings_reversal_is_its_own_internal_event_and_marks_the_row_it_reverses(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $account = $this->savingsAccountFor($member->id, $this->branch->id);
        $deposit = $this->savingsRow($account, 'credit', 'deposit', 50_000, ['method' => 'cash']);
        $this->harvestLater();

        $this->at('2026-11-20 09:10:00');
        $reversal = $this->savingsRow($account, 'debit', 'reversal', 50_000, ['method' => 'cash', 'reverses_transaction_id' => $deposit->id]);
        $this->harvestLater('2026-11-20 09:12:00');

        $original = $this->soleEvent('savings_transaction', $deposit->id);
        $this->assertSame('500.00', $original->amount);
        $this->assertNotNull($original->reversed_at);
        $this->assertSame('savings_transaction', $original->reversal_source);
        $this->assertSame($reversal->id, $original->reversal_source_id);

        $reversalEvent = $this->soleEvent('savings_transaction', $reversal->id);
        $this->assertSame('savings_reversal', $reversalEvent->kind);
        $this->assertTrue($reversalEvent->is_internal);
        $this->assertSame($deposit->id, $reversalEvent->linked_source_id);
    }

    public function test_time_deposits_place_and_pay_out_and_savings_funding_or_rollover_is_internal(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $cash = TimeDeposit::factory()->create(['borrower_id' => $member->id, 'principal' => '75000.00', 'funding_method' => 'maya']);
        $fromSavings = TimeDeposit::factory()->create(['borrower_id' => $member->id, 'funding_method' => 'savings']);
        $rolled = TimeDeposit::factory()->create(['borrower_id' => $member->id, 'funding_method' => 'rollover', 'rolled_over_from_id' => $cash->id]);
        $this->harvestLater();

        $placement = $this->soleEvent('time_deposit', $cash->id);
        $this->assertSame(['time_deposit_placement', 'in', false, 'maya', '75000.00'], [$placement->kind, $placement->direction, $placement->is_internal, $placement->channel, $placement->amount]);
        $this->assertSame($cash->branch_id, $placement->branch_id);
        $this->assertTrue($this->soleEvent('time_deposit', $fromSavings->id)->is_internal);
        $this->assertTrue($this->soleEvent('time_deposit', $rolled->id)->is_internal);

        $this->at('2026-11-20 09:10:00');
        DB::table('time_deposits')->where('id', $cash->id)->update([
            'status' => 'withdrawn', 'settled_on' => '2026-11-20', 'settled_amount' => '75616.44', 'payout_method' => 'cash', 'updated_at' => now(),
        ]);
        $this->harvestLater('2026-11-20 09:12:00');

        $events = collect($this->eventsFor('time_deposit', $cash->id))->keyBy('kind');
        $this->assertSame('75616.44', $events['time_deposit_payout']->amount);
        $this->assertSame('out', $events['time_deposit_payout']->direction);
        $this->assertFalse($events['time_deposit_payout']->is_internal);
        $this->assertSame('cash', $events['time_deposit_payout']->channel);
    }

    public function test_only_unlinked_share_capital_rows_are_events(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $loan = $this->createReleasedLoan();
        $manual = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-11-20', 'credit' => 2500.5, 'debit' => 0, 'method' => 'cash']);
        $withdrawn = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-11-20', 'credit' => 0, 'debit' => 300, 'method' => 'cash']);
        $linked = ShareCapitalLedger::factory()->create(['borrower_id' => $loan->borrower_id, 'loan_id' => $loan->id, 'date' => '2026-11-20', 'credit' => 500, 'debit' => 0]);

        $this->harvestLater();

        $in = $this->soleEvent('share_capital', $manual->id);
        $this->assertSame(['share_capital_contribution', 'in', '2500.50', 'cash'], [$in->kind, $in->direction, $in->amount, $in->channel]);
        $this->assertSame($this->branch->id, $in->branch_id);
        $out = $this->soleEvent('share_capital', $withdrawn->id);
        $this->assertSame(['share_capital_withdrawal', 'out', '300.00'], [$out->kind, $out->direction, $out->amount]);
        $this->assertSame([], $this->eventsFor('share_capital', $linked->id));
    }

    public function test_gcash_events_carry_the_cash_received_or_paid_out_as_the_gcash_list_shows_it(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $otherBranch = Branch::factory()->create();
        $walkIn = GCashNonMember::factory()->create();
        $cashIn = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020, 'status' => 'paid', 'paid_at' => now()]);
        $cashOut = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'type' => 'cash_out', 'amount' => 3000, 'charge_amount' => 45, 'total_amount' => 2955, 'status' => 'completed']);
        $walkInRow = GCashTransaction::factory()->forNonMember($walkIn)->create(['branch_id' => $otherBranch->id, 'amount' => 500, 'charge_amount' => 10, 'total_amount' => 510, 'status' => 'paid', 'paid_at' => now()]);
        $pending = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'pending']);

        $this->harvestLater();

        $listed = collect($this->getJson('/api/gcash/transactions?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $in = $this->soleEvent('gcash', $cashIn->id);
        $this->assertSame(['gcash_cash_in', 'in', 'cash', '1020.00'], [$in->kind, $in->direction, $in->channel, $in->amount]);
        $this->assertSame(number_format((float) $listed[$cashIn->id]['total_amount'], 2, '.', ''), $in->amount);
        $this->assertSame($this->branch->id, $in->branch_id);

        $out = $this->soleEvent('gcash', $cashOut->id);
        $this->assertSame(['gcash_cash_out', 'out', '2955.00'], [$out->kind, $out->direction, $out->amount]);
        $this->assertSame(number_format((float) $listed[$cashOut->id]['total_amount'], 2, '.', ''), $out->amount);

        $walkInEvent = $this->soleEvent('gcash', $walkInRow->id);
        $this->assertNull($walkInEvent->borrower_id);
        $this->assertSame($walkIn->id, $walkInEvent->gcash_non_member_id);
        $this->assertSame($otherBranch->id, $walkInEvent->branch_id);

        $this->assertSame([], $this->eventsFor('gcash', $pending->id));

        $this->at('2026-11-20 09:10:00');
        DB::table('gcash_transactions')->where('id', $pending->id)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
        $this->harvestLater('2026-11-20 09:12:00');
        $this->assertSame('1020.00', $this->soleEvent('gcash', $pending->id)->amount);
    }

    public function test_rerunning_over_the_overlap_never_doubles_an_event(): void
    {
        $this->goLive();
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['payment_date' => '2026-11-20', 'amount_paid' => 5000, 'method' => 'cash'])->assertCreated();

        $this->harvestLater('2026-11-20 09:02:00');
        $first = AmlaEvent::query()->count();
        $this->harvestLater('2026-11-20 09:02:30');
        $this->harvestLater('2026-11-20 09:03:00');

        $this->assertSame(2, $first);
        $this->assertSame($first, AmlaEvent::query()->count());
    }

    public function test_a_row_that_cant_be_built_is_a_failure_and_the_cursor_still_moves(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $broken = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 999, 'status' => 'paid', 'paid_at' => now()]);
        $fine = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);

        $counts = $this->harvestLater();
        $this->harvestLater('2026-11-20 09:06:00');

        $this->assertSame(1, $counts['gcash']['failed']);
        $failure = AmlaEventFailure::query()->where('source', 'gcash')->where('source_id', $broken->id)->sole();
        $this->assertStringContainsString('does not reconcile', $failure->reason);
        $this->assertSame([], $this->eventsFor('gcash', $broken->id));
        $this->assertCount(1, $this->eventsFor('gcash', $fine->id));
        $this->assertSame('2026-11-20 09:05:55', AmlaHarvestCursor::query()->where('source', 'gcash')->sole()->watermark->format('Y-m-d H:i:s'));

        $this->at('2026-11-20 09:07:00');
        DB::table('gcash_transactions')->where('id', $broken->id)->update(['total_amount' => 1020]);
        $this->harvestLater('2026-11-20 09:08:00');
        $this->assertSame('1020.00', $this->soleEvent('gcash', $broken->id)->amount);
        $this->assertSame(1, AmlaEventFailure::query()->count());
    }

    public function test_harvesting_writes_no_source_table_no_audit_row_and_no_notice(): void
    {
        $this->goLive();
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['payment_date' => '2026-11-20', 'amount_paid' => 5000, 'method' => 'cash'])->assertCreated();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $account = $this->savingsAccountFor($member->id, $this->branch->id);
        $this->savingsRow($account, 'credit', 'deposit', 50_000, ['method' => 'cash']);
        GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-11-20', 'credit' => 100, 'debit' => 0]);
        TimeDeposit::factory()->create(['borrower_id' => $member->id]);

        $sums = $this->sourceChecksums();
        $outside = $this->outsideAmlaCounts();
        $this->harvestLater();

        $this->assertGreaterThan(5, AmlaEvent::query()->count());
        $this->assertSame($sums, $this->sourceChecksums());
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_events_are_kept_forever_and_only_the_reversal_mark_is_ever_written_once(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);
        $this->harvestLater();
        $event = AmlaEvent::query()->sole();

        $this->expectExceptionObject(AmlaRecordIsAppendOnlyException::update('amla_events', $event->id));
        $event->forceFill(['amount' => '1.00'])->save();
    }

    public function test_an_event_cant_be_deleted_or_marked_reversed_twice(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);
        $this->harvestLater();
        $event = AmlaEvent::query()->sole();

        $event->markReversed('gcash', 1, now());
        $this->assertNotNull($event->fresh()->reversed_at);

        try {
            $event->fresh()->markReversed('gcash', 2, now());
            $this->fail('A second reversal mark was written.');
        } catch (AmlaRecordIsAppendOnlyException) {
        }

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $event->fresh()->delete();
    }

    public function test_the_harvest_command_reports_each_source(): void
    {
        $this->goLive();
        $this->createReleasedLoan();
        $this->at('2026-11-20 09:05:00');

        $this->artisan('amla:harvest')->expectsOutputToContain('Loan releases: 1 event')->assertSuccessful();
    }
}
