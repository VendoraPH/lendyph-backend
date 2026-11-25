<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AuditLog;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Closing an account (design §2): the server's quote, refusals in words,
 * the accrued interest credited (and its tax withheld) before the balance is
 * paid out, and a payout above tier 1 waiting for a checker.
 *
 * ₱5,000.00 held Nov 1–10 at 3.65% earns exactly ₱5.00.
 */
class ClosingTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

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

    private function close(array $overrides = []): TestResponse
    {
        return $this->money("/api/savings/accounts/{$this->savings->id}/close", [
            'date' => '2026-11-11', 'reason' => 'Member moving abroad', 'payout_method' => 'cash', ...$overrides,
        ]);
    }

    public function test_the_closing_quote_is_the_servers(): void
    {
        $this->getJson("/api/savings/accounts/{$this->savings->id}/closing-quote")->assertOk()
            ->assertExactJson(['success' => true, 'data' => [
                'balance' => '5000.00',
                'accrued_interest' => '5.00',
                'withholding_tax' => '0.00',
                'payout_amount' => '5005.00',
                'can_close' => true,
                'blockers' => [],
            ]]);
    }

    public function test_close_credits_the_interest_withholds_its_tax_then_pays_out(): void
    {
        DB::table('savings_tax_rules')->insert(['holder_type' => 'member', 'rate' => '20.0000', 'effective_from' => '2026-11-02', 'created_at' => now()]);

        $this->getJson("/api/savings/accounts/{$this->savings->id}/closing-quote")
            ->assertJsonPath('data.withholding_tax', '1.00')->assertJsonPath('data.payout_amount', '5004.00');

        $this->close(['payout_method' => 'gcash'])->assertCreated()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.closed_on', '2026-11-11')
            ->assertJsonPath('data.close_reason', 'Member moving abroad')
            ->assertJsonPath('data.interest_credited_through', '2026-11-10');

        $rows = SavingsTransaction::query()->where('account_id', $this->savings->id)->orderBy('id')->get();
        $this->assertSame(
            [['deposit', '0.00', '5000.00', '5000.00'], ['interest', '0.00', '5.00', '5005.00'], ['withholding_tax', '1.00', '0.00', '5004.00'], ['closing', '5004.00', '0.00', '0.00']],
            $rows->map(fn ($r) => [$r->type, $r->debit, $r->credit, $r->balance_after])->all(),
        );
        $this->assertSame('2026-11-10', $rows[1]->value_date->toDateString());

        $interestJournal = $this->journalFor($rows[1], 'savings_interest');
        $lines = collect($this->journalLines($interestJournal));
        $this->assertSame([['5160', 500, 0], ['2050', 0, 400]], $lines->take(2)->all());
        $this->assertSame(100, $lines[2][2]);
        $this->assertSame([['2050', 500_400, 0], ['1020', 0, 500_400]], $this->journalLines($this->journalFor($rows[3])));

        $account = $this->savings->refresh();
        $this->assertNull($account->open_key);
        $this->assertSame($this->admin->id, $account->closed_by);
        $this->assertTrue(AuditLog::query()->where('action', 'savings_account_closed')->where('auditable_id', $account->id)->exists());
    }

    public function test_blockers_in_words_and_422(): void
    {
        SavingsHold::factory()->create(['account_id' => $this->savings->id, 'amount' => '50.00']);
        $this->savings->forceFill(['status' => 'frozen'])->save();

        $quote = $this->getJson("/api/savings/accounts/{$this->savings->id}/closing-quote")->assertOk()
            ->assertJsonPath('data.can_close', false)->json('data.blockers');
        $this->assertCount(2, $quote);
        $this->assertStringContainsString('frozen', $quote[0].$quote[1]);
        $this->assertStringContainsString('hold', $quote[0].$quote[1]);

        $this->close()->assertUnprocessable();
        $this->assertSame(1, SavingsTransaction::query()->count());
    }

    public function test_a_pending_request_blocks_closing(): void
    {
        $this->money("/api/savings/accounts/{$this->savings->id}/withdrawals", ['amount' => '100.00', 'date' => '2026-11-11', 'payment_method' => 'cash'])->assertCreated();
        $this->money('/api/savings/transactions/'.SavingsTransaction::query()->where('type', 'withdrawal')->value('id').'/reverse', ['reason' => 'Wrong account'])->assertStatus(202);

        $this->getJson("/api/savings/accounts/{$this->savings->id}/closing-quote")->assertJsonPath('data.can_close', false);
        $this->close()->assertUnprocessable();
    }

    public function test_a_payout_above_tier_one_waits_for_a_checker_then_closes(): void
    {
        $this->fund($this->savings, 1_000_000, '2026-11-11');
        $this->actingAs($maker = $this->teller());

        $approval = $this->close()->assertStatus(202)
            ->assertJsonPath('data.approval.kind', 'close')
            ->assertJsonPath('data.approval.amount', '15005.00')
            ->json('data.approval');
        $this->assertSame('active', $this->savings->refresh()->status);
        $this->getJson("/api/savings/accounts/{$this->savings->id}/closing-quote")->assertJsonPath('data.can_close', false);

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $account = $this->savings->refresh();
        $this->assertSame(['closed', '0.00', $maker->id], [$account->status, $account->ledger_balance, $account->closed_by]);
    }

    public function test_an_already_closed_account_is_422(): void
    {
        $this->close()->assertCreated();
        $this->close()->assertUnprocessable();
        $this->money("/api/savings/accounts/{$this->savings->id}/deposits", ['amount' => '1', 'date' => '2026-11-11', 'payment_method' => 'cash'])->assertUnprocessable();
    }

    public function test_a_savings_payout_method_is_refused(): void
    {
        $this->close(['payout_method' => 'savings'])->assertUnprocessable()->assertJsonValidationErrors('payout_method');
    }
}
