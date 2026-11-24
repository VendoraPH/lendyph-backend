<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 2, decision 11: a deposit, withdrawal or opening deposit
 * paid by bank transfer, GCash or Maya whose reference is already on record
 * for the same method anywhere in the cooperative (a savings transaction or a
 * time deposit placement, or a withdrawal still waiting for its checker) is
 * refused with a 422 that names only the reference. Cash, auto-credit and
 * the system's own rows are exempt. New records only: a row that was reversed,
 * or a withdrawal request rejected, frees its reference.
 */
class DuplicateReferenceTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private const TAKEN = 'Reference "GC-778899" is already on record for another GCash transaction. Check the reference and enter the right one.';

    private const BDO_9 = 'Reference "BDO-9" is already on record for another bank transfer. Check the reference and enter the right one.';

    private const BDO_9_HELD = 'Reference "BDO-9" is held by a bank transfer withdrawal waiting for approval. Check the reference, or decide that withdrawal first.';

    private SavingsAccount $mine;

    private SavingsAccount $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->mine = $this->account();
        $this->fund($this->mine, 5_000_000);

        $neighbour = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active', 'last_name' => 'Delacruz']);
        $this->theirs = $this->account(['borrower_id' => $neighbour->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_deposit_whose_reference_is_on_record_for_the_same_method_is_refused_naming_only_the_reference(): void
    {
        $this->deposit($this->theirs, 'gcash', 'GC-778899')->assertCreated();
        $before = SavingsTransaction::query()->count();

        $message = $this->deposit($this->mine, 'gcash', 'GC-778899')->assertUnprocessable()->json('errors.reference.0');

        $this->assertSame(self::TAKEN, $message);
        $this->assertStringNotContainsString('Delacruz', $message);
        $this->assertStringNotContainsString($this->theirs->account_number, $message);
        $this->assertSame($before, SavingsTransaction::query()->count());
    }

    public function test_a_withdrawal_reusing_a_deposits_reference_is_refused(): void
    {
        $this->deposit($this->theirs, 'gcash', 'GC-778899')->assertCreated();

        $this->withdrawal($this->mine, 'gcash', 'GC-778899')->assertUnprocessable()->assertJsonPath('errors.reference.0', self::TAKEN);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_a_deposit_reusing_a_time_deposit_placements_reference_is_refused(): void
    {
        TimeDeposit::factory()->create(['borrower_id' => $this->theirs->borrower_id, 'funding_method' => 'gcash', 'reference' => 'GC-778899']);

        $this->deposit($this->mine, 'gcash', 'GC-778899')->assertUnprocessable()->assertJsonPath('errors.reference.0', self::TAKEN);
    }

    public function test_an_opening_deposit_is_checked_too(): void
    {
        $this->deposit($this->theirs, 'maya', 'MY-1')->assertCreated();
        $product = SavingsProduct::factory()->create(['name' => 'Payroll Savings', 'minimum_opening_balance' => '0.00']);

        $this->money('/api/savings/accounts', [
            'borrower_id' => $this->member->id, 'product_id' => $product->id, 'opened_at' => '2026-11-10',
            'initial_deposit' => '1500.00', 'payment_method' => 'maya', 'reference' => 'MY-1',
        ])->assertUnprocessable()->assertJsonPath('errors.reference.0', 'Reference "MY-1" is already on record for another Maya transaction. Check the reference and enter the right one.');

        $this->assertFalse(SavingsAccount::query()->where('product_id', $product->id)->exists());
    }

    public function test_a_withdrawal_waiting_for_its_checker_holds_its_reference_and_still_posts_with_it(): void
    {
        $this->actingAs($this->teller());
        $approval = $this->withdrawal($this->mine, 'bank_transfer', 'BDO-9', '10000.01')->assertStatus(202)->json('data.approval');

        $this->deposit($this->theirs, 'bank_transfer', 'BDO-9')->assertUnprocessable()->assertJsonPath('errors.reference.0', self::BDO_9_HELD);
        $this->withdrawal($this->mine, 'bank_transfer', 'bdo-9')->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "bdo-9" is held by a bank transfer withdrawal waiting for approval. Check the reference, or decide that withdrawal first.');
        $this->deposit($this->theirs, 'gcash', 'BDO-9')->assertCreated();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('BDO-9', SavingsTransaction::query()->where('type', 'withdrawal')->sole()->external_reference);
    }

    /**
     * Two waiting withdrawals can already share a reference (from before this
     * check, or two requests at the same instant). The older one posts; the
     * newer one is refused, whichever the checker approves first.
     */
    public function test_of_two_waiting_withdrawals_sharing_a_reference_the_older_posts_and_the_newer_is_refused(): void
    {
        $this->actingAs($this->teller());
        $older = $this->withdrawal($this->mine, 'bank_transfer', 'BDO-9', '10000.01')->assertStatus(202)->json('data.approval.id');
        $newer = $this->withdrawal($this->mine, 'bank_transfer', 'BDO-TEMP', '10000.02')->assertStatus(202)->json('data.approval.id');
        $approval = SavingsApproval::query()->findOrFail($newer);
        $approval->forceFill(['payload' => ['reference' => 'BDO-9'] + $approval->payload])->save();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$newer}/approve")->assertUnprocessable()->assertJsonPath('errors.reference.0', self::BDO_9_HELD);
        $this->postJson("/api/savings/approvals/{$older}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/savings/approvals/{$newer}/approve")->assertUnprocessable()->assertJsonPath('errors.reference.0', self::BDO_9);

        $this->assertSame(['10000.01'], SavingsTransaction::query()->where('type', 'withdrawal')->pluck('debit')->all());
    }

    public function test_a_rejected_withdrawal_frees_its_reference(): void
    {
        $this->actingAs($this->teller());
        $approval = $this->withdrawal($this->mine, 'bank_transfer', 'BDO-9', '10000.01')->assertStatus(202)->json('data.approval');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/reject", ['reason' => 'Wrong account'])->assertOk();

        $this->actingAs($this->admin);
        $this->deposit($this->theirs, 'bank_transfer', 'BDO-9')->assertCreated();
    }

    public function test_an_approved_withdrawal_is_checked_again_when_it_posts(): void
    {
        $this->actingAs($this->teller());
        $approval = $this->withdrawal($this->mine, 'bank_transfer', 'BDO-9', '10000.01')->assertStatus(202)->json('data.approval');

        // Taken while the withdrawal waited, by a writer that never checks (an auto-credit run does this).
        DB::transaction(fn () => app(SavingsLedger::class)->credit($this->theirs, 'deposit', 25_000, ['date' => '2026-11-10', 'method' => 'bank_transfer', 'external_reference' => 'BDO-9']));

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', self::BDO_9);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_the_same_reference_by_another_method_is_accepted(): void
    {
        $this->deposit($this->theirs, 'gcash', 'REF-1')->assertCreated();

        $this->deposit($this->mine, 'maya', 'REF-1')->assertCreated();
        $this->withdrawal($this->mine, 'bank_transfer', 'REF-1')->assertCreated();
    }

    public function test_cash_is_exempt(): void
    {
        $this->deposit($this->theirs, 'cash', 'OR-77')->assertCreated();
        $this->deposit($this->mine, 'cash', 'OR-77')->assertCreated();
        $this->withdrawal($this->mine, 'cash', 'OR-77')->assertCreated();
    }

    public function test_an_auto_credit_run_is_exempt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Asia/Manila'));
        $this->deposit($this->theirs, 'gcash', 'GC-RUN', '2026-11-15')->assertCreated();
        SavingsAutoCreditInstruction::query()->create([
            'account_id' => $this->mine->id, 'amount' => '500.00', 'schedule' => '15', 'active' => true,
            'authorised_on' => '2026-11-01', 'authorised_by' => 'The member', 'created_by' => $this->admin->id,
        ]);

        $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15', 'method' => 'gcash', 'reference_number' => 'GC-RUN'])->assertCreated();

        $this->assertSame(2, SavingsTransaction::query()->where('method', 'gcash')->where('external_reference', 'GC-RUN')->count());
    }

    public function test_a_reversed_rows_reference_is_free_again(): void
    {
        DB::transaction(function (): void {
            $ledger = app(SavingsLedger::class);
            $original = $ledger->credit($this->theirs, 'deposit', 25_000, ['date' => '2026-11-10', 'method' => 'gcash', 'external_reference' => 'GC-778899']);
            $ledger->debit($this->theirs, 'reversal', 25_000, ['date' => '2026-11-10', 'method' => 'gcash', 'reverses_transaction_id' => $original->id]);
        });

        $this->deposit($this->mine, 'gcash', 'GC-778899')->assertCreated();
    }

    private function deposit(SavingsAccount $account, string $method, string $reference, string $date = '2026-11-10')
    {
        return $this->money("/api/savings/accounts/{$account->id}/deposits", ['amount' => '250.00', 'date' => $date, 'payment_method' => $method, 'reference' => $reference]);
    }

    private function withdrawal(SavingsAccount $account, string $method, string $reference, string $amount = '100.00')
    {
        return $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => $amount, 'date' => '2026-11-10', 'payment_method' => $method, 'reference' => $reference]);
    }
}
