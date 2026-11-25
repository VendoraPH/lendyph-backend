<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Reversing an expense, or its payment, whose entry a bank reconciliation's
 * statement line is matched to (finish run round 6). While the reconciliation
 * is open (it doesn't read Reconciled), the reversal frees the line, unmatched
 * again, with an audit row in words on the reconciliation. A reconciliation
 * that reads Reconciled refuses the reversal in words, and nothing is posted,
 * until it's reopened by unmatching the line.
 */
class ExpenseReconciliationReversalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_reversing_an_expense_made_from_a_statement_line_frees_the_line_while_the_reconciliation_is_open(): void
    {
        [$reconciliation, $line] = $this->reconciliation(statementBalance: 0);
        $expense = $this->expenseFrom($reconciliation, $line);
        $other = $this->secondLine($reconciliation);
        $this->assertNotNull($line->refresh()->matched_journal_line_id);

        $this->reverseExpense($expense->id)->assertOk()->assertJsonPath('data.status', 'reversed');

        $this->assertNull($line->refresh()->matched_journal_line_id, 'the line is unmatched again');
        $this->assertNull($other->refresh()->matched_journal_line_id, 'the other line is untouched');
        $journal = AccountingJournal::query()->findOrFail($expense->journal_id);
        $reversal = AccountingJournal::query()->findOrFail($journal->reversed_by_journal_id);

        $row = AuditLog::query()->where('auditable_type', AccountingReconciliation::class)->latest('id')->firstOrFail();
        $this->assertSame('updated', $row->action);
        $this->assertSame($reconciliation->id, (int) $row->auditable_id);
        $this->assertSame(['statement_line_id' => $line->id, 'matched_journal_line_id' => $this->bankLineOf($journal->id)], $row->old_values);
        $this->assertSame(['statement_line_id' => $line->id, 'matched_journal_line_id' => null], $row->new_values);
        $this->assertSame(
            "Statement line of Sep 12, 2026 for ₱150.00 out unmatched: its entry {$journal->journal_no} was reversed by {$reversal->journal_no} when the expense to BDO was reversed on Expenses & Payables.",
            $row->description,
        );

        $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()
            ->assertJsonPath('data.is_reconciled', false)
            ->assertJsonPath('data.matched_count', 0);
    }

    public function test_a_reconciliation_that_reads_reconciled_refuses_the_expense_reversal_in_words(): void
    {
        [$reconciliation, $line] = $this->reconciliation(statementBalance: -15000);
        $expense = $this->expenseFrom($reconciliation, $line);
        $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()->assertJsonPath('data.is_reconciled', true);
        $journals = AccountingJournal::query()->count();
        $matched = $line->refresh()->matched_journal_line_id;

        $this->reverseExpense($expense->id)->assertUnprocessable()->assertJsonPath(
            'errors.expense.0',
            'This expense is matched on the September 2026 reconciliation of 1040 Bank Accounts (All branches), which reads Reconciled. '
            .'Reopen that reconciliation first by unmatching its statement line there, then reverse again.',
        );

        $this->assertSame($journals, AccountingJournal::query()->count(), 'nothing was posted');
        $this->assertSame($matched, $line->refresh()->matched_journal_line_id, 'the line stays matched');
        $this->assertSame('posted', AccountingJournal::query()->findOrFail($expense->journal_id)->status);

        // Reopened by unmatching the line, the reversal goes ahead.
        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", [
            'matches' => [['line_id' => $line->id, 'journal_line_id' => null]],
        ])->assertOk();
        $this->reverseExpense($expense->id)->assertOk()->assertJsonPath('data.status', 'reversed');
    }

    public function test_reversing_a_payment_matched_to_a_statement_line_frees_the_line_while_the_reconciliation_is_open(): void
    {
        $id = $this->owed(15000);
        $payment = $this->pay($id, 15000, '2026-09-12');
        [$reconciliation, $line] = $this->reconciliation(statementBalance: 0);
        $paymentJournal = AccountingExpensePayment::query()->findOrFail($payment)->journal_id;
        $this->match($reconciliation, $line, $this->bankLineOf($paymentJournal));

        $this->reversePayment($id, $payment)->assertOk()->assertJsonPath('data.amount_paid', 0);

        $this->assertNull($line->refresh()->matched_journal_line_id);
        $journal = AccountingJournal::query()->findOrFail($paymentJournal);
        $this->assertStringContainsString(
            "its entry {$journal->journal_no} was reversed by ".AccountingJournal::query()->findOrFail($journal->reversed_by_journal_id)->journal_no
            .' when a payment of the expense to Meralco was reversed on Expenses & Payables.',
            AuditLog::query()->where('auditable_type', AccountingReconciliation::class)->latest('id')->firstOrFail()->description,
        );
    }

    public function test_a_reconciliation_that_reads_reconciled_refuses_the_payment_reversal_in_words(): void
    {
        $id = $this->owed(15000);
        $payment = $this->pay($id, 15000, '2026-09-12');
        [$reconciliation, $line] = $this->reconciliation(statementBalance: -15000);
        $this->match($reconciliation, $line, $this->bankLineOf(AccountingExpensePayment::query()->findOrFail($payment)->journal_id));
        $journals = AccountingJournal::query()->count();

        $this->reversePayment($id, $payment)->assertUnprocessable()->assertJsonPath(
            'errors.payment.0',
            'This payment is matched on the September 2026 reconciliation of 1040 Bank Accounts (All branches), which reads Reconciled. '
            .'Reopen that reconciliation first by unmatching its statement line there, then reverse again.',
        );

        $this->assertSame($journals, AccountingJournal::query()->count());
        $this->assertNotNull($line->refresh()->matched_journal_line_id);
    }

    public function test_an_expense_no_statement_line_is_matched_to_reverses_as_before_and_audits_no_reconciliation(): void
    {
        $id = $this->owed(15000);
        $payment = $this->pay($id, 15000, '2026-09-12');
        $this->reconciliation(statementBalance: 0);

        $this->reversePayment($id, $payment)->assertOk();
        $this->reverseExpense($id)->assertOk();

        $this->assertSame(0, AuditLog::query()->where('auditable_type', AccountingReconciliation::class)->where('action', 'updated')->count());
    }

    /**
     * A September 2026 reconciliation of 1040 for all branches, with one ₱150.00
     * money-out statement line on Sep 12.
     *
     * @return array{AccountingReconciliation, AccountingReconciliationLine}
     */
    private function reconciliation(int $statementBalance): array
    {
        $id = $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => $statementBalance,
            'lines' => [['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000]],
        ])->assertCreated()->json('data.id');

        $reconciliation = AccountingReconciliation::query()->findOrFail($id);

        return [$reconciliation, AccountingReconciliationLine::query()->where('accounting_reconciliation_id', $id)->sole()];
    }

    /** A second, unmatched statement line on the same reconciliation, money in. */
    private function secondLine(AccountingReconciliation $reconciliation): AccountingReconciliationLine
    {
        return AccountingReconciliationLine::query()->create([
            'accounting_reconciliation_id' => $reconciliation->id,
            'date' => '2026-09-15',
            'description' => 'Deposit',
            'amount' => 5000,
        ]);
    }

    private function expenseFrom(AccountingReconciliation $reconciliation, AccountingReconciliationLine $line): AccountingExpense
    {
        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/lines/{$line->id}/expense", [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertCreated();

        return AccountingExpense::query()->sole();
    }

    private function owed(int $amount): int
    {
        return $this->postJson('/api/accounting/expenses', [
            'date' => '2026-09-01',
            'payee' => 'Meralco',
            'expense_account_id' => $this->account('5030'),
            'amount' => $amount,
            'branch_id' => $this->branch->id,
        ])->assertCreated()->json('data.id');
    }

    /** Pays the expense from 1040 and returns the payment's id. */
    private function pay(int $expenseId, int $amount, string $date): int
    {
        $this->postJson("/api/accounting/expenses/{$expenseId}/pay", [
            'date' => $date, 'amount' => $amount, 'account_id' => $this->account('1040'),
        ])->assertOk();

        return (int) AccountingExpensePayment::query()->where('accounting_expense_id', $expenseId)->max('id');
    }

    private function match(AccountingReconciliation $reconciliation, AccountingReconciliationLine $line, int $journalLineId): void
    {
        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", [
            'matches' => [['line_id' => $line->id, 'journal_line_id' => $journalLineId]],
        ])->assertOk();
        $this->assertSame($journalLineId, $line->refresh()->matched_journal_line_id);
    }

    private function bankLineOf(int $journalId): int
    {
        return (int) AccountingJournalLine::query()
            ->where('accounting_journal_id', $journalId)
            ->where('accounting_account_id', $this->account('1040'))
            ->sole()
            ->id;
    }

    private function reversePayment(int $expenseId, int $paymentId): TestResponse
    {
        return $this->postJson("/api/accounting/expenses/{$expenseId}/payments/{$paymentId}/reverse", ['reason' => 'Paid twice']);
    }

    private function reverseExpense(int $expenseId): TestResponse
    {
        return $this->postJson("/api/accounting/expenses/{$expenseId}/reverse", ['reason' => 'Recorded in error']);
    }
}
