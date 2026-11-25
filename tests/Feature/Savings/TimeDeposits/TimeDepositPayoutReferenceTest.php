<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\SavingsApproval;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Finish run round 5, time deposit payouts: money paid out at maturity or on
 * pre-termination by bank transfer, GCash or Maya needs its
 * `payout_reference` (422 in words), kept on the placement beside its payout
 * method. Cash needs none, nor does a payout into the member's savings or a
 * full rollover. Round 2's duplicate-reference rule covers it as it covers
 * withdrawals: checked when asked for, held while the payout waits for its
 * checker, and checked again when it posts.
 */
class TimeDepositPayoutReferenceTest extends TestCase
{
    use TimeDepositFixtures;

    private const WORDS = ['bank_transfer' => 'bank transfer', 'gcash' => 'GCash', 'maya' => 'Maya'];

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

    public function test_a_non_cash_payout_at_maturity_without_its_reference_is_refused(): void
    {
        $deposit = $this->placed(['principal' => '9000.00']);
        $this->toMaturity();

        foreach (self::WORDS as $method => $words) {
            foreach (['payout', 'rollover_principal'] as $action) {
                $this->settle($deposit, ['action' => $action, 'payout_method' => $method])
                    ->assertUnprocessable()
                    ->assertJsonPath('errors.payout_reference.0', "Enter the {$words} reference for this payout.");
            }
        }

        $this->assertSame('active', $deposit->refresh()->status);
    }

    public function test_with_its_reference_a_payout_at_maturity_keeps_it_on_the_placement(): void
    {
        $deposit = $this->placed(['principal' => '9000.00']);
        $this->toMaturity();

        $this->settle($deposit, ['payout_method' => 'gcash', 'payout_reference' => 'GC-PAY-1'])
            ->assertCreated()
            ->assertJsonPath('data.payout_method', 'gcash')
            ->assertJsonPath('data.payout_reference', 'GC-PAY-1');

        $this->assertSame('GC-PAY-1', $deposit->refresh()->payout_reference);
    }

    public function test_cash_savings_and_a_full_rollover_need_no_reference(): void
    {
        $cash = $this->placed(['principal' => '9000.00']);
        $account = $this->savingsAccount();
        $intoSavings = $this->placed(['principal' => '9000.00', 'payout_account_id' => $account->id]);
        $rollover = $this->placed(['principal' => '9000.00']);
        $this->toMaturity();

        $this->settle($cash)->assertCreated()->assertJsonPath('data.payout_reference', null);
        $this->settle($intoSavings, ['payout_method' => 'savings'])->assertCreated()->assertJsonPath('data.payout_reference', null);
        $this->settle($rollover, ['action' => 'rollover_all', 'payout_method' => 'gcash'])->assertCreated();
    }

    public function test_a_non_cash_pretermination_without_its_reference_is_refused(): void
    {
        $deposit = $this->placed();

        foreach (self::WORDS as $method => $words) {
            $this->preterminate($deposit, ['payout_method' => $method])
                ->assertUnprocessable()
                ->assertJsonPath('errors.payout_reference.0', "Enter the {$words} reference for this payout.");
        }

        $this->assertSame('active', $deposit->refresh()->status);
    }

    public function test_a_pretermination_waiting_for_its_checker_holds_its_reference_then_keeps_it(): void
    {
        $deposit = $this->placed();
        $approval = $this->preterminate($deposit, ['payout_method' => 'bank_transfer', 'payout_reference' => 'BDO-PT-1'])->assertStatus(202)->json('data.approval');
        $this->assertSame('BDO-PT-1', SavingsApproval::query()->findOrFail($approval['id'])->payload['payout_reference']);

        $other = $this->placed();
        $this->preterminate($other, ['payout_method' => 'bank_transfer', 'payout_reference' => 'BDO-PT-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_reference.0', 'Reference "BDO-PT-1" is held by a bank transfer payout waiting for approval. Check the reference, or decide that payout first.');

        $this->execute(SavingsApproval::query()->findOrFail($approval['id']), User::factory()->create(['branch_id' => $this->branch->id]));
        $this->assertSame(['preterminated', 'bank_transfer', 'BDO-PT-1'], [$deposit->refresh()->status, $deposit->payout_method, $deposit->payout_reference]);
    }

    public function test_cash_and_savings_preterminations_need_no_reference(): void
    {
        $this->preterminate($this->placed(['principal' => '9000.00']))->assertCreated()->assertJsonPath('data.payout_reference', null);

        $account = $this->savingsAccount();
        $this->preterminate($this->placed(['principal' => '9000.00', 'payout_account_id' => $account->id]), ['payout_method' => 'savings'])->assertCreated();
    }

    public function test_a_payout_reference_on_record_is_refused_both_ways(): void
    {
        $this->place(['payment_method' => 'maya', 'reference' => 'MY-1'])->assertCreated();
        $deposit = $this->placed(['principal' => '9000.00']);

        $this->preterminate($deposit, ['payout_method' => 'maya', 'payout_reference' => 'MY-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_reference.0', 'Reference "MY-1" is already on record for another Maya transaction. Check the reference and enter the right one.');

        $this->preterminate($deposit, ['payout_method' => 'maya', 'payout_reference' => 'MY-2'])->assertCreated();
        $this->place(['payment_method' => 'maya', 'reference' => 'MY-2'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "MY-2" is already on record for another Maya transaction. Check the reference and enter the right one.');
    }

    public function test_an_approved_payout_is_checked_again_when_it_posts(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();
        $approval = SavingsApproval::query()->findOrFail($this->settle($deposit, ['payout_method' => 'gcash', 'payout_reference' => 'GC-9'])->assertStatus(202)->json('data.approval.id'));
        // Taken while the payout waited, by a writer that never checks.
        DB::table('time_deposits')->where('id', $this->placed()->id)->update(['payout_method' => 'gcash', 'payout_reference' => 'GC-9']);

        $this->expectExceptionMessage('Reference "GC-9" is already on record for another GCash transaction.');
        $this->execute($approval, User::factory()->create(['branch_id' => $this->branch->id]));
    }

    private function placed(array $overrides = []): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place($overrides)->assertCreated()->json('data.id'));
    }

    private function toMaturity(): void
    {
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
    }

    private function settle(TimeDeposit $deposit, array $body = []): TestResponse
    {
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => now()->toDateString(), 'action' => 'payout', 'payout_method' => 'cash', ...$body]);
    }

    private function preterminate(TimeDeposit $deposit, array $body = []): TestResponse
    {
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", [
            'date' => now()->toDateString(), 'reason' => 'Member needs the money for tuition.', 'payout_method' => 'cash', ...$body,
        ]);
    }

    private function execute(SavingsApproval $approval, User $checker): mixed
    {
        $executor = app(ApprovalGate::class)->executor($approval->kind);

        return DB::transaction(function () use ($approval, $checker, $executor) {
            app(SavingsLocks::class)->accounts($executor->accountIds($approval));
            app(SavingsLocks::class)->timeDeposits($executor->timeDepositIds($approval));

            return $executor->execute($approval, $checker);
        });
    }
}
