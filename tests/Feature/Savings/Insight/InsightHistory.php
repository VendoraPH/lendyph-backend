<?php

namespace Tests\Feature\Savings\Insight;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsMath;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Interest\SavingsHistory;

/**
 * One savings history for the insight tests (gap round S5), from Sep 1 to
 * Nov 10, 2026 (today), in two branches:
 *
 * Main branch:
 * - A (Regular): Oct 1 +₱1,000.00, Oct 15 +₱500.00, Nov 3 −₱300.00, Nov 4
 *   +₱250.50 reversed the same day, Nov 5 interest ₱12.34 and tax ₱2.47,
 *   Nov 5 ₱100.00 to B, Nov 7 ₱200.00 into time deposit TD5. Balance
 *   ₱909.87.
 * - B (Junior), opened Nov 2: +₱2,000.00 and ₱100.00 from A. ₱2,100.00.
 * - D (Regular), opened Nov 6, frozen, ₱0.00.
 * - E (Junior), opened Oct 1: +₱50.00, dormant.
 * - TD1 ₱10,000.00 placed Nov 1, matures Nov 20 (₱10,050.00).
 * - TD2 ₱20,000.00 placed Oct 1, matures Jan 5, 2027 (₱20,100.00).
 * - TD3 ₱5,000.00 placed Sep 1, settled Nov 3 with ₱80.00 interest and
 *   ₱16.00 tax.
 * - TD5 ₱200.00 placed Nov 7 from A, matures Feb 10, 2027 (₱201.00).
 *
 * Far branch:
 * - C (Regular), opened Nov 6: +₱9,999.00.
 * - TD4 ₱7,000.00 placed Nov 2, matures Mar 1, 2027 (₱7,050.00).
 *
 * Most rows are posted to the books as the teller does; the reversal, the
 * time deposit move and the time deposits themselves are not, so the GL
 * control differs.
 */
trait InsightHistory
{
    use SavingsHistory;

    protected Branch $far;

    protected SavingsProduct $regular;

    protected SavingsProduct $junior;

    protected SavingsProduct $timeProduct;

    /** @var array<string, SavingsAccount> */
    protected array $accounts = [];

    /** @var array<string, TimeDeposit> */
    protected array $deposits = [];

    protected function buildHistory(): void
    {
        $this->far = Branch::factory()->create(['name' => 'Far Branch']);
        $this->onDay('2026-09-01');
        $this->regular = $this->regularProduct(['name' => 'Regular Savings']);
        $this->junior = $this->regularProduct(['name' => 'Junior Savings']);
        $this->timeProduct = SavingsProduct::factory()->timeDeposit()->create(['name' => 'Time Deposit 90']);

        $this->deposits['TD3'] = $this->timeDeposit($this->branch, '5000.00', '2026-09-01', '2026-10-31', '5080.00', [
            'status' => 'withdrawn', 'settled_on' => '2026-11-03', 'settled_interest' => '80.00', 'settled_tax' => '16.00',
        ]);

        $this->onDay('2026-10-01');
        $this->accounts['A'] = $this->openAccount($this->regular, 100_000);
        $this->accounts['E'] = $this->openAccount($this->junior, 5_000);
        DB::table('savings_accounts')->where('id', $this->accounts['E']->id)->update(['status' => SavingsAccount::STATUS_DORMANT, 'dormant_since' => '2026-11-01']);
        $this->deposits['TD2'] = $this->timeDeposit($this->branch, '20000.00', '2026-10-01', '2027-01-05', '20100.00');

        $this->onDay('2026-10-15');
        $this->deposit($this->accounts['A'], 50_000);

        $this->onDay('2026-11-01');
        $this->deposits['TD1'] = $this->timeDeposit($this->branch, '10000.00', '2026-11-01', '2026-11-20', '10050.00');

        $this->onDay('2026-11-02');
        $this->accounts['B'] = $this->openAccount($this->junior, 200_000);
        $this->deposits['TD4'] = $this->timeDeposit($this->far, '7000.00', '2026-11-02', '2027-03-01', '7050.00');

        $this->onDay('2026-11-03');
        $this->withdraw($this->accounts['A'], 30_000);

        $this->onDay('2026-11-04');
        $reversed = $this->deposit($this->accounts['A'], 25_050);
        DB::transaction(fn () => app(SavingsLedger::class)->debit($this->accounts['A']->refresh(), 'reversal', 25_050, ['reverses_transaction_id' => $reversed->id, 'method' => 'cash']));

        $this->onDay('2026-11-05');
        DB::transaction(function () {
            $ledger = app(SavingsLedger::class);
            $a = $this->accounts['A']->refresh();
            $ledger->credit($a, 'interest', 1_234, ['created_by' => null]);
            $ledger->debit($a->refresh(), 'withholding_tax', 247, ['created_by' => null]);
            $out = $ledger->debit($a->refresh(), 'transfer_out', 10_000, ['method' => 'savings']);
            $ledger->credit($this->accounts['B']->refresh(), 'transfer_in', 10_000, ['method' => 'savings', 'counterpart_transaction_id' => $out->id]);
        });

        $this->onDay('2026-11-06');
        $this->accounts['C'] = $this->openAccount($this->regular, 999_900, $this->far);
        $this->accounts['D'] = $this->openAccount($this->regular);
        DB::table('savings_accounts')->where('id', $this->accounts['D']->id)->update(['status' => SavingsAccount::STATUS_FROZEN]);

        $this->onDay('2026-11-07');
        $this->deposits['TD5'] = $this->timeDeposit($this->branch, '200.00', '2026-11-07', '2027-02-10', '201.00');
        DB::transaction(fn () => app(SavingsLedger::class)->debit($this->accounts['A']->refresh(), 'withdrawal', 20_000, [
            'method' => 'savings', 'time_deposit_id' => $this->deposits['TD5']->id,
        ]));

        $this->onDay('2026-11-10');

        foreach ($this->accounts as $key => $account) {
            $this->accounts[$key] = $account->refresh();
        }
    }

    /** @param  array<string, mixed>  $attributes */
    protected function timeDeposit(Branch $branch, string $principal, string $placed, string $matures, string $maturityAmount, array $attributes = []): TimeDeposit
    {
        return TimeDeposit::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id])->id,
            'product_id' => $this->timeProduct->id,
            'principal' => $principal,
            'placement_date' => $placed,
            'maturity_date' => $matures,
            'maturity_amount' => $maturityAmount,
            'net_interest' => SavingsMath::pesos(SavingsMath::centavos($maturityAmount) - SavingsMath::centavos($principal)),
            ...$attributes,
        ]);
    }
}
