<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\SavingsAccount;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use LogicException;
use Tests\TestCase;

/**
 * SavingsLedger and SavingsLocks refuse to run outside a transaction, where a
 * row lock would be released at once and a ledger row could be written
 * without its journal.
 */
class SavingsWritesNeedATransactionTest extends TestCase
{
    /** Opted out of the per-test transaction, which would hide the guard. */
    protected bool $wrapsEachTestInTransaction = false;

    public function test_the_ledger_and_the_locks_refuse_outside_a_transaction(): void
    {
        $account = SavingsAccount::factory()->create();

        foreach ([
            fn () => app(SavingsLedger::class)->credit($account, 'deposit', 100, ['method' => 'cash']),
            fn () => app(SavingsLocks::class)->accounts([$account->id]),
            fn () => app(SavingsLocks::class)->timeDeposits([1]),
        ] as $write) {
            try {
                $write();
                $this->fail('A savings write ran outside a transaction.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('transaction', $e->getMessage());
            }
        }

        $this->assertSame(0, $account->transactions()->count());
    }
}
