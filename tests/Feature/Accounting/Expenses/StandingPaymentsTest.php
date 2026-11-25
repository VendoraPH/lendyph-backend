<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Services\Accounting\JournalPoster;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * An expense's paid amount counts only the payments whose journal stands
 * (finish run round 5, the twin of round 4's Reversed rule). A payment whose
 * journal was reversed by hand on the Journals screen before that was refused
 * no longer counts, wherever the paid amount is read: the row's paid amount,
 * amount due and status, the list's totals, status filters and counts, and the
 * branch closure checklist. Nothing stored is rewritten.
 */
class StandingPaymentsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_payment_reversed_by_hand_before_no_longer_counts_as_paid(): void
    {
        $id = $this->record(1000000, ['due_date' => '2026-11-30']);
        $this->pay($id, 400000);
        $this->pay($id, 600000);
        $this->assertSame('paid', $this->row($id)['status']);

        $this->reverseByHand($this->paymentsOf($id)[0]);

        $row = $this->row($id);
        $this->assertSame([600000, 400000, 'partially_paid'], [$row['amount_paid'], $row['amount_due'], $row['status']]);
        $this->getJson("/api/accounting/expenses/{$id}")->assertOk()
            ->assertJsonPath('data.amount_paid', 600000)
            ->assertJsonPath('data.amount_due', 400000);

        $meta = $this->getJson('/api/accounting/expenses')->assertOk()->json('meta');
        $this->assertSame(['count' => 1, 'amount' => 1000000, 'amount_paid' => 600000, 'amount_due' => 400000], $meta['totals']);
        $this->assertSame(['unpaid' => 0, 'partially_paid' => 1, 'paid' => 0, 'overdue' => 0, 'reversed' => 0], $meta['stats']);
        $this->assertSame([$id], $this->ids('partially_paid'));
        $this->assertSame([], $this->ids('paid'));

        // Nothing stored was rewritten.
        $stored = AccountingExpense::query()->findOrFail($id);
        $this->assertSame([1000000, 'paid'], [$stored->amount_paid, $stored->settlement_status]);
    }

    public function test_what_it_paid_is_owed_again_and_overdue_past_the_due_date(): void
    {
        $id = $this->record(1000000, ['due_date' => '2026-10-10']);
        $this->pay($id, 1000000);
        $this->reverseByHand($this->paymentsOf($id)[0]);

        $this->assertSame(['overdue', 0, 1000000], [$this->row($id)['status'], $this->row($id)['amount_paid'], $this->row($id)['amount_due']]);
        $this->assertSame([$id], $this->ids('overdue'));

        // It can be paid again, for what no longer counts.
        $this->pay($id, 1000000);
        $this->assertSame(['paid', 1000000, 0], [$this->row($id)['status'], $this->row($id)['amount_paid'], $this->row($id)['amount_due']]);
    }

    /** A reversal that was itself reversed by hand puts the payment back. */
    public function test_a_reversed_reversal_puts_the_payment_back(): void
    {
        $id = $this->record(1000000);
        $this->pay($id, 400000);
        $reversal = $this->reverseByHand($this->paymentsOf($id)[0]);
        $this->assertSame(0, $this->row($id)['amount_paid']);

        app(JournalPoster::class)->reverse($reversal, '2026-10-12', 'Reversed in error', $this->admin->id);

        $this->assertSame([400000, 'partially_paid'], [$this->row($id)['amount_paid'], $this->row($id)['status']]);
    }

    /**
     * An owed expense with a payment reversed by hand holds a branch's closure,
     * since paying it posts into the branch; one paid in full doesn't.
     */
    public function test_the_closure_checklist_counts_it_as_unpaid(): void
    {
        $quiet = Branch::factory()->create(['name' => 'Quiet Branch', 'code' => 'QUIET']);
        $id = $this->record(1000000, ['branch_id' => $quiet->id]);
        $this->pay($id, 1000000);
        $this->assertSame(0, $this->unpaidPayables($quiet));

        $this->reverseByHand($this->paymentsOf($id)[0]);

        $this->assertSame(1, $this->unpaidPayables($quiet));
    }

    /**
     * `status()` and its SQL twin `scopeWithStatus()` agree row for row once
     * payments are reversed, and the totals equal the rows'.
     */
    public function test_the_status_and_its_sql_twin_agree_with_reversed_payments(): void
    {
        $paid = $this->record(100000);
        $this->pay($paid, 100000);
        $partly = $this->record(200000);
        $this->pay($partly, 50000);
        $this->pay($partly, 50000);
        $this->reverseByHand($this->paymentsOf($partly)[1]);
        $unpaid = $this->record(300000);
        $this->pay($unpaid, 300000);
        $this->reverseByHand($this->paymentsOf($unpaid)[0]);
        $overdue = $this->record(400000, ['due_date' => '2026-10-10']);
        $this->pay($overdue, 100000);
        $this->pay($overdue, 300000);
        $this->reverseByHand($this->paymentsOf($overdue)[1]);
        $onTheSpot = $this->record(50000, ['payment_account_id' => $this->account('1010')]);
        $reversed = $this->record(60000);
        $this->pay($reversed, 20000);
        $this->reverseByHand($this->paymentsOf($reversed)[0]);
        app(JournalPoster::class)->reverse(AccountingJournal::query()->findOrFail(AccountingExpense::query()->findOrFail($reversed)->journal_id), '2026-10-12', 'By hand', $this->admin->id);

        $expected = [$paid => 'paid', $partly => 'partially_paid', $unpaid => 'unpaid', $overdue => 'overdue', $onTheSpot => 'paid', $reversed => 'reversed'];

        foreach (AccountingExpense::query()->with(AccountingExpense::STATUS_RELATIONS)->get() as $expense) {
            $matches = array_values(array_filter(
                AccountingExpense::STATUSES,
                fn (string $status): bool => AccountingExpense::query()->withStatus($status)->whereKey($expense->id)->exists(),
            ));
            $this->assertSame([$expense->status()], $matches, "Expense {$expense->id} reads {$expense->status()}.");
            $this->assertSame($expected[$expense->id], $expense->status());
        }

        $answer = $this->getJson('/api/accounting/expenses?per_page=100')->assertOk();
        $counted = array_filter($answer->json('data'), fn (array $row): bool => $row['status'] !== 'reversed');
        $this->assertSame(array_sum(array_column($counted, 'amount_paid')), $answer->json('meta.totals.amount_paid'));
        $this->assertSame(array_sum(array_column($counted, 'amount_due')), $answer->json('meta.totals.amount_due'));
        $this->assertSame(['count' => 5, 'amount' => 1050000, 'amount_paid' => 300000, 'amount_due' => 750000], $answer->json('meta.totals'));
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

    /** @return list<AccountingExpensePayment> oldest first */
    private function paymentsOf(int $expenseId): array
    {
        return AccountingExpensePayment::query()->where('accounting_expense_id', $expenseId)->orderBy('id')->get()->all();
    }

    /**
     * Reverses a payment's journal the way the Journals screen did before it
     * refused expense entries: straight through the poster.
     */
    private function reverseByHand(AccountingExpensePayment $payment): AccountingJournal
    {
        return app(JournalPoster::class)->reverse(AccountingJournal::query()->findOrFail($payment->journal_id), '2026-10-08', 'By hand', $this->admin->id);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return collect($this->getJson('/api/accounting/expenses?per_page=100')->assertOk()->json('data'))->firstWhere('id', $id);
    }

    /** @return list<int> */
    private function ids(string $status): array
    {
        return array_column($this->getJson("/api/accounting/expenses?status={$status}&per_page=100")->assertOk()->json('data'), 'id');
    }

    private function unpaidPayables(Branch $branch): int
    {
        return collect($this->getJson("/api/branches/{$branch->id}/closure")->assertOk()->json('data.checklist'))
            ->firstWhere('key', 'unpaid_payables')['count'];
    }
}
