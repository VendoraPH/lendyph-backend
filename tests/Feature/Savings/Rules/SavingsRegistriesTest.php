<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Savings\Api\Reverser;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The savings row types and approval kinds registered ahead of the slices
 * that carry them out (gap round S1): `share_capital_transfer` rows post Dr
 * 2050 / Cr 3060 and are never reversed, and an approval of a kind with no
 * executor yet answers 422 in words, never a 500, and stays pending.
 */
class SavingsRegistriesTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_new_kinds_and_types_are_registered(): void
    {
        $this->assertContains('share_capital_transfer', SavingsApproval::KINDS);
        $this->assertContains('reactivation', SavingsApproval::KINDS);
        $this->assertContains('share_capital_transfer', SavingsApproval::OUTFLOW_KINDS);
        $this->assertNotContains('reactivation', SavingsApproval::OUTFLOW_KINDS);
        $this->assertContains('fee', SavingsTransaction::DEBIT_TYPES);
        $this->assertContains('share_capital_transfer', SavingsTransaction::DEBIT_TYPES);
        $this->assertNotContains('share_capital_transfer', Reverser::REVERSIBLE);
        $this->assertNotContains('fee', Reverser::REVERSIBLE);
    }

    public function test_a_share_capital_transfer_row_posts_dr_2050_cr_3060_and_cant_be_reversed(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);

        $row = DB::transaction(function () use ($account): SavingsTransaction {
            $row = app(SavingsLedger::class)->debit($account, 'share_capital_transfer', 30_000, ['method' => 'savings']);
            app(AutomaticPoster::class)->savingsTransaction($row, $this->admin->id);

            return $row;
        });

        $this->assertSame([['2050', 30_000, 0], ['3060', 0, 30_000]], $this->journalLines($this->journalFor($row)));
        $this->money("/api/savings/transactions/{$row->id}/reverse", ['reason' => 'Wrong'])->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');
    }

    public function test_an_approval_of_a_kind_with_no_executor_yet_answers_422_and_stays_pending(): void
    {
        // `share_capital_transfer` has its executor since gap round S3, so this
        // uses `reactivation`, the kind still waiting for its own slice.
        $this->assertTrue(ApprovalGate::hasExecutor('share_capital_transfer'));
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-05']);
        $approval = DB::transaction(fn () => app(ApprovalGate::class)->open('reactivation', 0, ['account_id' => $account->id], $this->admin->id));

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval->id}/approve")->assertUnprocessable()
            ->assertJsonPath('errors.approval.0', 'A reactivation request can\'t be carried out yet: this version has no way to complete it. Reject or cancel it instead.');
        $this->assertSame('pending', $approval->refresh()->status);
    }
}
