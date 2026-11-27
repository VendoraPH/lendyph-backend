<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AccountingJournal;
use App\Models\SavingsApproval;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reversals (design §2, §8): always through a checker, a linked `reversal`
 * row that never edits the original, the original's journal reversed, once
 * per row (409 while pending or once done), never for interest, tax,
 * closing, time deposit or reversal rows, and both legs of a transfer.
 */
class ReversalTest extends TestCase
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

    private function requestReversal(SavingsTransaction $row, string $reason = 'Keyed twice'): TestResponse
    {
        return $this->money("/api/savings/transactions/{$row->id}/reverse", ['reason' => $reason]);
    }

    public function test_a_reversal_waits_for_a_checker_then_posts_a_linked_row_and_reverses_the_journal(): void
    {
        $account = $this->account();
        $deposit = $this->fund($account, 50_000, '2026-11-09');
        $original = $deposit->fresh()->getAttributes();
        $this->actingAs($maker = $this->teller());

        $approval = $this->requestReversal($deposit)->assertStatus(202)
            ->assertJsonPath('data.approval.kind', 'reversal')
            ->assertJsonPath('data.approval.target_transaction_id', $deposit->id)
            ->assertJsonPath('data.approval.target_reference', $deposit->reference)
            ->assertJsonPath('data.approval.amount', '500.00')
            ->assertJsonPath('data.approval.required_checkers', 1)
            ->json('data.approval');

        $this->getJson("/api/savings/transactions/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.pending_reversal_approval_id', $approval['id'])
            ->assertJsonPath('data.reversed_by_transaction_id', null);
        // A pending reversal of a credit reserves the money.
        $this->getJson("/api/savings/accounts/{$account->id}")->assertJsonPath('data.available_balance', '0.00');

        $this->requestReversal($deposit)->assertStatus(409);
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertForbidden();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $reversal = SavingsTransaction::query()->where('type', 'reversal')->sole();
        $this->assertSame([$deposit->id, '500.00', '0.00', '0.00', '2026-11-10', '2026-11-09', $maker->id], [
            $reversal->reverses_transaction_id, $reversal->debit, $reversal->credit, $reversal->balance_after,
            $reversal->date->toDateString(), $reversal->value_date->toDateString(), $reversal->created_by,
        ]);
        $this->assertSame($original, $deposit->fresh()->getAttributes(), 'the original row changed');

        $journal = $this->journalFor($deposit);
        $this->assertSame('reversed', $journal->status);
        $this->assertNotNull($journal->reversed_by_journal_id);
        $this->assertSame('2026-11-10', AccountingJournal::query()->findOrFail($journal->reversed_by_journal_id)->date->toDateString());

        $this->getJson("/api/savings/transactions/{$deposit->id}")
            ->assertJsonPath('data.reversed_by_transaction_id', $reversal->id)
            ->assertJsonPath('data.pending_reversal_approval_id', null);

        $this->actingAs($maker);
        $this->requestReversal($deposit)->assertStatus(409);
    }

    public function test_interest_tax_closing_time_deposit_and_reversal_rows_cannot_be_reversed(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $rows = DB::transaction(function () use ($account) {
            $ledger = app(SavingsLedger::class);

            return [
                $ledger->credit($account, 'interest', 500, ['value_date' => '2026-11-09', 'created_by' => null]),
                $ledger->debit($account, 'withholding_tax', 100, ['value_date' => '2026-11-09', 'created_by' => null]),
                $ledger->debit($account, 'closing', 100, ['method' => 'cash']),
            ];
        });

        foreach ($rows as $row) {
            $this->requestReversal($row)->assertUnprocessable()->assertJsonValidationErrors('transaction');
        }

        $this->assertSame(0, SavingsApproval::query()->count());
    }

    public function test_a_time_deposits_funding_debit_or_payout_credit_cannot_be_reversed(): void
    {
        $account = $this->account(['ledger_balance' => '100000.00']);
        $placement = TimeDeposit::factory()->create(['borrower_id' => $this->member->id]);
        $rows = [
            SavingsTransaction::factory()->create([
                'account_id' => $account->id, 'type' => 'withdrawal', 'debit' => '50000.00', 'credit' => '0.00',
                'balance_after' => '50000.00', 'method' => 'savings', 'time_deposit_id' => $placement->id,
            ]),
            SavingsTransaction::factory()->create([
                'account_id' => $account->id, 'type' => 'deposit', 'debit' => '0.00', 'credit' => '50375.00',
                'balance_after' => '100375.00', 'method' => 'savings', 'time_deposit_id' => $placement->id,
            ]),
        ];

        foreach ($rows as $row) {
            $this->requestReversal($row)->assertUnprocessable()->assertJsonValidationErrors('transaction');
        }

        $this->assertSame(0, SavingsApproval::query()->count());
    }

    public function test_reversing_a_withdrawal_credits_it_back(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $withdrawal = SavingsTransaction::query()->findOrFail(
            $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '400.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated()->json('data.id'),
        );

        $approval = $this->requestReversal($withdrawal)->assertStatus(202)->json('data.approval');
        // A pending reversal of a debit reserves nothing.
        $this->getJson("/api/savings/accounts/{$account->id}")->assertJsonPath('data.available_balance', '600.00');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();

        $this->assertSame('1000.00', $this->balanceOf($account));
        $this->assertSame('reversed', $this->journalFor($withdrawal)->status);
    }

    public function test_reversing_either_leg_of_a_transfer_reverses_both(): void
    {
        $from = $this->account();
        $to = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->fund($from, 100_000);
        $legs = $this->money("/api/savings/accounts/{$from->id}/transfers", ['to_account_id' => $to->id, 'amount' => '300.00', 'date' => '2026-11-10'])
            ->assertCreated()->json('data');

        $out = SavingsTransaction::query()->findOrFail($legs['transfer_out']['id']);
        $approval = $this->requestReversal($out)->assertStatus(202)->json('data.approval');

        $this->getJson("/api/savings/transactions/{$legs['transfer_in']['id']}")->assertJsonPath('data.pending_reversal_approval_id', $approval['id']);
        $this->requestReversal(SavingsTransaction::query()->findOrFail($legs['transfer_in']['id']))->assertStatus(409);

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();

        $this->assertSame(['1000.00', '0.00'], [$this->balanceOf($from), $this->balanceOf($to)]);
        $this->assertSame(2, SavingsTransaction::query()->where('type', 'reversal')->count());
        $this->getJson("/api/savings/transactions/{$legs['transfer_out']['id']}")->assertJsonPath('data.reversed_by_transaction_id', fn ($id) => $id !== null);
    }

    public function test_a_reversal_request_can_be_rejected_or_cancelled_and_frees_the_row(): void
    {
        $account = $this->account();
        $deposit = $this->fund($account, 50_000);
        $this->actingAs($maker = $this->teller());

        $first = $this->requestReversal($deposit)->assertStatus(202)->json('data.approval');
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$first['id']}/cancel")->assertForbidden();
        $this->postJson("/api/savings/approvals/{$first['id']}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/savings/approvals/{$first['id']}/reject", ['reason' => 'Deposit was right'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'Deposit was right');

        $this->actingAs($maker);
        $second = $this->requestReversal($deposit)->assertStatus(202)->json('data.approval');
        $this->postJson("/api/savings/approvals/{$second['id']}/reject", ['reason' => 'mine'])->assertForbidden();
        $this->postJson("/api/savings/approvals/{$second['id']}/cancel", ['reason' => 'Wrong row'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/savings/approvals/{$second['id']}/cancel")->assertStatus(409);

        $this->requestReversal($deposit)->assertStatus(202);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'reversal')->count());
    }
}
