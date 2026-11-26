<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6 (money review): one rule of a blank reference, in PHP and
 * in SQL alike: null, or nothing but the characters PHP's trim() strips
 * (MySQL's TRIM() strips only spaces). A payout whose stored reference holds
 * only a tab or a newline is offered "Record reference", is listed as missing
 * one, and records it (one row written), on a transaction and a time deposit.
 */
class BlankReferenceRuleTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private const WHITESPACE = [" \t ", "\n", "\r\n", "\x0B", "\0"];

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

    public function test_php_and_sql_read_blank_the_same_way(): void
    {
        foreach ([...self::WHITESPACE, '', null, 'GC-1', " GC-1\t"] as $value) {
            $sql = (bool) DB::selectOne('SELECT '.SavingsTransaction::blankReferenceSql('t.v').' AS blank FROM (SELECT CAST(? AS CHAR(100)) AS v) AS t', [$value])->blank;
            $this->assertSame(SavingsTransaction::isBlankReference($value), $sql, json_encode($value));
        }

        $this->assertTrue(SavingsTransaction::isBlankReference("\t"));
        $this->assertFalse(SavingsTransaction::isBlankReference(" GC-1\t"));
    }

    public function test_a_withdrawal_stored_with_only_whitespace_takes_its_reference(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000, '2026-11-01');
        $row = DB::transaction(fn () => app(SavingsLedger::class)->debit($account, 'withdrawal', 10_000, ['method' => 'gcash', 'external_reference' => "\t"]));

        $this->getJson("/api/savings/transactions/{$row->id}")->assertJsonPath('data.can_record_reference', true);
        $this->getJson('/api/savings/payouts/missing-reference')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $row->id);

        $this->postJson("/api/savings/transactions/{$row->id}/reference", ['reference' => 'GC-TAB-1'])
            ->assertOk()
            ->assertJsonPath('data.external_reference', 'GC-TAB-1');
        $this->getJson('/api/savings/payouts/missing-reference')->assertJsonPath('meta.total', 0);
    }

    public function test_a_time_deposit_stored_with_only_whitespace_takes_its_payout_reference(): void
    {
        $deposit = TimeDeposit::factory()->create([
            'borrower_id' => $this->member->id,
            'product_id' => SavingsProduct::factory()->timeDeposit()->create()->id,
            'branch_id' => $this->member->branch_id,
            'status' => 'withdrawn',
            'settled_on' => '2026-11-10',
            'settled_amount' => '1000.00',
            'settled_interest' => '0.00',
            'settled_tax' => '0.00',
            'payout_method' => 'maya',
            'payout_reference' => "\n",
        ]);

        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertJsonPath('data.can_record_reference', true);
        $this->getJson('/api/savings/payouts/missing-reference')->assertJsonPath('meta.total', 1);

        $this->postJson("/api/savings/time-deposits/{$deposit->id}/payout-reference", ['reference' => 'MAYA-NL-1'])
            ->assertOk()
            ->assertJsonPath('data.payout_reference', 'MAYA-NL-1');
        $this->getJson('/api/savings/payouts/missing-reference')->assertJsonPath('meta.total', 0);
    }

    public function test_a_reference_that_is_blank_by_the_rule_is_refused(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000, '2026-11-01');
        $row = DB::transaction(fn () => app(SavingsLedger::class)->debit($account, 'withdrawal', 10_000, ['method' => 'gcash']));

        $this->postJson("/api/savings/transactions/{$row->id}/reference", ['reference' => "\0"])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Enter the reference of this payout.');
        $this->assertNull($row->refresh()->external_reference);
    }
}
