<?php

namespace Tests\Feature;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Expenses and payables: the lifecycle, and the journal behind every step.
 *
 * The invariant every test here is ultimately protecting is that an expense and
 * its journal are written together or not at all. A cost on this screen with no
 * entry in the books is added into the headline "Outstanding" figure and is
 * absent from the income statement, the trial balance and everything built on
 * them, and nothing joins the two to notice.
 */
class AccountingExpenseTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function expensePayload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-09-18',
            'payee' => 'Meralco',
            'expense_account_id' => $this->account('5030'),
            // ₱15,000.50 in centavos.
            'amount' => 1500050,
            'payment_account_id' => $this->account('1010'),
            'reference' => 'OR-4471',
            'description' => 'September electricity',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function createExpense(array $overrides = []): array
    {
        return $this->postJson('/api/accounting/expenses', $this->expensePayload($overrides))
            ->assertCreated()
            ->json('data');
    }

    /** The debit/credit pairs of a journal, keyed by account code. */
    private function linesOf(int $journalId): array
    {
        return AccountingJournal::findOrFail($journalId)
            ->lines()
            ->with('account:id,code')
            ->get()
            ->mapWithKeys(fn ($line): array => [
                $line->account->code => ['debit' => (int) $line->debit, 'credit' => (int) $line->credit],
            ])
            ->all();
    }

    // ── Money is integer centavos ──

    /**
     * The 100x test.
     *
     * ₱15,000.50 is 1500050 centavos. Every one of these assertions fails
     * differently if the amount is ever treated as pesos: the stored column
     * would hold 15000, the journal line 15000, and the response "15000.50" as
     * a string — which the Expenses screen subtracts into a headline figure of
     * ₱150.01 and renders without complaint.
     */
    public function test_an_amount_is_integer_centavos_end_to_end(): void
    {
        $expense = $this->createExpense();

        $this->assertSame(1500050, $expense['amount']);
        $this->assertIsInt($expense['amount']);
        $this->assertIsInt($expense['amount_paid']);

        $this->assertSame(1500050, (int) AccountingExpense::findOrFail($expense['id'])->amount);

        $lines = $this->linesOf($expense['journal_id']);
        $this->assertSame(1500050, $lines['5030']['debit']);
        $this->assertSame(1500050, $lines['1010']['credit']);
    }

    public function test_a_decimal_amount_is_refused_rather_than_floored_to_a_hundredth(): void
    {
        // A client that skipped `toCentavos` and sent pesos. Flooring this to
        // 15000 would record ₱150.00 for a ₱15,000.50 bill, and nothing about
        // the result would look wrong.
        $this->postJson('/api/accounting/expenses', $this->expensePayload(['amount' => 15000.50]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, AccountingExpense::count());
    }

    // ── The two creation shapes ──

    /** `expense_cash`: Dr the expense account, Cr the money account. */
    public function test_an_expense_paid_on_the_spot_posts_expense_cash_and_is_settled(): void
    {
        $expense = $this->createExpense();

        $this->assertSame('paid', $expense['status']);
        $this->assertSame(1500050, $expense['amount_paid']);
        $this->assertSame($this->account('1010'), $expense['payment_account_id']);

        $journal = AccountingJournal::findOrFail($expense['journal_id']);
        $this->assertSame('expense', $journal->source);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(AccountingExpense::class, $journal->postable_type);
        $this->assertSame($expense['id'], (int) $journal->postable_id);

        $this->assertSame(
            ['5030' => ['debit' => 1500050, 'credit' => 0], '1010' => ['debit' => 0, 'credit' => 1500050]],
            $this->linesOf($expense['journal_id']),
        );
    }

    /** `expense_accrual`: Dr the expense account, Cr Accounts Payable. */
    public function test_an_expense_that_is_owed_posts_expense_accrual_against_accounts_payable(): void
    {
        $expense = $this->createExpense([
            'payment_account_id' => null,
            'due_date' => '2026-12-31',
        ]);

        $this->assertSame('unpaid', $expense['status']);
        $this->assertSame(0, $expense['amount_paid']);
        $this->assertNull($expense['payment_account_id']);

        $journal = AccountingJournal::findOrFail($expense['journal_id']);
        $this->assertSame('payable', $journal->source);

        $this->assertSame(
            ['5030' => ['debit' => 1500050, 'credit' => 0], '2010' => ['debit' => 0, 'credit' => 1500050]],
            $this->linesOf($expense['journal_id']),
        );
    }

    public function test_a_due_date_on_an_expense_paid_on_the_spot_is_dropped(): void
    {
        // It is already settled. Keeping the due date would let a paid expense
        // start reading as overdue the moment that date passed.
        $expense = $this->createExpense(['due_date' => '2020-01-01']);

        $this->assertNull($expense['due_date']);
        $this->assertSame('paid', $expense['status']);
    }

    // ── Fail closed ──

    /**
     * No accounts payable mapping means NOTHING is written.
     *
     * The alternative — recording the expense and skipping the journal — gives
     * a screen that looks correct and a set of books that is missing the cost.
     */
    public function test_an_accrual_refuses_and_writes_nothing_when_accounts_payable_is_unmapped(): void
    {
        AccountingAccountMapping::query()->where('role', 'accounts_payable')->delete();

        $this->postJson('/api/accounting/expenses', $this->expensePayload(['payment_account_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_mapping');

        $this->assertSame(0, AccountingExpense::count());
        $this->assertSame(0, AccountingJournal::count());
    }

    public function test_the_cost_must_land_in_an_expense_account(): void
    {
        // 1010 is Cash on Hand. The entry would balance perfectly and report
        // the spending as an asset.
        $this->postJson('/api/accounting/expenses', $this->expensePayload([
            'expense_account_id' => $this->account('1010'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_account_id');

        $this->assertSame(0, AccountingExpense::count());
    }

    public function test_payment_must_come_from_a_money_account(): void
    {
        // 5020 is Rent — an expense account with no `cash_kind`. Crediting it
        // would take the money out of the books while leaving it in every
        // figure the dashboard reports as cash on hand.
        $this->postJson('/api/accounting/expenses', $this->expensePayload([
            'payment_account_id' => $this->account('5020'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_account_id');
    }

    public function test_a_heading_cannot_be_used_as_the_expense_account(): void
    {
        // 5000 Expenses is a group. Its balance is the sum of its children, so
        // posting to it as well counts the same money twice.
        $this->postJson('/api/accounting/expenses', $this->expensePayload([
            'expense_account_id' => $this->account('5000'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_account_id');
    }

    // ── Paying a payable ──

    public function test_a_partial_payment_advances_the_balance_and_posts_payable_payment(): void
    {
        $expense = $this->createExpense(['payment_account_id' => null, 'amount' => 1500050]);

        $paid = $this->postJson("/api/accounting/expenses/{$expense['id']}/pay", [
            'date' => '2026-09-20',
            'amount' => 500050,
            'account_id' => $this->account('1040'),
        ])->assertOk()->json('data');

        $this->assertSame('partially_paid', $paid['status']);
        $this->assertSame(500050, $paid['amount_paid']);
        $this->assertSame(1000000, $paid['amount'] - $paid['amount_paid']);

        $payment = AccountingExpense::findOrFail($expense['id'])->payments()->sole();

        // Dr Accounts Payable, Cr the bank. NO expense line — the cost was
        // recognised on accrual, and debiting it again here would report the
        // same spending twice.
        $this->assertSame(
            ['2010' => ['debit' => 500050, 'credit' => 0], '1040' => ['debit' => 0, 'credit' => 500050]],
            $this->linesOf((int) $payment->journal_id),
        );
    }

    public function test_paying_the_rest_settles_it_and_clears_accounts_payable(): void
    {
        $expense = $this->createExpense(['payment_account_id' => null]);
        $id = $expense['id'];

        foreach ([['2026-09-20', 500050], ['2026-09-25', 1000000]] as [$date, $amount]) {
            $this->postJson("/api/accounting/expenses/{$id}/pay", [
                'date' => $date,
                'amount' => $amount,
                'account_id' => $this->account('1040'),
            ])->assertOk();
        }

        $final = $this->getJson("/api/accounting/expenses/{$id}")->assertOk()->json('data');
        $this->assertSame('paid', $final['status']);
        $this->assertSame(1500050, $final['amount_paid']);

        // The accrual credited AP 1500050 and the two payments debited it
        // 500050 + 1000000. A payable settled in full leaves nothing behind.
        $payable = $this->getJson('/api/accounting/accounts/'.$this->account('2010'))
            ->assertOk()->json('data');
        $this->assertSame(0, $payable['balance']);
    }

    /**
     * THE IDEMPOTENCY TRAP.
     *
     * `expense_accrual` and `payable_payment` both post with `source =
     * 'payable'`, and JournalPoster::postImmediately() is idempotent on
     * (postable_type, postable_id, source). Anchoring the payment journal to the
     * Expense would make the poster return the ACCRUAL's entry as though the
     * payment had posted: the request succeeds, `amount_paid` advances, and no
     * money ever leaves the cash account. Nothing fails and nothing looks wrong.
     *
     * The payment is anchored to its own row, so this asserts two distinct
     * journals and, more importantly, that the bank was actually credited.
     */
    public function test_a_payment_writes_its_own_journal_rather_than_reusing_the_accruals(): void
    {
        $expense = $this->createExpense(['payment_account_id' => null]);

        $this->postJson("/api/accounting/expenses/{$expense['id']}/pay", [
            'date' => '2026-09-20',
            'amount' => 1500050,
            'account_id' => $this->account('1040'),
        ])->assertOk();

        $payment = AccountingExpense::findOrFail($expense['id'])->payments()->sole();

        $this->assertNotNull($payment->journal_id);
        $this->assertNotSame($expense['journal_id'], (int) $payment->journal_id);
        $this->assertSame(2, AccountingJournal::where('source', 'payable')->count());

        // The money actually moved. This is the assertion the collision breaks.
        $bank = $this->getJson('/api/accounting/accounts/'.$this->account('1040'))
            ->assertOk()->json('data');
        $this->assertSame(-1500050, $bank['balance']);
    }

    public function test_paying_more_than_is_outstanding_is_refused(): void
    {
        $expense = $this->createExpense(['payment_account_id' => null]);

        $this->postJson("/api/accounting/expenses/{$expense['id']}/pay", [
            'date' => '2026-09-20',
            'amount' => 1500051,
            'account_id' => $this->account('1040'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, (int) AccountingExpense::findOrFail($expense['id'])->amount_paid);
    }

    public function test_an_expense_paid_on_the_spot_cannot_be_paid_again(): void
    {
        // There is no payable behind it — the `expense_cash` entry already
        // credited the money account. Paying it again would credit cash a
        // second time against a liability that was never booked.
        $expense = $this->createExpense();

        $this->postJson("/api/accounting/expenses/{$expense['id']}/pay", [
            'date' => '2026-09-20',
            'amount' => 1,
            'account_id' => $this->account('1010'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense');
    }

    public function test_a_settled_payable_cannot_be_paid_again(): void
    {
        $expense = $this->createExpense(['payment_account_id' => null]);

        $this->postJson("/api/accounting/expenses/{$expense['id']}/pay", [
            'date' => '2026-09-20', 'amount' => 1500050, 'account_id' => $this->account('1040'),
        ])->assertOk();

        $this->postJson("/api/accounting/expenses/{$expense['id']}/pay", [
            'date' => '2026-09-21', 'amount' => 1, 'account_id' => $this->account('1040'),
        ])->assertStatus(422)->assertJsonValidationErrors('expense');
    }

    // ── Update ──

    public function test_the_descriptive_fields_can_be_corrected(): void
    {
        $expense = $this->createExpense(['payment_account_id' => null, 'due_date' => '2026-10-05']);

        $updated = $this->putJson("/api/accounting/expenses/{$expense['id']}", [
            'payee' => 'Meralco — Quezon City',
            'due_date' => '2026-11-05',
            'reference' => 'OR-4471-A',
        ])->assertOk()->json('data');

        $this->assertSame('Meralco — Quezon City', $updated['payee']);
        $this->assertSame('2026-11-05', $updated['due_date']);
        $this->assertSame('OR-4471-A', $updated['reference']);
    }

    /**
     * The figures are the journal, and the journal is posted.
     *
     * Refused rather than silently dropped: a 200 on a request that changed
     * nothing tells the user they corrected a number that did not move.
     */
    public function test_editing_a_posted_figure_is_refused_with_the_reason(): void
    {
        $expense = $this->createExpense();

        $this->putJson("/api/accounting/expenses/{$expense['id']}", ['amount' => 9900])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->putJson("/api/accounting/expenses/{$expense['id']}", [
            'expense_account_id' => $this->account('5020'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_account_id');

        $this->assertSame(1500050, (int) AccountingExpense::findOrFail($expense['id'])->amount);
    }

    // ── Permissions ──

    public function test_a_bookkeeper_may_record_an_expense_but_not_settle_one(): void
    {
        // The same preparer/approver split the journals permissions make real:
        // recording what is owed and taking money out to settle it are not the
        // same act, and nobody should do both to their own paperwork.
        $bookkeeper = $this->userWithRole('general_bookkeeper');

        $expense = $this->actingAs($bookkeeper)
            ->postJson('/api/accounting/expenses', $this->expensePayload(['payment_account_id' => null]))
            ->assertCreated()
            ->json('data');

        $this->actingAs($bookkeeper)
            ->postJson("/api/accounting/expenses/{$expense['id']}/pay", [
                'date' => '2026-09-20', 'amount' => 1, 'account_id' => $this->account('1040'),
            ])
            ->assertForbidden();
    }

    public function test_a_user_with_no_role_sees_none_of_it(): void
    {
        $this->actingAs($this->userWithNoRole())
            ->getJson('/api/accounting/expenses')
            ->assertForbidden();
    }
}
