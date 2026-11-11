<?php

namespace Tests\Feature\Portal\Read;

use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Savings and time deposits in the portal (portal design §0 finding 10, §3):
 * every figure is the savings module's own, and `withdrawable` is the
 * server's, with the maintaining balance taken off once.
 */
class PortalSavingsReadsTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_withdrawable_takes_the_maintaining_balance_off_once(): void
    {
        $me = $this->portalMember();
        $account = $this->savingsFor($me, 500_000);

        $row = $this->portalJson('GET', '/api/portal/savings', [], $this->portalToken($me))->assertOk()->json('data.0');

        $this->assertSame('5000.00', $row['ledger_balance']);
        $this->assertSame('500.00', $row['maintaining_balance']);
        $this->assertSame('4500.00', $row['available_balance']);
        $this->assertSame('4500.00', $row['withdrawable'], 'Never ₱4,000.00: AvailableBalance already keeps the maintaining balance.');
        $this->assertSame($account->account_number, $row['account_number']);
    }

    public function test_every_figure_equals_the_staff_account_screen(): void
    {
        $me = $this->portalMember();
        $account = $this->savingsFor($me, 800_000);
        SavingsHold::create([
            'account_id' => $account->id, 'type' => 'legal', 'amount' => '1000.00', 'reason' => 'Court order',
            'placed_on' => '2026-11-10', 'status' => 'active', 'created_by' => $this->admin->id,
        ]);

        $staff = $this->staffGet("/api/savings/accounts/{$account->id}")->json('data');
        $mine = $this->portalJson('GET', "/api/portal/savings/{$account->id}", [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertSame($staff['balance'], $mine['ledger_balance']);
        $this->assertSame($staff['hold_amount'], $mine['held']);
        $this->assertSame($staff['pending_amount'], $mine['pending_withdrawal']);
        $this->assertSame($staff['available_balance'], $mine['available_balance']);
        $this->assertSame($staff['available_balance'], $mine['withdrawable']);
        $this->assertSame($staff['accrued_interest'], $mine['accrued_interest']);
        $this->assertSame($staff['opened_on'], $mine['opened_on']);
        $this->assertSame('3.6500', $mine['annual_rate'], 'The rate as stored, never rounded.');
        $this->assertSame('2026-11-10', $mine['last_transaction_on']);
        $this->assertArrayNotHasKey('remarks', $mine);
    }

    public function test_a_member_request_hold_reads_as_a_pending_withdrawal(): void
    {
        $me = $this->portalMember();
        $account = $this->savingsFor($me, 800_000);
        foreach ([['legal', '1000.00'], ['member_request', '2500.00']] as [$type, $amount]) {
            SavingsHold::create([
                'account_id' => $account->id, 'type' => $type, 'amount' => $amount, 'reason' => 'Test hold',
                'placed_on' => '2026-11-10', 'expires_on' => '2026-11-17', 'status' => 'active',
            ]);
        }

        $staff = $this->staffGet("/api/savings/accounts/{$account->id}")->json('data');
        $mine = $this->portalJson('GET', "/api/portal/savings/{$account->id}", [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertSame('3500.00', $staff['hold_amount']);
        $this->assertSame('1000.00', $mine['held']);
        $this->assertSame('2500.00', $mine['pending_withdrawal']);
        $this->assertSame($staff['available_balance'], $mine['available_balance']);
        $this->assertSame('4000.00', $mine['withdrawable']);
    }

    public function test_a_dormant_or_frozen_account_is_not_withdrawable_in_the_portal(): void
    {
        $me = $this->portalMember();
        $dormant = $this->savingsFor($me, 300_000);
        $dormant->forceFill(['status' => SavingsAccount::STATUS_DORMANT])->save();

        $row = $this->portalJson('GET', "/api/portal/savings/{$dormant->id}", [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertSame('2500.00', $row['available_balance']);
        $this->assertSame('0.00', $row['withdrawable']);
    }

    public function test_the_passbook_equals_the_staff_ledger(): void
    {
        $me = $this->portalMember();
        $account = $this->savingsFor($me);
        $this->on('2026-11-01', fn () => $this->deposit($account, 100_000));
        $this->on('2026-11-05', fn () => $this->deposit($account, 50_000));
        $this->deposit($account, 25_000);

        $staff = $this->staffGet("/api/savings/accounts/{$account->id}/transactions?date_from=2026-11-03")->json();
        $mine = $this->portalJson('GET', "/api/portal/savings/{$account->id}/transactions?from=2026-11-03", [], $this->portalToken($me))->assertOk()->json();

        $this->assertSame('1000.00', $mine['meta']['opening_balance']);
        $this->assertSame($staff['meta']['opening_balance'], $mine['meta']['opening_balance']);
        $this->assertSame(array_column($staff['data'], 'reference'), array_column($mine['data'], 'reference'));
        $this->assertSame(array_column($staff['data'], 'balance_after'), array_column($mine['data'], 'balance_after'));
        $this->assertSame(['date', 'reference', 'type', 'debit', 'credit', 'balance_after'], array_keys($mine['data'][0]));
    }

    public function test_a_time_deposit_shows_the_savings_modules_figures(): void
    {
        $me = $this->portalMember();
        $this->savingsBooks();
        $deposit = $this->timeDepositFor($me, ['placement_date' => '2026-09-01', 'maturity_date' => '2026-11-30', 'term_days' => 90]);

        $staff = $this->staffGet("/api/savings/time-deposits/{$deposit->id}")->json('data');
        $list = $this->portalJson('GET', '/api/portal/time-deposits', [], $this->portalToken($me))->assertOk()->json('data');
        $mine = $this->portalJson('GET', "/api/portal/time-deposits/{$deposit->id}", [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertSame([$deposit->id], array_column($list, 'id'));

        foreach (['certificate_number', 'principal', 'annual_rate', 'term_days', 'placement_date', 'maturity_date', 'gross_interest', 'withholding_tax', 'net_interest', 'maturity_amount', 'maturity_instruction', 'status'] as $key) {
            $this->assertSame($staff[$key], $mine[$key], $key);
        }

        $this->assertSame(20, $mine['days_remaining']);
        $this->assertTrue($mine['maturing_soon']);
    }

    private function on(string $date, callable $then): void
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse("{$date} 10:00:00", 'Asia/Manila'));

        try {
            $then();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
