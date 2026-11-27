<?php

namespace Tests\Feature\Savings\Foundation;

use App\Exceptions\SavingsTransactionIsAppendOnlyException;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Savings\SavingsLedger;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Savings transactions stay append-only (finish run round 6). The one narrow
 * exception: `external_reference` may go from blank to a value, once, on a
 * withdrawal or closing payout by bank transfer, GCash or Maya that is
 * neither a reversal nor reversed. Every other change, and a second write,
 * still throws.
 */
class PayoutReferenceAppendOnlyTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account();
        $this->fund($this->savings, 500_000, '2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_blank_payout_reference_can_be_filled_in_once(): void
    {
        $row = $this->payout('withdrawal', 'gcash');

        $row->external_reference = 'GC-1';
        $this->assertTrue($row->save());
        $this->assertSame('GC-1', SavingsTransaction::query()->findOrFail($row->id)->external_reference);

        $this->assertRefused(function () use ($row): void {
            $row->external_reference = 'GC-2';
            $row->save();
        });
        $this->assertSame('GC-1', SavingsTransaction::query()->findOrFail($row->id)->external_reference);
    }

    public function test_a_closing_payout_by_bank_transfer_can_be_filled_in(): void
    {
        $row = $this->payout('closing', 'bank_transfer');

        $row->external_reference = 'BDO-1';
        $this->assertTrue($row->save());
    }

    public function test_every_other_change_still_throws(): void
    {
        $row = $this->payout('withdrawal', 'gcash');
        $id = $row->id;

        $this->assertRefused(fn () => $this->fresh($id)->forceFill(['external_reference' => 'GC-1', 'remarks' => 'and more'])->save());
        $this->assertRefused(fn () => $this->fresh($id)->forceFill(['debit' => '1.00'])->save());
        $this->assertRefused(fn () => $this->fresh($id)->forceFill(['method' => 'maya'])->save());
        $this->assertRefused(fn () => $this->fresh($id)->forceFill(['external_reference' => '   '])->save());
        $this->assertRefused(fn () => $this->fresh($id)->forceFill(['external_reference' => 'GC-1'])->delete());
        $this->assertRefused(fn () => SavingsTransaction::query()->whereKey($id)->update(['external_reference' => 'GC-1']));
        $this->assertRefused(fn () => $this->fresh($id)->increment('debit'));

        $this->assertNull($this->fresh($id)->external_reference);
    }

    public function test_rows_that_are_not_eligible_still_throw(): void
    {
        $cash = $this->payout('withdrawal', 'cash');
        $deposit = DB::transaction(fn () => app(SavingsLedger::class)->credit($this->savings, 'deposit', 10_000, ['method' => 'gcash']));
        $reversed = $this->payout('withdrawal', 'gcash');
        $reversal = DB::transaction(fn () => app(SavingsLedger::class)->credit($this->savings, 'reversal', 10_000, ['reverses_transaction_id' => $reversed->id]));

        foreach ([$cash, $deposit, $reversed, $reversal] as $row) {
            $this->assertRefused(fn () => $this->fresh($row->id)->forceFill(['external_reference' => 'X-1'])->save());
            $this->assertNull($this->fresh($row->id)->external_reference);
        }
    }

    private function payout(string $type, string $method): SavingsTransaction
    {
        return DB::transaction(fn () => app(SavingsLedger::class)->debit($this->savings, $type, 10_000, ['method' => $method]));
    }

    private function fresh(int $id): SavingsTransaction
    {
        return SavingsTransaction::query()->findOrFail($id);
    }

    private function assertRefused(Closure $write): void
    {
        try {
            $write();
            $this->fail('The write went through.');
        } catch (SavingsTransactionIsAppendOnlyException) {
            $this->addToAssertionCount(1);
        }
    }
}
