<?php

namespace Tests\Feature\Savings\Foundation;

use App\Exceptions\SavingsTransactionIsAppendOnlyException;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Savings\SavingsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * SavingsLedger, the only writer of savings_transactions (design §4, §5):
 * the date rules, balance_after and the ledger_balance cache, dormant
 * reactivation, and a transaction row that can never change.
 */
class SavingsLedgerTest extends TestCase
{
    use SetupLendyPH;

    private SavingsLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->ledger = app(SavingsLedger::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(array $attributes = []): SavingsAccount
    {
        return SavingsAccount::factory()->create(array_merge(['opened_on' => '2026-11-01'], $attributes));
    }

    private function assertRefused(callable $write, string $field, string $contains): void
    {
        try {
            DB::transaction($write);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($contains, $e->errors()[$field][0]);

            return;
        }

        $this->fail('The write was not refused.');
    }

    public function test_a_deposit_and_a_withdrawal_keep_balance_after_and_the_cache_right(): void
    {
        $account = $this->account();

        $deposit = DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 150_050, ['method' => 'cash']));
        $withdrawal = DB::transaction(fn () => $this->ledger->debit($account, 'withdrawal', 50_025, ['method' => 'cash']));

        $this->assertSame('1500.50', $deposit->credit);
        $this->assertSame('0.00', $deposit->debit);
        $this->assertSame('1500.50', $deposit->balance_after);
        $this->assertSame('1000.25', $withdrawal->balance_after);
        $this->assertSame('2026-11-10', $withdrawal->date->toDateString());
        $this->assertSame('2026-11-10', $withdrawal->value_date->toDateString());
        $this->assertSame($this->admin->id, $withdrawal->created_by);

        $account->refresh();
        $this->assertSame('1000.25', $account->ledger_balance);
        $this->assertSame('2026-11-10', $account->last_member_activity_on->toDateString());
    }

    public function test_references_are_numbered_per_day(): void
    {
        $account = $this->account();

        $first = DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']));
        $second = DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']));

        $this->assertSame('SVT-20261110-000001', $first->reference);
        $this->assertSame('SVT-20261110-000002', $second->reference);
    }

    public function test_interest_does_not_count_as_member_activity(): void
    {
        $account = $this->account(['last_member_activity_on' => '2026-11-02']);

        DB::transaction(fn () => $this->ledger->credit($account, 'interest', 500, ['value_date' => '2026-11-09', 'created_by' => null]));

        $account->refresh();
        $this->assertSame('2026-11-02', $account->last_member_activity_on->toDateString());
        $this->assertSame('5.00', $account->ledger_balance);
    }

    public function test_a_date_after_today_is_refused(): void
    {
        $account = $this->account();

        $this->assertRefused(fn () => $this->ledger->credit($account, 'deposit', 100, ['date' => '2026-11-11', 'method' => 'cash']), 'date', 'after today');
        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    public function test_a_date_earlier_than_the_accounts_latest_is_refused(): void
    {
        $account = $this->account();
        DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']));

        $this->assertRefused(fn () => $this->ledger->credit($account, 'deposit', 100, ['date' => '2026-11-09', 'method' => 'cash']), 'date', 'Nov 10, 2026');
    }

    public function test_back_dating_is_allowed_only_until_the_next_morning(): void
    {
        $account = $this->account();

        $yesterday = DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 100, ['date' => '2026-11-09', 'method' => 'cash']));
        $this->assertSame('2026-11-09', $yesterday->date->toDateString());

        $other = $this->account();
        $this->assertRefused(fn () => $this->ledger->credit($other, 'deposit', 100, ['date' => '2026-11-08', 'method' => 'cash']), 'date', 'yesterday');
    }

    public function test_a_value_date_inside_a_credited_period_is_refused(): void
    {
        $account = $this->account(['interest_credited_through' => '2026-11-09']);

        $this->assertRefused(fn () => $this->ledger->credit($account, 'deposit', 100, ['date' => '2026-11-09', 'method' => 'cash']), 'value_date', 'already been credited');
        $this->assertRefused(fn () => $this->ledger->debit($account, 'reversal', 100, ['value_date' => '2026-11-09']), 'reverses_transaction_id', 'name the row it reverses');
        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    public function test_a_value_date_after_the_date_is_refused(): void
    {
        $account = $this->account();

        $this->assertRefused(fn () => $this->ledger->credit($account, 'deposit', 100, ['date' => '2026-11-09', 'value_date' => '2026-11-10', 'method' => 'cash']), 'value_date', 'after');
    }

    public function test_a_withdrawal_above_the_ledger_balance_is_refused(): void
    {
        $account = $this->account();
        DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 10_000, ['method' => 'cash']));

        $this->assertRefused(fn () => $this->ledger->debit($account, 'withdrawal', 10_001, ['method' => 'cash']), 'amount', '₱100.00');
        $this->assertSame('100.00', $account->refresh()->ledger_balance);
    }

    public function test_a_closed_account_takes_nothing(): void
    {
        $account = $this->account(['status' => 'closed', 'closed_on' => '2026-11-05', 'open_key' => null]);

        $this->assertRefused(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']), 'account', 'closed');
    }

    public function test_a_zero_amount_and_the_wrong_side_for_a_type_are_refused(): void
    {
        $account = $this->account();

        $this->assertRefused(fn () => $this->ledger->credit($account, 'deposit', 0, ['method' => 'cash']), 'amount', 'greater than ₱0.00');
        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->ledger->credit($account, 'withdrawal', 100, ['method' => 'cash']));
    }

    public function test_a_closed_accounting_period_is_refused_and_writes_nothing(): void
    {
        $account = $this->account();
        AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse('2026-11-01')) + [
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $this->admin->id,
        ]);

        $this->assertRefused(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']), 'date', 'closed');
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame('0.00', $account->refresh()->ledger_balance);
    }

    public function test_a_deposit_reactivates_a_dormant_account_with_an_audit_row(): void
    {
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-05']);
        $before = AuditLog::query()->max('id') ?? 0;

        $deposit = DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']));

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertNull($account->dormant_since);

        $row = AuditLog::query()->where('id', '>', $before)->where('action', 'savings_account_reactivated')->sole();
        $this->assertSame(SavingsAccount::class, $row->auditable_type);
        $this->assertSame(['status' => 'dormant', 'dormant_since' => '2026-11-05'], $row->old_values);
        $this->assertSame('active', $row->new_values['status']);
        $this->assertStringContainsString($deposit->reference, (string) $row->description);
    }

    public function test_interest_does_not_reactivate_a_dormant_account(): void
    {
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-05']);

        DB::transaction(fn () => $this->ledger->credit($account, 'interest', 100, ['value_date' => '2026-11-09', 'created_by' => null]));

        $this->assertSame('dormant', $account->refresh()->status);
    }

    public function test_the_model_can_never_update_or_delete_a_transaction(): void
    {
        $account = $this->account();
        $row = DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']));

        $attempts = [
            fn () => $row->update(['remarks' => 'changed']),
            fn () => $row->delete(),
            fn () => SavingsTransaction::query()->whereKey($row->id)->update(['credit' => 5]),
            fn () => SavingsTransaction::query()->whereKey($row->id)->delete(),
            fn () => $row->increment('credit'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('A savings transaction was changed.');
            } catch (SavingsTransactionIsAppendOnlyException) {
                // Refused, as it must be.
            }
        }

        $this->assertSame('1.00', SavingsTransaction::query()->findOrFail($row->id)->credit);
    }
}
