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
 * An expense whose journal was reversed on the Journals screen (finish run
 * round 4). The reversal undoes the cost, and an accrual's payable, in the
 * books, so the expense reads "Reversed" and counts in no total, count or
 * status filter of the Expenses & Payables list. The list still shows it, and
 * the Reversed filter finds it. Nothing stored changes: the rule is read from
 * the journal's status, the one the branch closure checklist already reads.
 *
 * The Journals screen refuses expense entries since round 5
 * (ExpenseJournalGuardTest), so these are the hand reversals made before,
 * which stay as they are: posted here through JournalPoster, as that screen
 * did.
 */
class ReversedExpenseTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_the_totals_and_the_count_leave_out_an_expense_whose_journal_was_reversed(): void
    {
        $this->record(20000);
        $paidOnTheSpot = $this->record(30000, ['payment_account_id' => $this->account('1010')]);
        $owed = $this->record(150000, ['due_date' => '2026-10-10']);
        $partly = $this->record(40000);
        $this->pay($partly, 10000);

        foreach ([$paidOnTheSpot, $owed, $partly] as $id) {
            $this->reverse($id);
        }

        $meta = $this->getJson('/api/accounting/expenses')->assertOk()->json('meta');

        $this->assertSame(['count' => 1, 'amount' => 20000, 'amount_paid' => 0, 'amount_due' => 20000], $meta['totals']);
        // The list still shows all four rows.
        $this->assertSame(4, $meta['total']);
    }

    public function test_an_expense_whose_journal_was_reversed_reads_reversed_and_owes_nothing(): void
    {
        $id = $this->record(150000, ['due_date' => '2026-10-10']);
        $this->assertSame('overdue', $this->row($id)['status']);

        $this->reverse($id);

        $row = $this->row($id);
        $this->assertSame('reversed', $row['status']);
        // The recorded amount stays on the row; nothing is owed on it.
        $this->assertSame(150000, $row['amount']);
        $this->assertSame(0, $row['amount_due']);

        $this->getJson("/api/accounting/expenses/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.amount_due', 0);

        // Nothing stored was rewritten.
        $stored = AccountingExpense::query()->findOrFail($id);
        $this->assertSame(
            [150000, 0, 'unpaid', '2026-10-10'],
            [$stored->amount, $stored->amount_paid, $stored->settlement_status, $stored->due_date->toDateString()],
        );
    }

    public function test_the_status_filters_leave_it_out_and_the_reversed_filter_finds_it(): void
    {
        $this->record(20000);
        $partly = $this->record(40000);
        $this->pay($partly, 10000);
        $reversed = [
            'unpaid' => $this->record(10000),
            'partially_paid' => $partly,
            'paid' => $this->record(30000, ['payment_account_id' => $this->account('1010')]),
            'overdue' => $this->record(150000, ['due_date' => '2026-10-10']),
        ];

        foreach ($reversed as $status => $id) {
            $this->assertSame($status, $this->row($id)['status']);
            $this->reverse($id);
        }

        foreach (['unpaid', 'partially_paid', 'paid', 'overdue'] as $status) {
            $listed = array_column($this->getJson("/api/accounting/expenses?status={$status}&per_page=100")->assertOk()->json('data'), 'id');
            $this->assertSame([], array_values(array_intersect($listed, $reversed)), "The {$status} filter lists a reversed expense.");
        }

        $answer = $this->getJson('/api/accounting/expenses?status=reversed&per_page=100')->assertOk();
        $this->assertEqualsCanonicalizing(array_values($reversed), array_column($answer->json('data'), 'id'));
        $this->assertSame(['reversed'], array_values(array_unique(array_column($answer->json('data'), 'status'))));
        // They count in no total, even under their own filter.
        $this->assertSame(['count' => 0, 'amount' => 0, 'amount_paid' => 0, 'amount_due' => 0], $answer->json('meta.totals'));
        $this->assertSame(
            ['unpaid' => 1, 'partially_paid' => 0, 'paid' => 0, 'overdue' => 0, 'reversed' => 4],
            $answer->json('meta.stats'),
        );
    }

    public function test_a_payment_on_a_reversed_expense_is_refused_and_records_nothing(): void
    {
        $id = $this->record(150000);
        $this->reverse($id);
        $journals = AccountingJournal::query()->count();

        $this->postJson("/api/accounting/expenses/{$id}/pay", [
            'date' => '2026-10-05', 'amount' => 10000, 'account_id' => $this->account('1010'),
        ])->assertUnprocessable()
            ->assertJsonPath('errors.expense.0', "This expense was reversed, so it can't be paid. Record it again if it's still owed.");

        $this->assertSame(0, AccountingExpensePayment::query()->where('accounting_expense_id', $id)->count());
        $this->assertSame($journals, AccountingJournal::query()->count());
        $stored = AccountingExpense::query()->findOrFail($id);
        $this->assertSame([0, 'unpaid'], [$stored->amount_paid, $stored->settlement_status]);
    }

    /**
     * Reversing the reversal puts the cost back in the books, so the expense
     * counts again, can be paid again, and a further reversal reverses it
     * again: it reads Reversed while its chain of reversals ends on one.
     */
    public function test_an_expense_whose_reversal_was_reversed_counts_again(): void
    {
        $this->record(20000);
        $id = $this->record(150000, ['due_date' => '2026-10-10']);
        $this->reverse($id);
        $this->assertSame('reversed', $this->row($id)['status']);

        $reversal = $this->reverseTheLatestReversal($id);

        $row = $this->row($id);
        $this->assertSame(['overdue', 150000], [$row['status'], $row['amount_due']]);
        $meta = $this->getJson('/api/accounting/expenses')->assertOk()->json('meta');
        $this->assertSame(['count' => 2, 'amount' => 170000, 'amount_paid' => 0, 'amount_due' => 170000], $meta['totals']);
        $this->assertSame(['unpaid' => 1, 'partially_paid' => 0, 'paid' => 0, 'overdue' => 1, 'reversed' => 0], $meta['stats']);
        $this->assertSame([$id], array_column($this->getJson('/api/accounting/expenses?status=overdue')->json('data'), 'id'));

        $this->pay($id, 10000);

        app(JournalPoster::class)->reverse(AccountingJournal::query()->findOrFail($reversal), now()->toDateString(), 'Entered in error', $this->admin->id);
        $this->assertSame(['reversed', 0], [$this->row($id)['status'], $this->row($id)['amount_due']]);
        $this->assertSame(1, $this->getJson('/api/accounting/expenses')->json('meta.totals.count'));
    }

    /**
     * `status()` and its SQL twin `scopeWithStatus()` agree row for row: every
     * expense is in exactly one status's rows, and it's the status it reads.
     * An expense with no journal is never reversed, as on the closure
     * checklist.
     */
    public function test_the_status_and_its_sql_twin_agree_on_every_row_reversed_included(): void
    {
        $this->record(20000);
        $this->record(25000, ['due_date' => '2026-10-10']);
        $partly = $this->record(40000);
        $this->pay($partly, 10000);
        $this->record(30000, ['payment_account_id' => $this->account('1010')]);
        $this->reverse($this->record(10000));
        $this->reverse($this->record(150000, ['due_date' => '2026-10-10']));
        $reinstated = $this->record(5000);
        $this->reverse($reinstated);
        $this->reverseTheLatestReversal($reinstated);
        AccountingExpense::factory()->create(['branch_id' => $this->branch->id]);

        $statuses = [];

        foreach (AccountingExpense::query()->with('journal')->get() as $expense) {
            $matches = array_values(array_filter(
                AccountingExpense::STATUSES,
                fn (string $status): bool => AccountingExpense::query()->withStatus($status)->whereKey($expense->id)->exists(),
            ));
            $this->assertSame([$expense->status()], $matches, "Expense {$expense->id} reads {$expense->status()}.");
            $statuses[] = $expense->status();
        }

        sort($statuses);
        $this->assertSame(['overdue', 'paid', 'partially_paid', 'reversed', 'reversed', 'unpaid', 'unpaid', 'unpaid'], $statuses);
    }

    /**
     * Records an expense through the API, owed unless a payment account is
     * given, and returns its id.
     *
     * @param  array<string, mixed>  $overrides
     */
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

    /** Reverses the expense's own journal by hand, as the Journals screen did before round 5. */
    private function reverse(int $expenseId): void
    {
        $journalId = AccountingExpense::query()->findOrFail($expenseId)->journal_id;

        app(JournalPoster::class)->reverse(AccountingJournal::query()->findOrFail($journalId), now()->toDateString(), 'Entered in error', $this->admin->id);
        $this->assertSame('reversed', AccountingJournal::query()->findOrFail($journalId)->status);
    }

    /**
     * Reverses the last entry in the expense journal's chain of reversals (the
     * reversal, or the reversal's reversal), and returns the new entry's id.
     */
    private function reverseTheLatestReversal(int $expenseId): int
    {
        $journal = AccountingJournal::query()->findOrFail(AccountingExpense::query()->findOrFail($expenseId)->journal_id);

        while ($journal->reversed_by_journal_id !== null) {
            $journal = AccountingJournal::query()->findOrFail($journal->reversed_by_journal_id);
        }

        return app(JournalPoster::class)->reverse($journal, now()->toDateString(), 'Reversed in error', $this->admin->id)->id;
    }

    private function pay(int $expenseId, int $amount): void
    {
        $this->postJson("/api/accounting/expenses/{$expenseId}/pay", [
            'date' => '2026-10-05', 'amount' => $amount, 'account_id' => $this->account('1010'),
        ])->assertOk();
    }

    /** @return array<string, mixed> the expense's row on the list */
    private function row(int $id): array
    {
        return collect($this->getJson('/api/accounting/expenses?per_page=100')->assertOk()->json('data'))->firstWhere('id', $id);
    }
}
