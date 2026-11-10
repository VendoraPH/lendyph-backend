<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Paying a time deposit into any of the member's savings accounts: settle and
 * preterminate take an optional `payout_account_id` with `payout_method`
 * savings. It must be the same member's active account in the placement's
 * branch (422 in words otherwise); left out, the account named at placement
 * is used, as before. A payout into savings needs no checker, and the
 * journal is still Dr 2060 / Cr 2050.
 */
class TimeDepositPayoutAccountTest extends TestCase
{
    use TimeDepositFixtures;

    /** ₱100,000.00 × 3% × 90 ÷ 365 = ₱739.73 gross, no tax for a member. */
    private const MATURITY_LINES = [['2060', 10_000_000, 0], ['5160', 73_973, 0], ['2050', 0, 10_073_973]];

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
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => now()->toDateString(), 'action' => 'payout', 'payout_method' => 'savings', ...$body]);
    }

    /** Another regular account of the member, on a product of its own (one open account per product). */
    private function otherAccount(array $attributes = [], ?Borrower $member = null): SavingsAccount
    {
        $member ??= $this->member;

        return SavingsAccount::factory()->create([
            'borrower_id' => $member->id,
            'product_id' => SavingsProduct::factory()->create()->id,
            'branch_id' => $member->branch_id,
            ...$attributes,
        ]);
    }

    public function test_a_settlement_into_another_of_the_members_accounts_credits_it_and_still_posts_2060_to_2050(): void
    {
        $named = $this->savingsAccount();
        $other = $this->otherAccount();
        $deposit = $this->placed(['payout_account_id' => $named->id]);
        $this->toMaturity();
        $auditBefore = AuditLog::query()->max('id');

        $this->settle($deposit, ['payout_account_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.payout_account_id', $other->id)
            ->assertJsonPath('data.payout_account_number', $other->account_number);

        $credit = SavingsTransaction::query()->where('account_id', $other->id)->sole();
        $this->assertSame('deposit', $credit->type);
        $this->assertSame('savings', $credit->method);
        $this->assertSame($deposit->id, $credit->time_deposit_id);
        $this->assertSame('100739.73', $credit->credit);
        $this->assertSame('100739.73', $other->refresh()->ledger_balance);
        $this->assertSame(0, $this->savingsRows($named));

        $this->assertSame(self::MATURITY_LINES, $this->lines($this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement')));

        $audit = AuditLog::query()->where('id', '>', $auditBefore)->where('action', 'time_deposit_settled')->sole();
        $this->assertSame($named->account_number, $audit->old_values['payout_account']);
        $this->assertSame($other->account_number, $audit->new_values['payout_account']);
        $this->assertStringContainsString("into savings account {$other->account_number}", (string) $audit->description);
    }

    public function test_a_placement_that_named_no_account_can_be_paid_into_one_at_settlement(): void
    {
        $deposit = $this->placed();
        $account = $this->savingsAccount();
        $this->toMaturity();

        $this->settle($deposit, ['payout_account_id' => $account->id])->assertCreated();

        $this->assertSame('100739.73', $account->refresh()->ledger_balance);
        $this->assertSame(self::MATURITY_LINES, $this->lines($this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement')));
    }

    public function test_a_rollover_pays_the_interest_into_the_chosen_account_and_the_new_placement_keeps_the_named_one(): void
    {
        $named = $this->savingsAccount();
        $other = $this->otherAccount();
        $deposit = $this->placed(['payout_account_id' => $named->id]);
        $this->toMaturity();

        $new = $this->settle($deposit, ['action' => 'rollover_principal', 'payout_account_id' => $other->id])->assertCreated()->json('data');

        $this->assertSame('739.73', $other->refresh()->ledger_balance);
        $this->assertSame($named->id, $new['payout_account_id']);
        $this->assertSame($other->id, $deposit->refresh()->payout_account_id);
    }

    public function test_another_members_account_is_refused_in_words(): void
    {
        $stranger = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $theirs = $this->otherAccount(member: $stranger);
        $deposit = $this->placed();
        $this->toMaturity();

        $this->settle($deposit, ['payout_account_id' => $theirs->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_account_id.0', "That savings account isn't one of {$this->member->full_name}'s.");

        $this->assertNothingSettled($deposit, $theirs);
    }

    /** @return array<string, array{0: string}> */
    public static function statusesThatCantReceive(): array
    {
        return [
            'frozen' => [SavingsAccount::STATUS_FROZEN],
            'dormant' => [SavingsAccount::STATUS_DORMANT],
            'closed' => [SavingsAccount::STATUS_CLOSED],
        ];
    }

    #[DataProvider('statusesThatCantReceive')]
    public function test_an_account_that_isnt_active_is_refused_in_words(string $status): void
    {
        $account = $this->otherAccount(['status' => $status, ...($status === SavingsAccount::STATUS_CLOSED ? ['open_key' => null, 'closed_on' => now()->toDateString()] : [])]);
        $deposit = $this->placed();
        $this->toMaturity();

        $this->settle($deposit, ['payout_account_id' => $account->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_account_id.0', "{$account->account_number} is {$status}. Only an active savings account can receive the payout.");

        $this->assertNothingSettled($deposit, $account);
    }

    public function test_an_account_in_another_branch_is_refused_in_words(): void
    {
        $elsewhere = $this->otherAccount(['branch_id' => Branch::factory()->create()->id]);
        $deposit = $this->placed();
        $this->toMaturity();

        $this->settle($deposit, ['payout_account_id' => $elsewhere->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_account_id.0', 'That savings account is in another branch. A time deposit can only move money within the member\'s own branch.');

        $this->assertNothingSettled($deposit, $elsewhere);
    }

    public function test_an_unknown_account_is_refused_in_words(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();

        $this->settle($deposit, ['payout_account_id' => 999_999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_account_id.0', "That savings account isn't one of {$this->member->full_name}'s.");
    }

    public function test_the_account_is_ignored_when_the_payout_leaves_another_way(): void
    {
        $stranger = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $theirs = $this->otherAccount(member: $stranger);
        $deposit = $this->placed(['principal' => '9000.00']);
        $this->toMaturity();

        $this->settle($deposit, ['payout_method' => 'cash', 'payout_account_id' => $theirs->id])
            ->assertCreated()
            ->assertJsonPath('data.payout_method', 'cash')
            ->assertJsonPath('data.payout_account_id', null);

        $this->assertSame(0, $this->savingsRows($theirs));
    }

    public function test_a_pretermination_into_another_of_the_members_accounts_credits_it(): void
    {
        $named = $this->savingsAccount();
        $other = $this->otherAccount();
        $deposit = $this->placed(['payout_account_id' => $named->id]);
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));

        $data = $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", [
            'date' => now()->toDateString(),
            'reason' => 'Member needs the money for tuition.',
            'payout_method' => 'savings',
            'payout_account_id' => $other->id,
        ])->assertCreated()->assertJsonPath('data.payout_account_id', $other->id)->json('data');

        $this->assertSame('preterminated', $data['status']);
        $this->assertSame($data['settled_amount'], $other->refresh()->ledger_balance);
        $this->assertSame(0, $this->savingsRows($named));

        $lines = $this->lines($this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement'));
        $this->assertSame(['2060', 10_000_000, 0], $lines[0]);
        $this->assertSame('2050', $lines[array_key_last($lines)][0]);
        $this->assertSame(array_sum(array_column($lines, 1)), array_sum(array_column($lines, 2)));
    }

    public function test_a_pretermination_into_a_frozen_account_is_refused(): void
    {
        $frozen = $this->otherAccount(['status' => SavingsAccount::STATUS_FROZEN]);
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));

        $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", [
            'date' => now()->toDateString(),
            'reason' => 'Member needs the money for tuition.',
            'payout_method' => 'savings',
            'payout_account_id' => $frozen->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('payout_account_id');

        $this->assertSame('active', $deposit->refresh()->status);
    }

    private function assertNothingSettled(TimeDeposit $deposit, SavingsAccount $account): void
    {
        $this->assertContains($deposit->refresh()->status, ['active', 'matured']);
        $this->assertNull($deposit->settled_on);
        $this->assertSame(0, $this->savingsRows($account));
        $this->assertNull($this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement'));
    }
}
