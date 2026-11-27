<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Savings\PayoutReferences;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6: a withdrawal or closing payout sent by bank transfer,
 * GCash or Maya is often posted before its reference exists, so the reference
 * can be recorded afterwards (`POST /savings/transactions/{id}/reference`):
 * once, only while blank, never on a reversed row, under the posting rules
 * (trimmed, 64 characters, no reuse on the same method). It needs the
 * permission that posts that payout and the account's branch, moves no money,
 * posts nothing, and writes an audit row in words.
 */
class RecordPayoutReferenceTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account();
        $this->fund($this->savings, 500_000, '2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_gcash_withdrawal_gets_its_reference_recorded_once_with_nothing_moved_or_posted(): void
    {
        $row = $this->withdrawal('gcash');
        $this->getJson("/api/savings/transactions/{$row->id}")->assertOk()->assertJsonPath('data.can_record_reference', true);
        $journals = AccountingJournal::query()->count();
        $rows = SavingsTransaction::query()->count();
        $balance = $this->balanceOf($this->savings);

        $this->record($row, ['reference' => '  GC-PAY-77  '])
            ->assertOk()
            ->assertJsonPath('data.id', $row->id)
            ->assertJsonPath('data.external_reference', 'GC-PAY-77')
            ->assertJsonPath('data.can_record_reference', false);

        $this->assertSame('GC-PAY-77', SavingsTransaction::query()->findOrFail($row->id)->external_reference);
        $this->assertSame([$journals, $rows, $balance], [AccountingJournal::query()->count(), SavingsTransaction::query()->count(), $this->balanceOf($this->savings)]);

        $audit = AuditLog::query()->where('action', 'savings_payout_reference_recorded')->sole();
        $this->assertSame([SavingsTransaction::class, $row->id], [$audit->auditable_type, $audit->auditable_id]);
        $this->assertSame(['external_reference' => null], $audit->old_values);
        $this->assertSame(['external_reference' => 'GC-PAY-77'], $audit->new_values);
        $this->assertSame("Reference \"GC-PAY-77\" recorded on withdrawal {$row->reference} (GCash, ₱500.00) of savings account {$this->savings->account_number}.", $audit->description);

        // Once only: a recorded reference is never changed.
        $this->record($row, ['reference' => 'GC-PAY-78'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$row->reference} already has its reference (GC-PAY-77). A recorded reference can't be changed.");
        $this->assertSame(1, AuditLog::query()->where('action', 'savings_payout_reference_recorded')->count());
    }

    public function test_a_closing_payout_by_bank_transfer_needs_savings_close(): void
    {
        $this->money("/api/savings/accounts/{$this->savings->id}/close", [
            'date' => '2026-11-10', 'reason' => 'Member moving abroad', 'payout_method' => 'bank_transfer',
        ])->assertCreated();
        $row = SavingsTransaction::query()->where('type', 'closing')->sole();

        $transactOnly = $this->userWith(['savings:view', 'savings:transact']);
        $this->actingAs($transactOnly)->getJson("/api/savings/transactions/{$row->id}")->assertJsonPath('data.can_record_reference', false);
        $this->record($row, ['reference' => 'BDO-CLOSE-1'])->assertForbidden();

        $closer = $this->userWith(['savings:view', 'savings:close']);
        $this->actingAs($closer)->getJson("/api/savings/transactions/{$row->id}")->assertJsonPath('data.can_record_reference', true);
        $this->record($row, ['reference' => 'BDO-CLOSE-1'])->assertOk()->assertJsonPath('data.external_reference', 'BDO-CLOSE-1');

        $this->assertStringStartsWith("Reference \"BDO-CLOSE-1\" recorded on closing payout {$row->reference} (bank transfer, ",
            AuditLog::query()->where('action', 'savings_payout_reference_recorded')->sole()->description);
    }

    public function test_a_withdrawal_needs_savings_transact(): void
    {
        $row = $this->withdrawal('maya');

        $this->actingAs($this->userWith(['savings:view']));
        $this->record($row, ['reference' => 'MAYA-1'])->assertForbidden();

        $this->actingAs($this->userWith(['savings:view', 'savings:close']));
        $this->record($row, ['reference' => 'MAYA-1'])->assertForbidden();

        $this->actingAs($this->userWith(['savings:view', 'savings:transact']));
        $this->record($row, ['reference' => 'MAYA-1'])->assertOk();
    }

    public function test_another_branchs_payout_answers_404(): void
    {
        $row = $this->withdrawal('gcash');
        $elsewhere = Branch::factory()->create();

        $this->actingAs($this->userWith(['savings:view', 'savings:transact'], $elsewhere));
        $this->record($row, ['reference' => 'GC-1'])->assertNotFound();
        $this->assertNull(SavingsTransaction::query()->findOrFail($row->id)->external_reference);
    }

    public function test_cash_a_deposit_a_transfer_and_a_reversal_take_no_reference_afterwards(): void
    {
        $cash = $this->withdrawal('cash');
        $deposit = SavingsTransaction::query()->findOrFail($this->money("/api/savings/accounts/{$this->savings->id}/deposits", [
            'amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'gcash', 'reference' => 'GC-IN-1',
        ])->assertCreated()->json('data.id'));

        $this->record($cash, ['reference' => 'X-1'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$cash->reference} was paid in cash. Only a payout by bank transfer, GCash or Maya takes its reference afterwards.");
        $this->record($deposit, ['reference' => 'X-1'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'A reference can be recorded afterwards only on a withdrawal or a closing payout.');
        $this->getJson("/api/savings/transactions/{$cash->id}")->assertJsonPath('data.can_record_reference', false);
        $this->getJson("/api/savings/transactions/{$deposit->id}")->assertJsonPath('data.can_record_reference', false);
    }

    public function test_a_reversed_withdrawal_and_its_reversal_take_no_reference(): void
    {
        $row = $this->withdrawal('gcash');
        $this->actingAs($this->teller());
        $approval = $this->money("/api/savings/transactions/{$row->id}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval');
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();
        $reversal = SavingsTransaction::query()->where('type', 'reversal')->sole();

        $this->actingAs($this->admin);
        $this->record($row, ['reference' => 'GC-1'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$row->reference} was reversed, so no reference can be recorded on it.");
        $this->record($reversal, ['reference' => 'GC-1'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'A reference can be recorded afterwards only on a withdrawal or a closing payout.');
        $this->getJson("/api/savings/transactions/{$row->id}")->assertJsonPath('data.can_record_reference', false);
    }

    public function test_the_reference_follows_the_posting_rules(): void
    {
        $row = $this->withdrawal('gcash');
        $this->withdrawal('gcash', 'GC-TAKEN');

        $this->record($row, ['reference' => '   '])->assertUnprocessable()->assertJsonPath('errors.reference.0', 'Enter the reference of this payout.');
        $this->record($row, ['reference' => str_repeat('A', 65)])->assertUnprocessable()->assertJsonPath('errors.reference.0', 'The reference can be at most 64 characters.');
        $this->record($row, ['reference' => 'gc-taken'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "gc-taken" is already on record for another GCash transaction. Check the reference and enter the right one.');

        // The same reference on another method is a different payment.
        $this->record($row, ['reference' => str_repeat('A', 64)])->assertOk();
        $bank = $this->withdrawal('bank_transfer');
        $this->record($bank, ['reference' => 'GC-TAKEN'])->assertOk();
    }

    public function test_a_reference_held_by_a_withdrawal_waiting_for_approval_is_refused(): void
    {
        $row = $this->withdrawal('gcash');
        $this->fund($this->savings, 2_000_000, '2026-11-10');
        $this->actingAs($this->teller());
        $this->money("/api/savings/accounts/{$this->savings->id}/withdrawals", [
            'amount' => '15000.00', 'date' => '2026-11-10', 'payment_method' => 'gcash', 'reference' => 'GC-HELD',
        ])->assertStatus(202);

        $this->record($row, ['reference' => 'GC-HELD'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "GC-HELD" is held by a GCash withdrawal waiting for approval. Check the reference, or decide that withdrawal first.');
    }

    public function test_a_reference_recorded_by_someone_else_first_answers_409(): void
    {
        $row = $this->withdrawal('gcash');
        $stale = SavingsTransaction::query()->findOrFail($row->id);
        DB::table('savings_transactions')->where('id', $row->id)->update(['external_reference' => 'GC-FIRST']);

        try {
            DB::transaction(fn () => app(PayoutReferences::class)->recordOnTransaction($stale, 'GC-SECOND', $this->admin));
            $this->fail('A second reference was recorded.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame("Someone recorded the reference of {$row->reference} a moment ago. Refresh to see it.", $e->getResponse()->getData(true)['message']);
        }

        $this->assertSame('GC-FIRST', SavingsTransaction::query()->findOrFail($row->id)->external_reference);
        $this->assertSame(0, AuditLog::query()->where('action', 'savings_payout_reference_recorded')->count());
    }

    private function withdrawal(string $method, ?string $reference = null): SavingsTransaction
    {
        $id = $this->money("/api/savings/accounts/{$this->savings->id}/withdrawals", array_filter([
            'amount' => '500.00', 'date' => '2026-11-10', 'payment_method' => $method, 'reference' => $reference,
        ]))->assertCreated()->json('data.id');

        return SavingsTransaction::query()->findOrFail($id);
    }

    private function record(SavingsTransaction $row, array $body): TestResponse
    {
        return $this->postJson("/api/savings/transactions/{$row->id}/reference", $body);
    }
}
