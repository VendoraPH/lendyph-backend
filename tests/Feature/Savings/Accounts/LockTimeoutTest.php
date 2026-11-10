<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Review L4: while another connection holds a savings account's row lock,
 * placing or releasing a hold, changing the account's status, and rejecting
 * or cancelling a request on it give up after the lock wait timeout with a
 * 409 in words, never a 500.
 *
 * The rows are committed (no wrapping transaction), so the second connection
 * can lock them; this connection waits one second at most.
 */
class LockTimeoutTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected bool $wrapsEachTestInTransaction = false;

    public function test_writes_that_wait_out_the_account_lock_answer_409(): void
    {
        $this->setUpSavings(pinToday: false);
        $account = $this->account(['opened_on' => now()->toDateString(), 'last_member_activity_on' => now()->toDateString()]);
        $this->fund($account, 100_000, now()->toDateString());
        $hold = SavingsHold::factory()->create(['account_id' => $account->id, 'amount' => '1.00']);
        $toReject = SavingsApproval::factory()->create(['account_id' => $account->id, 'amount' => '1.00', 'requested_by' => $this->userWith(['savings:transact'])->id]);
        $toCancel = SavingsApproval::factory()->create(['account_id' => $account->id, 'amount' => '1.00', 'requested_by' => $this->admin->id]);

        config(['database.connections.savings_lock_holder' => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge('savings_lock_holder');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $holder = DB::connection('savings_lock_holder');
        $holder->beginTransaction();

        try {
            $holder->table('savings_accounts')->where('id', $account->id)->lockForUpdate()->first();

            $calls = [
                fn () => $this->postJson("/api/savings/accounts/{$account->id}/holds", ['type' => 'manual', 'amount' => '1', 'reason' => 'x'], ['Idempotency-Key' => (string) Str::uuid()]),
                fn () => $this->postJson("/api/savings/holds/{$hold->id}/release", ['reason' => 'x']),
                fn () => $this->postJson("/api/savings/accounts/{$account->id}/status", ['status' => 'frozen', 'reason' => 'x']),
                fn () => $this->postJson("/api/savings/approvals/{$toReject->id}/reject", ['reason' => 'x']),
                fn () => $this->postJson("/api/savings/approvals/{$toCancel->id}/cancel"),
            ];

            foreach ($calls as $call) {
                $call()->assertStatus(409)->assertJsonPath('message', fn ($m) => is_string($m) && $m !== '');
            }
        } finally {
            $holder->rollBack();
            DB::purge('savings_lock_holder');
        }

        $this->assertSame(['active', 'pending', 'pending', 'active'], [
            $hold->refresh()->status, $toReject->refresh()->status, $toCancel->refresh()->status, $account->refresh()->status,
        ]);
        $this->assertSame(1, SavingsHold::query()->count());
    }
}
