<?php

namespace Tests\Feature\Savings\Foundation;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingJournal;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AutomaticPoster's savings postings (design §4) on a cooperative chart:
 * each journal's lines, source, date, branch and postable key, idempotent by
 * that key, and reversed through reverseFor().
 */
class SavingsBooksWiringTest extends TestCase
{
    use SetupLendyPH;

    private AutomaticPoster $poster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        $this->poster = app(AutomaticPoster::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: int, 2: int}> code, debit, credit */
    private function lines(AccountingJournal $journal): array
    {
        return $journal->lines()->with('account')->orderBy('id')->get()
            ->map(fn ($line) => [$line->account->code, (int) $line->debit, (int) $line->credit])
            ->all();
    }

    private function account(): SavingsAccount
    {
        return SavingsAccount::factory()->create(['branch_id' => $this->branch->id, 'opened_on' => '2026-11-01']);
    }

    private function write(SavingsAccount $account, string $side, string $type, int $centavos, array $attributes = []): SavingsTransaction
    {
        return DB::transaction(fn () => app(SavingsLedger::class)->{$side}($account, $type, $centavos, $attributes));
    }

    public function test_a_deposit_posts_dr_cash_cr_savings_deposits_once(): void
    {
        $deposit = $this->write($this->account(), 'credit', 'deposit', 150_000, ['method' => 'cash']);

        $journal = DB::transaction(fn () => $this->poster->savingsTransaction($deposit, $this->admin->id));
        $again = DB::transaction(fn () => $this->poster->savingsTransaction($deposit, $this->admin->id));

        $this->assertSame($journal->id, $again->id);
        $this->assertSame('savings', $journal->source);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('2026-11-10', $journal->date->toDateString());
        $this->assertSame($this->branch->id, $journal->branch_id);
        $this->assertSame(SavingsTransaction::class, $journal->postable_type);
        $this->assertSame($deposit->reference, $journal->reference);
        $this->assertSame([['1010', 150_000, 0], ['2050', 0, 150_000]], $this->lines($journal));
        $this->assertSame(1, AccountingJournal::query()->where('postable_type', SavingsTransaction::class)->count());
    }

    public function test_a_withdrawal_by_bank_transfer_and_a_closing_payout_by_gcash(): void
    {
        $account = $this->account();
        $this->write($account, 'credit', 'deposit', 100_000, ['method' => 'cash']);
        $withdrawal = $this->write($account, 'debit', 'withdrawal', 40_000, ['method' => 'bank_transfer']);
        $closing = $this->write($account, 'debit', 'closing', 60_000, ['method' => 'gcash']);

        $this->assertSame([['2050', 40_000, 0], ['1040', 0, 40_000]], $this->lines($this->poster->savingsTransaction($withdrawal, $this->admin->id)));
        $this->assertSame([['2050', 60_000, 0], ['1020', 0, 60_000]], $this->lines($this->poster->savingsTransaction($closing, $this->admin->id)));
    }

    public function test_a_transfer_posts_no_journal_and_an_interest_row_is_refused_there(): void
    {
        $account = $this->account();
        $this->write($account, 'credit', 'deposit', 100, ['method' => 'cash']);
        $out = $this->write($account, 'debit', 'transfer_out', 100, ['method' => 'savings']);
        $in = $this->write($this->account(), 'credit', 'transfer_in', 100, ['method' => 'savings', 'counterpart_transaction_id' => $out->id]);
        $interest = $this->write($account, 'credit', 'interest', 100, ['value_date' => '2026-11-09', 'created_by' => null]);

        $this->assertNull($this->poster->savingsTransaction($in, $this->admin->id));

        $this->expectException(CannotPostToTheBooksException::class);
        $this->poster->savingsTransaction($interest, $this->admin->id);
    }

    public function test_interest_credited_at_close_posts_expense_net_and_tax(): void
    {
        $account = $this->account();
        $interest = $this->write($account, 'credit', 'interest', 10_000, ['value_date' => '2026-11-09', 'created_by' => null]);
        $tax = $this->write($account, 'debit', 'withholding_tax', 2_000, ['value_date' => '2026-11-09', 'created_by' => null]);

        $journal = $this->poster->savingsInterestCredit($interest, $tax, $this->admin->id);

        $this->assertSame('savings_interest', $journal->source);
        $this->assertSame([['5160', 10_000, 0], ['2050', 0, 8_000], ['2210', 0, 2_000]], $this->lines($journal));
        $this->assertSame([['5160', 500, 0], ['2050', 0, 500]], $this->lines($this->poster->savingsInterestCredit(
            $this->write($this->account(), 'credit', 'interest', 500, ['value_date' => '2026-11-09', 'created_by' => null]),
            null,
            null,
        )));
    }

    public function test_an_interest_run_posts_one_journal_for_its_branch(): void
    {
        $run = SavingsInterestRun::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'approved',
            'total_gross' => '1234.56',
            'total_tax' => '0.00',
            'total_net' => '1234.56',
        ]);
        $this->write($this->account(), 'credit', 'interest', 123_456, ['value_date' => '2026-11-09', 'interest_run_id' => $run->id, 'created_by' => null]);

        $journal = $this->poster->savingsInterestRun($run, $this->admin->id);

        $this->assertSame('savings_interest', $journal->source);
        $this->assertSame($this->branch->id, $journal->branch_id);
        $this->assertSame('2026-11-10', $journal->date->toDateString());
        $this->assertSame([['5160', 123_456, 0], ['2050', 0, 123_456]], $this->lines($journal));
    }

    public function test_a_time_deposit_placement_and_settlement(): void
    {
        $fromSavings = TimeDeposit::factory()->create(['branch_id' => $this->branch->id, 'principal' => '50000.00', 'funding_method' => 'savings', 'placement_date' => '2026-11-10']);
        $fromCash = TimeDeposit::factory()->create(['branch_id' => $this->branch->id, 'principal' => '20000.00', 'funding_method' => 'cash', 'placement_date' => '2026-11-10']);

        $this->assertSame([['2050', 5_000_000, 0], ['2060', 0, 5_000_000]], $this->lines($this->poster->timeDepositPlacement($fromSavings, $this->admin->id)));
        $this->assertSame('time_deposit', $this->poster->timeDepositPlacement($fromCash, $this->admin->id)->source);

        $fromSavings->forceFill(['status' => 'withdrawn', 'settled_on' => '2026-11-10', 'settled_interest' => '616.44', 'settled_tax' => '123.29', 'settled_amount' => '50493.15'])->save();
        $journal = $this->poster->timeDepositSettlement($fromSavings, 0, 'cash', null);

        $this->assertSame('time_deposit_settlement', $journal->source);
        $this->assertNull($journal->created_by);
        $this->assertSame([['2060', 5_000_000, 0], ['5160', 61_644, 0], ['2210', 0, 12_329], ['1010', 0, 5_049_315]], $this->lines($journal));
    }

    public function test_reverse_for_reverses_a_savings_journal(): void
    {
        $deposit = $this->write($this->account(), 'credit', 'deposit', 25_000, ['method' => 'maya']);
        $journal = $this->poster->savingsTransaction($deposit, $this->admin->id);

        $reversal = DB::transaction(fn () => $this->poster->reverseFor($deposit, 'savings', $this->admin->id, 'Entered twice'));

        $this->assertSame('reversed', $journal->refresh()->status);
        $this->assertSame([['1030', 0, 25_000], ['2050', 25_000, 0]], $this->lines($reversal));
    }

    public function test_the_journal_list_names_a_savings_transaction_by_its_reference(): void
    {
        $deposit = $this->write($this->account(), 'credit', 'deposit', 100, ['method' => 'cash']);
        $this->poster->savingsTransaction($deposit, $this->admin->id);

        $this->getJson('/api/accounting/journals?source=savings')
            ->assertOk()
            ->assertJsonPath('data.0.postable_label', $deposit->reference);
    }

    public function test_savings_refuses_to_post_without_books(): void
    {
        DB::table('accounting_account_mappings')->delete();
        DB::table('accounting_accounts')->update(['parent_id' => null]);
        DB::table('accounting_accounts')->delete();
        $deposit = $this->write($this->account(), 'credit', 'deposit', 100, ['method' => 'cash']);

        $this->expectException(CannotPostToTheBooksException::class);
        $this->poster->savingsTransaction($deposit, $this->admin->id);
    }
}
