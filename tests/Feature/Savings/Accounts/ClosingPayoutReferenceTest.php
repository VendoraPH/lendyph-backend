<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 5, closing payouts: a balance paid out at closing is money
 * going out, like a withdrawal, so its `payout_reference` is optional by any
 * method (it exists only once the money is sent, after a checker approves
 * above tier 1). One given is kept on the closing row as a deposit keeps its
 * reference, and round 2's duplicate-reference rule covers it as it covers
 * withdrawals: checked when asked for, held while a close waits for its
 * checker, and checked again when the close posts.
 */
class ClosingPayoutReferenceTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    private ?SavingsAccount $otherAccount = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Asia/Manila'));
        $this->savings = $this->account();
        $this->fund($this->savings, 500_000, '2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_non_cash_closing_payout_needs_no_reference(): void
    {
        $this->close(['payout_method' => 'bank_transfer', 'payout_reference' => '  '])->assertCreated()->assertJsonPath('data.status', 'closed');

        $row = SavingsTransaction::query()->where('type', 'closing')->sole();
        $this->assertSame(['bank_transfer', null], [$row->method, $row->external_reference]);
    }

    public function test_a_close_above_tier_one_waits_for_its_checker_without_a_reference(): void
    {
        $this->fund($this->savings, 1_000_000, '2026-11-11');
        $this->actingAs($this->teller());

        $approval = $this->close(['payout_method' => 'gcash'])->assertStatus(202)->json('data.approval');

        $this->assertNull(SavingsApproval::query()->findOrFail($approval['id'])->payload['payout_reference']);
        $this->assertSame('active', $this->savings->refresh()->status);
    }

    public function test_with_its_reference_the_closing_row_keeps_it(): void
    {
        $this->close(['payout_method' => 'gcash', 'payout_reference' => 'GC-CLOSE-1'])->assertCreated();

        $row = SavingsTransaction::query()->where('type', 'closing')->sole();
        $this->assertSame(['gcash', 'GC-CLOSE-1'], [$row->method, $row->external_reference]);
    }

    public function test_cash_needs_no_reference(): void
    {
        $this->close()->assertCreated();

        $this->assertNull(SavingsTransaction::query()->where('type', 'closing')->sole()->external_reference);
    }

    public function test_a_close_waiting_for_its_checker_holds_its_reference_then_posts_with_it(): void
    {
        $this->fund($this->savings, 1_000_000, '2026-11-11');
        $this->actingAs($this->teller());
        $approval = $this->close(['payout_method' => 'bank_transfer', 'payout_reference' => 'BDO-77'])->assertStatus(202)->json('data.approval');
        $this->assertSame('BDO-77', SavingsApproval::query()->findOrFail($approval['id'])->payload['payout_reference']);

        $this->money("/api/savings/accounts/{$this->other()->id}/deposits", ['amount' => '100.00', 'date' => '2026-11-11', 'payment_method' => 'bank_transfer', 'reference' => 'BDO-77'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "BDO-77" is held by a bank transfer payout waiting for approval. Check the reference, or decide that payout first.');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('BDO-77', SavingsTransaction::query()->where('type', 'closing')->sole()->external_reference);
    }

    public function test_a_rejected_close_frees_the_reference_it_held(): void
    {
        $this->fund($this->savings, 1_000_000, '2026-11-11');
        $this->actingAs($this->teller());
        $approval = $this->close(['payout_method' => 'bank_transfer', 'payout_reference' => 'BDO-88'])->assertStatus(202)->json('data.approval');
        $deposit = ['amount' => '100.00', 'date' => '2026-11-11', 'payment_method' => 'bank_transfer', 'reference' => 'BDO-88'];

        $this->money("/api/savings/accounts/{$this->other()->id}/deposits", $deposit)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "BDO-88" is held by a bank transfer payout waiting for approval. Check the reference, or decide that payout first.');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/reject", ['reason' => 'Wrong account'])->assertOk();

        $this->actingAs($this->admin);
        $this->money("/api/savings/accounts/{$this->other()->id}/deposits", $deposit)->assertCreated()->assertJsonPath('data.external_reference', 'BDO-88');
        $this->assertSame('active', $this->savings->refresh()->status);
    }

    public function test_a_reference_on_record_is_refused_both_ways(): void
    {
        $this->money("/api/savings/accounts/{$this->other()->id}/deposits", ['amount' => '100.00', 'date' => '2026-11-11', 'payment_method' => 'gcash', 'reference' => 'GC-1'])->assertCreated();

        $this->close(['payout_method' => 'gcash', 'payout_reference' => 'GC-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_reference.0', 'Reference "GC-1" is already on record for another GCash transaction. Check the reference and enter the right one.');

        $this->close(['payout_method' => 'gcash', 'payout_reference' => 'GC-2'])->assertCreated();
        $this->money("/api/savings/accounts/{$this->other()->id}/deposits", ['amount' => '100.00', 'date' => '2026-11-11', 'payment_method' => 'gcash', 'reference' => 'GC-2'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "GC-2" is already on record for another GCash transaction. Check the reference and enter the right one.');
    }

    public function test_an_approved_close_is_checked_again_when_it_posts(): void
    {
        $this->fund($this->savings, 1_000_000, '2026-11-11');
        $this->actingAs($this->teller());
        $approval = $this->close(['payout_method' => 'maya', 'payout_reference' => 'MY-9'])->assertStatus(202)->json('data.approval');

        // Taken while the close waited, by a writer that never checks (an auto-credit run does this).
        $other = $this->other();
        DB::transaction(fn () => app(SavingsLedger::class)->credit($other, 'deposit', 10_000, ['date' => '2026-11-11', 'method' => 'maya', 'external_reference' => 'MY-9']));

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_reference.0', 'Reference "MY-9" is already on record for another Maya transaction. Check the reference and enter the right one.');
        $this->assertSame('active', $this->savings->refresh()->status);
    }

    private function close(array $overrides = []): TestResponse
    {
        return $this->money("/api/savings/accounts/{$this->savings->id}/close", [
            'date' => '2026-11-11', 'reason' => 'Member moving abroad', 'payout_method' => 'cash', ...$overrides,
        ]);
    }

    /** Another member's account, made once. */
    private function other(): SavingsAccount
    {
        return $this->otherAccount ??= $this->account([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id,
            'product_id' => SavingsProduct::factory()->create()->id,
        ]);
    }
}
