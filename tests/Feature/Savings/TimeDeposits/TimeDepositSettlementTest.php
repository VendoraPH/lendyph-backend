<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settling at maturity (design §2, §5, §8): never before the maturity date,
 * once only, at the contract's own figures; a rollover places again at the
 * rate in force on the maturity date; a cash payout above tier 1 waits for a
 * checker (202), while a payout into the member's own savings account moves
 * no cash and posts at once.
 */
class TimeDepositSettlementTest extends TestCase
{
    use TimeDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    private function placed(array $overrides = []): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place($overrides)->assertCreated()->json('data.id'));
    }

    private function toMaturity(): void
    {
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
    }

    private function settle(TimeDeposit $deposit, array $body = [])
    {
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => now()->toDateString(), 'action' => 'payout', 'payout_method' => 'cash', ...$body]);
    }

    public function test_settling_before_the_maturity_date_is_422_and_writes_nothing(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-07 09:00:00', 'Asia/Manila'));

        $this->settle($deposit)
            ->assertUnprocessable()
            ->assertJsonFragment(['date' => ["{$deposit->certificate_number} matures on Feb 8, 2027. Before then, pre-terminate it instead."]]);

        $this->assertSame('active', $deposit->refresh()->status);
        $this->assertNull($this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement'));
    }

    public function test_a_payout_within_tier_1_posts_at_once_at_the_contract_figures(): void
    {
        // ₱9,000.00 × 3% × 90 ÷ 365 = ₱66.58: ₱9,066.58 is within the ₱10,000.00 tier.
        $deposit = $this->placed(['principal' => '9000.00']);
        $this->toMaturity();

        $data = $this->settle($deposit)->assertCreated()->json('data');

        $this->assertSame($deposit->id, $data['id']);
        $this->assertSame('withdrawn', $data['status']);
        $this->assertSame('2027-02-08', $data['settled_on']);
        $this->assertSame('66.58', $data['settled_interest']);
        $this->assertSame('0.00', $data['settled_tax']);
        $this->assertSame('9066.58', $data['settled_amount']);
        $this->assertSame('cash', $data['payout_method']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->full_name], $data['settled_by']);

        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 900_000, 0], ['5160', 6_658, 0], ['1010', 0, 906_658]], $this->lines($journal));
        $this->assertSame('2027-02-08', $journal->date->toDateString());
    }

    public function test_a_cash_payout_above_tier_1_answers_202_and_a_checker_settles_it(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();

        $approval = $this->settle($deposit)->assertStatus(202)->json('data.approval');

        $this->assertSame('td_payout', $approval['kind']);
        $this->assertSame('pending', $approval['status']);
        $this->assertSame('100739.73', $approval['amount']);
        $this->assertSame(2, $approval['required_checkers']);
        $this->assertSame($deposit->id, $approval['time_deposit_id']);
        $this->assertSame($deposit->certificate_number, $approval['certificate_number']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->full_name], $approval['requested_by']);
        $this->assertNull($this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement'));
        $this->assertSame('active', $deposit->refresh()->status);

        $this->settle($deposit)->assertConflict();

        $checker = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->execute(SavingsApproval::query()->findOrFail($approval['id']), $checker);

        $deposit->refresh();
        $this->assertSame('withdrawn', $deposit->status);
        $this->assertSame($checker->id, $deposit->settled_by);
        $this->assertSame('100739.73', $deposit->settled_amount);
        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 10_000_000, 0], ['5160', 73_973, 0], ['1010', 0, 10_073_973]], $this->lines($journal));
    }

    public function test_the_payout_executor_refuses_a_placement_already_settled(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();
        $approval = SavingsApproval::query()->findOrFail($this->settle($deposit)->assertStatus(202)->json('data.approval.id'));
        DB::table('time_deposits')->where('id', $deposit->id)->update(['status' => 'withdrawn', 'settled_on' => '2027-02-08']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->execute($approval, User::factory()->create());
    }

    public function test_a_rollover_uses_the_rate_in_force_on_the_maturity_date(): void
    {
        $this->rate($this->product, 90, '4.5000', '2027-01-01');
        $this->rate($this->regular, null, '1.5000', '2027-01-01');
        $deposit = $this->placed(['maturity_instruction' => 'rollover_all']);
        $this->assertSame('3.0000', $deposit->annual_rate);
        $this->toMaturity();

        $new = $this->settle($deposit, ['action' => 'rollover_all'])->assertCreated()->json('data');

        $this->assertNotSame($deposit->id, $new['id']);
        $this->assertSame($deposit->id, $new['rolled_over_from_id']);
        $this->assertSame('100739.73', $new['principal']);
        $this->assertSame('4.5000', $new['annual_rate']);
        $this->assertSame('1.5000', $new['pretermination_rate']);
        $this->assertSame('2027-02-08', $new['placement_date']);
        $this->assertSame('2027-05-09', $new['maturity_date']);
        $this->assertSame(90, $new['term_days']);
        $this->assertSame('rollover_all', $new['maturity_instruction']);
        $this->assertNull($new['funding_method']);
        $this->assertSame('active', $new['status']);

        $quote = $this->postJson('/api/savings/time-deposits/quote', [
            'product_id' => $this->product->id, 'principal' => '100739.73', 'term_days' => 90, 'placement_date' => '2027-02-08',
        ])->assertOk()->json('data');
        $this->assertSame($quote['maturity_amount'], $new['maturity_amount']);

        $old = $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()->json('data');
        $this->assertSame('rolled_over', $old['status']);
        $this->assertSame($new['id'], $old['rolled_over_to_id']);
        $this->assertNull($old['payout_method']);

        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 10_000_000, 0], ['5160', 73_973, 0], ['2060', 0, 10_073_973]], $this->lines($journal));
        $this->assertNull($this->journalFor(TimeDeposit::class, $new['id'], 'time_deposit'));
    }

    public function test_rolling_over_the_principal_pays_the_interest_out(): void
    {
        $deposit = $this->placed(['maturity_instruction' => 'rollover_principal']);
        $this->toMaturity();

        $new = $this->settle($deposit, ['action' => 'rollover_principal', 'payout_method' => 'gcash'])->assertCreated()->json('data');

        $this->assertSame('100000.00', $new['principal']);
        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 10_000_000, 0], ['5160', 73_973, 0], ['2060', 0, 10_000_000], ['1030', 0, 73_973]], $this->lines($journal));
        $this->assertSame('gcash', $deposit->refresh()->payout_method);
    }

    public function test_settling_twice_is_409(): void
    {
        $deposit = $this->placed(['principal' => '9000.00']);
        $this->toMaturity();
        $this->settle($deposit)->assertCreated();

        $this->settle($deposit)->assertConflict();
        $this->assertSame(1, AccountingJournal::query()->where('postable_type', TimeDeposit::class)->where('source', 'time_deposit_settlement')->count());
    }

    public function test_a_payout_into_savings_moves_no_cash_so_needs_no_checker(): void
    {
        $account = $this->savingsAccount();
        $deposit = $this->placed(['payout_account_id' => $account->id]);
        $this->toMaturity();

        $this->settle($deposit, ['payout_method' => 'savings'])->assertCreated()->assertJsonPath('data.status', 'withdrawn');

        $credit = SavingsTransaction::query()->where('account_id', $account->id)->sole();
        $this->assertSame('deposit', $credit->type);
        $this->assertSame('savings', $credit->method);
        $this->assertSame($deposit->id, $credit->time_deposit_id);
        $this->assertSame('100739.73', $credit->credit);
        $this->assertSame('100739.73', $account->refresh()->ledger_balance);

        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 10_000_000, 0], ['5160', 73_973, 0], ['2050', 0, 10_073_973]], $this->lines($journal));
        $this->assertNull($this->journalFor(SavingsTransaction::class, $credit->id, 'savings'));
    }

    public function test_a_savings_payout_needs_a_payout_account(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();

        $this->settle($deposit, ['payout_method' => 'savings'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payout_method');
    }

    private function execute(SavingsApproval $approval, User $checker): ?SavingsTransaction
    {
        $gate = app(ApprovalGate::class);
        $executor = $gate->executor($approval->kind);

        return DB::transaction(function () use ($approval, $checker, $executor) {
            app(SavingsLocks::class)->accounts($executor->accountIds($approval));
            app(SavingsLocks::class)->timeDeposits($executor->timeDepositIds($approval));

            return $executor->execute($approval, $checker);
        });
    }
}
