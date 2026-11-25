<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Services\Accounting\JournalPoster;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * An expense's journal and each payment's journal are reversed from Expenses
 * & Payables, never by hand on the Journals screen (finish run round 5), like
 * savings, GCash and interbranch entries. The endpoint answers 422 in words,
 * and the journal reads `reversible: false` with the reason. So does the
 * reversal of one: reversing it would put a payment or a cost back in the
 * books without Expenses & Payables. Hand reversals made before stay as they
 * are.
 */
class ExpenseJournalGuardTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const IN_EXPENSES = 'This entry comes from Expenses & Payables. Reverse it from Expenses & Payables, so the expense and the books stay the same.';

    private const NO_REVERSAL = 'This entry reverses an expense or an expense payment and can\'t be reversed, so the expense and the books stay the same. If it is still owed or was paid, record it again in Expenses & Payables.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_an_expenses_own_journal_cant_be_reversed_by_hand(): void
    {
        $owed = $this->record(150000);
        $paidOnTheSpot = $this->record(30000, ['payment_account_id' => $this->account('1010')]);

        foreach ([$owed, $paidOnTheSpot] as $id) {
            $this->assertRefused(AccountingExpense::query()->findOrFail($id)->journal_id, self::IN_EXPENSES);
        }
    }

    public function test_a_payments_journal_cant_be_reversed_by_hand(): void
    {
        $id = $this->record(150000);
        $this->pay($id, 50000);

        $this->assertRefused(AccountingExpensePayment::query()->where('accounting_expense_id', $id)->sole()->journal_id, self::IN_EXPENSES);
        $this->assertSame(50000, $this->getJson("/api/accounting/expenses/{$id}")->json('data.amount_paid'));
    }

    public function test_the_reversal_of_an_expense_or_a_payment_cant_be_reversed_by_hand(): void
    {
        $id = $this->record(150000);
        $this->pay($id, 50000);
        $payment = AccountingExpensePayment::query()->where('accounting_expense_id', $id)->sole();
        $this->postJson("/api/accounting/expenses/{$id}/payments/{$payment->id}/reverse", ['reason' => 'Paid twice'])->assertOk();
        $this->postJson("/api/accounting/expenses/{$id}/reverse", ['reason' => 'Recorded twice'])->assertOk();

        foreach ([$payment->journal_id, AccountingExpense::query()->findOrFail($id)->journal_id] as $journalId) {
            $this->assertRefused(AccountingJournal::query()->findOrFail($journalId)->reversed_by_journal_id, self::NO_REVERSAL);
        }

        $this->assertSame('reversed', $this->getJson("/api/accounting/expenses/{$id}")->json('data.status'));
    }

    /**
     * A hand reversal made before this stays as it is, and the rest of its
     * chain is refused too, however far down it goes.
     */
    public function test_a_chain_of_hand_reversals_made_before_stays_as_it_is(): void
    {
        $id = $this->record(150000);
        $poster = app(JournalPoster::class);
        $first = $poster->reverse(AccountingJournal::query()->findOrFail(AccountingExpense::query()->findOrFail($id)->journal_id), '2026-10-02', 'By hand', $this->admin->id);
        $second = $poster->reverse($first, '2026-10-03', 'By hand again', $this->admin->id);

        $this->assertRefused($second->id, self::NO_REVERSAL);
        // The reversal of the reversal put the cost back.
        $this->assertSame('unpaid', $this->getJson("/api/accounting/expenses/{$id}")->json('data.status'));
    }

    /** The register reads the reason for every row in one go, and leaves other entries reversible. */
    public function test_the_register_marks_expense_entries_and_leaves_the_rest_reversible(): void
    {
        $id = $this->record(150000);
        $this->pay($id, 50000);
        $manual = $this->postSimpleJournal('5030', '1010', 10000, ['date' => '2026-10-05']);
        $expenseJournal = AccountingExpense::query()->findOrFail($id)->journal_id;
        $paymentJournal = AccountingExpensePayment::query()->where('accounting_expense_id', $id)->sole()->journal_id;

        $rows = collect($this->getJson('/api/accounting/journals?per_page=100')->assertOk()->json('data'))->keyBy('id');

        foreach ([$expenseJournal, $paymentJournal] as $journalId) {
            $this->assertFalse($rows[$journalId]['reversible']);
            $this->assertSame(self::IN_EXPENSES, $rows[$journalId]['not_reversible_reason']);
        }

        $this->assertTrue($rows[$manual->id]['reversible']);
        $this->assertNull($rows[$manual->id]['not_reversible_reason']);
        $this->postJson("/api/accounting/journals/{$manual->id}/reverse", ['reason' => 'Keyed twice'])->assertCreated();
    }

    private function assertRefused(int $journalId, string $reason): void
    {
        $status = AccountingJournal::query()->findOrFail($journalId)->status;
        $journals = AccountingJournal::query()->count();

        $this->postJson("/api/accounting/journals/{$journalId}/reverse", ['reason' => 'By hand'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', $reason);

        $this->assertSame($status, AccountingJournal::query()->findOrFail($journalId)->status);
        $this->assertSame($journals, AccountingJournal::query()->count());

        $this->getJson("/api/accounting/journals/{$journalId}")->assertOk()
            ->assertJsonPath('data.reversible', false)
            ->assertJsonPath('data.not_reversible_reason', $reason);
    }

    /** @param  array<string, mixed>  $overrides */
    private function record(int $amount, array $overrides = []): int
    {
        return $this->postJson('/api/accounting/expenses', array_merge([
            'date' => '2026-10-01',
            'payee' => 'Meralco',
            'expense_account_id' => $this->account('5030'),
            'amount' => $amount,
            'branch_id' => $this->branch->id,
        ], $overrides))->assertCreated()->json('data.id');
    }

    private function pay(int $expenseId, int $amount): void
    {
        $this->postJson("/api/accounting/expenses/{$expenseId}/pay", [
            'date' => '2026-10-05', 'amount' => $amount, 'account_id' => $this->account('1010'),
        ])->assertOk();
    }
}
