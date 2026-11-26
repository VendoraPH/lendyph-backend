<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Borrower;
use App\Models\SavingsTransaction;
use App\Services\GeneratedNumberWrite;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6 (money review): the reuse check takes its method's row
 * in `savings_reference_locks` before it reads, so two writers on different
 * accounts can't both save one reference.
 *
 * On two real connections: the other one plays a teller midway through a
 * GCash deposit with "GC-777" on member 2's account (it holds the gcash lock
 * row and has inserted its row, uncommitted). Recording "GC-777" on member 1's
 * withdrawal, or posting another GCash deposit, waits for it instead of
 * reading past it (409 after the one-second wait, nothing saved); once the
 * deposit commits, the same reference is refused in words. Another method
 * isn't held up. The rows are committed (no wrapping transaction), so the
 * other connection can see and lock them.
 */
class ReferenceMethodLockTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'savings_reference_lock_holder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings(pinToday: false);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER_CONNECTION)->rollBack(0);
        DB::purge(self::OTHER_CONNECTION);
        parent::tearDown();
    }

    public function test_a_writer_waits_for_another_checking_the_same_method_then_sees_its_reference(): void
    {
        $today = now()->toDateString();
        $first = $this->account(['opened_on' => $today, 'last_member_activity_on' => $today]);
        $this->fund($first, 500_000, $today);
        $withdrawal = SavingsTransaction::query()->findOrFail($this->money("/api/savings/accounts/{$first->id}/withdrawals", [
            'amount' => '500.00', 'date' => $today, 'payment_method' => 'gcash',
        ])->assertCreated()->json('data.id'));

        $second = $this->account([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id,
            'opened_on' => $today, 'last_member_activity_on' => $today,
        ]);

        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('savings_reference_locks')->where('method', 'gcash')->lockForUpdate()->first();
        $other->table('savings_transactions')->insert([
            'account_id' => $second->id, 'reference' => 'SVT-00000000-RACE01', 'type' => 'deposit', 'date' => $today,
            'value_date' => $today, 'debit' => '0.00', 'credit' => '700.00', 'balance_after' => '700.00',
            'method' => 'gcash', 'external_reference' => 'GC-777', 'created_at' => now(),
        ]);

        // Both wait for the deposit's writer instead of reading past it.
        $this->postJson("/api/savings/transactions/{$withdrawal->id}/reference", ['reference' => 'GC-777'])
            ->assertStatus(409)
            ->assertJsonPath('message', GeneratedNumberWrite::SAVINGS_CONFLICT);
        $this->money("/api/savings/accounts/{$first->id}/deposits", [
            'amount' => '100.00', 'date' => $today, 'payment_method' => 'gcash', 'reference' => 'GC-778',
        ])->assertStatus(409);
        $this->assertNull($withdrawal->refresh()->external_reference);
        $this->assertSame(0, SavingsTransaction::query()->where('external_reference', 'GC-778')->count());

        // Another method isn't held up.
        $this->money("/api/savings/accounts/{$first->id}/deposits", [
            'amount' => '100.00', 'date' => $today, 'payment_method' => 'maya', 'reference' => 'MAYA-1',
        ])->assertCreated();

        $other->commit();

        $this->postJson("/api/savings/transactions/{$withdrawal->id}/reference", ['reference' => 'GC-777'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "GC-777" is already on record for another GCash transaction. Check the reference and enter the right one.');
        $this->postJson("/api/savings/transactions/{$withdrawal->id}/reference", ['reference' => 'GC-779'])->assertOk();
    }

    public function test_every_non_cash_method_has_its_lock_row(): void
    {
        $this->assertSame(['bank_transfer', 'gcash', 'maya', 'savings'], DB::table('savings_reference_locks')->orderBy('method')->pluck('method')->all());
    }
}
