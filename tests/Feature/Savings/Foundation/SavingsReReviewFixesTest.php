<?php

namespace Tests\Feature\Savings\Foundation;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The fixes from the re-review of 320badb: a reversal row must undo exactly
 * one row (opposite side, same amount, not a reversal, reversed once);
 * transfer legs pair one to one with equal amounts; an interest run posts
 * only its own branch's and product's rows. Each test fails before the fix.
 */
class SavingsReReviewFixesTest extends TestCase
{
    use SetupLendyPH;

    private SavingsLedger $ledger;

    private SavingsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->ledger = app(SavingsLedger::class);
        $this->account = SavingsAccount::factory()->create(['opened_on' => '2026-11-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refusedOn(callable $write, string $field, string $contains): void
    {
        try {
            DB::transaction($write);
            $this->fail('The write was not refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($contains, $e->errors()[$field][0]);
        }
    }

    /** Interest value-dated yesterday, then that day credited, as after a posted run. */
    private function creditedInterest(int $centavos = 500): SavingsTransaction
    {
        $interest = DB::transaction(fn () => $this->ledger->credit($this->account, 'interest', $centavos, ['value_date' => '2026-11-09', 'created_by' => null]));
        DB::table('savings_accounts')->where('id', $this->account->id)->update(['interest_credited_through' => '2026-11-09']);
        $this->account->refresh();

        return $interest;
    }

    public function test_a_reversal_of_the_wrong_amount_is_refused(): void
    {
        $interest = $this->creditedInterest(500);

        $this->refusedOn(fn () => $this->ledger->debit($this->account, 'reversal', 400, ['value_date' => '2026-11-09', 'reverses_transaction_id' => $interest->id]), 'amount', '₱5.00');
    }

    public function test_a_reversal_on_the_same_side_is_refused(): void
    {
        $interest = $this->creditedInterest(500);

        $this->refusedOn(fn () => $this->ledger->credit($this->account, 'reversal', 500, ['value_date' => '2026-11-09', 'reverses_transaction_id' => $interest->id]), 'reverses_transaction_id', 'opposite side');
    }

    public function test_a_reversal_of_a_reversal_is_refused(): void
    {
        $interest = $this->creditedInterest(500);
        $reversal = DB::transaction(fn () => $this->ledger->debit($this->account, 'reversal', 500, ['value_date' => '2026-11-09', 'reverses_transaction_id' => $interest->id]));

        $this->refusedOn(fn () => $this->ledger->credit($this->account, 'reversal', 500, ['value_date' => '2026-11-09', 'reverses_transaction_id' => $reversal->id]), 'reverses_transaction_id', 'itself be reversed');
    }

    public function test_a_second_reversal_of_the_same_row_is_a_422_not_a_500(): void
    {
        $deposit = DB::transaction(fn () => $this->ledger->credit($this->account, 'deposit', 1_000, ['method' => 'cash']));
        $first = DB::transaction(fn () => $this->ledger->debit($this->account, 'reversal', 1_000, ['reverses_transaction_id' => $deposit->id]));
        DB::transaction(fn () => $this->ledger->credit($this->account, 'deposit', 1_000, ['method' => 'cash']));

        $this->refusedOn(fn () => $this->ledger->debit($this->account, 'reversal', 1_000, ['reverses_transaction_id' => $deposit->id]), 'reverses_transaction_id', $first->reference);
    }

    public function test_a_reversal_can_only_undo_a_row_on_its_own_account(): void
    {
        $other = SavingsAccount::factory()->create(['opened_on' => '2026-11-01']);
        $deposit = DB::transaction(fn () => $this->ledger->credit($other, 'deposit', 1_000, ['method' => 'cash']));
        DB::transaction(fn () => $this->ledger->credit($this->account, 'deposit', 1_000, ['method' => 'cash']));

        $this->refusedOn(fn () => $this->ledger->debit($this->account, 'reversal', 1_000, ['reverses_transaction_id' => $deposit->id]), 'reverses_transaction_id', 'this account');
    }

    public function test_a_transfer_out_can_be_claimed_by_one_transfer_in_only(): void
    {
        DB::transaction(fn () => $this->ledger->credit($this->account, 'deposit', 10_000, ['method' => 'cash']));
        $to = SavingsAccount::factory()->create(['opened_on' => '2026-11-01', 'branch_id' => $this->account->branch_id]);
        $out = DB::transaction(fn () => $this->ledger->debit($this->account, 'transfer_out', 1_000, ['method' => 'savings']));
        DB::transaction(fn () => $this->ledger->credit($to, 'transfer_in', 1_000, ['method' => 'savings', 'counterpart_transaction_id' => $out->id]));

        $this->refusedOn(fn () => $this->ledger->credit($to, 'transfer_in', 1_000, ['method' => 'savings', 'counterpart_transaction_id' => $out->id]), 'counterpart_transaction_id', 'already');

        $unique = DB::selectOne(
            "select count(*) as n from information_schema.STATISTICS where TABLE_SCHEMA = database() and TABLE_NAME = 'savings_transactions' and COLUMN_NAME = 'counterpart_transaction_id' and NON_UNIQUE = 0",
        )->n;
        $this->assertSame(1, (int) $unique, 'counterpart_transaction_id must be unique.');
    }

    public function test_transfer_legs_must_carry_the_same_amount(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        DB::transaction(fn () => $this->ledger->credit($this->account, 'deposit', 10_000, ['method' => 'cash']));
        $to = SavingsAccount::factory()->create(['opened_on' => '2026-11-01', 'branch_id' => $this->account->branch_id]);

        [$out, $in] = DB::transaction(function () use ($to): array {
            $out = $this->ledger->debit($this->account, 'transfer_out', 1_000, ['method' => 'savings']);

            return [$out, $this->ledger->credit($to, 'transfer_in', 999, ['method' => 'savings', 'counterpart_transaction_id' => $out->id])];
        });

        foreach ([$out, $in] as $leg) {
            try {
                DB::transaction(fn () => app(AutomaticPoster::class)->savingsTransaction($leg, $this->admin->id));
                $this->fail('Unequal transfer legs were accepted.');
            } catch (CannotPostToTheBooksException $e) {
                $this->assertStringContainsString('same amount', $e->getMessage());
            }
        }
    }

    public function test_an_interest_run_counts_only_its_own_branch_and_product_rows(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        $run = SavingsInterestRun::factory()->create([
            'product_id' => $this->account->product_id,
            'branch_id' => $this->account->branch_id,
            'status' => 'approved',
            'total_gross' => '15.00', 'total_tax' => '0.00', 'total_net' => '15.00',
        ]);
        $foreign = SavingsAccount::factory()->create(['opened_on' => '2026-11-01', 'branch_id' => Branch::factory()->create()->id]);

        DB::transaction(function () use ($run, $foreign): void {
            $this->ledger->credit($this->account, 'interest', 1_000, ['value_date' => '2026-11-09', 'interest_run_id' => $run->id, 'created_by' => null]);
            $this->ledger->credit($foreign, 'interest', 500, ['value_date' => '2026-11-09', 'interest_run_id' => $run->id, 'created_by' => null]);
        });

        try {
            DB::transaction(fn () => app(AutomaticPoster::class)->savingsInterestRun($run, $this->admin->id));
            $this->fail('A row outside the run\'s branch and product was counted.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('₱10.00', $e->getMessage());
        }
    }
}
