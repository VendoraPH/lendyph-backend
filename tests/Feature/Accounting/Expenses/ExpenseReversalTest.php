<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Reversing an expense's payment, and then the expense, from Expenses &
 * Payables (finish run round 5). Each takes a reason, posts its reversal
 * through JournalPoster (the Journals screen's own path, so its period and
 * date rules apply) and writes an audit row in words. An expense can't be
 * reversed while any of its payments stands, and a reversed expense can't be
 * edited.
 */
class ExpenseReversalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_the_payments_and_then_the_expense_are_reversed_and_the_books_come_back(): void
    {
        $before = $this->balances();
        $id = $this->record(1000000);
        $first = $this->pay($id, 400000, '2026-10-05');
        $second = $this->pay($id, 200000, '2026-10-06');

        $this->reverseExpense($id)->assertUnprocessable()
            ->assertJsonPath('errors.expense.0', 'Reverse its payments first: ₱6,000.00 paid in 2 payments.');

        $row = $this->reversePayment($id, $first)->assertOk()->json('data');
        $this->assertSame([200000, 800000, 'partially_paid'], [$row['amount_paid'], $row['amount_due'], $row['status']]);

        $journal = AccountingJournal::query()->findOrFail(AccountingExpensePayment::query()->findOrFail($first)->journal_id);
        $reversal = AccountingJournal::query()->findOrFail($journal->reversed_by_journal_id);
        $this->assertSame(['reversed', 'posted', 'reversal', '2026-10-20'], [$journal->status, $reversal->status, $reversal->source, $reversal->date->toDateString()]);
        $this->assertStringEndsWith('(Reason: Paid twice)', $reversal->description);

        $this->reverseExpense($id)->assertUnprocessable()
            ->assertJsonPath('errors.expense.0', 'Reverse its payments first: ₱2,000.00 paid in 1 payment.');

        $this->reversePayment($id, $second)->assertOk()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.amount_paid', 0)
            ->assertJsonPath('data.amount_due', 1000000);

        $row = $this->reverseExpense($id)->assertOk()->json('data');
        $this->assertSame(['reversed', 0, 0], [$row['status'], $row['amount_paid'], $row['amount_due']]);

        // The cash came back, nothing is owed and the cost is gone.
        $this->assertSame($before, $this->balances());
        $this->assertSame(['count' => 0, 'amount' => 0, 'amount_paid' => 0, 'amount_due' => 0], $this->getJson('/api/accounting/expenses')->json('meta.totals'));
    }

    public function test_an_expense_paid_on_the_spot_is_reversed_directly(): void
    {
        $before = $this->balances();
        $id = $this->record(300000, ['payment_account_id' => $this->account('1010')]);

        $this->getJson("/api/accounting/expenses/{$id}")->assertOk()
            ->assertJsonPath('data.can_reverse', true)
            ->assertJsonPath('data.reverse_blocked_reason', null)
            ->assertJsonPath('data.payments', []);

        $this->reverseExpense($id, 'Recorded twice')->assertOk()
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.amount_paid', 0)
            ->assertJsonPath('data.amount_due', 0)
            ->assertJsonPath('data.can_reverse', false)
            ->assertJsonPath('data.can_edit', false);

        $this->assertSame($before, $this->balances());
    }

    public function test_the_screen_is_told_which_actions_are_open(): void
    {
        $id = $this->record(1000000);
        $payment = $this->pay($id, 400000, '2026-10-05');

        $data = $this->getJson("/api/accounting/expenses/{$id}")->assertOk()->json('data');
        $this->assertSame([false, 'Reverse its payments first: ₱4,000.00 paid in 1 payment.', true], [$data['can_reverse'], $data['reverse_blocked_reason'], $data['can_edit']]);
        $this->assertCount(1, $data['payments']);
        $this->assertSame(
            ['id' => $payment, 'date' => '2026-10-05', 'amount' => 400000, 'payment_account_name' => 'Cash on Hand', 'reversed' => false, 'can_reverse' => true],
            array_intersect_key($data['payments'][0], array_flip(['id', 'date', 'amount', 'payment_account_name', 'reversed', 'can_reverse'])),
        );
        $this->assertMatchesRegularExpression('/^JE-\d{6}$/', $data['payments'][0]['journal_no']);

        $this->reversePayment($id, $payment)->assertOk();

        // The list carries the same flags.
        $row = collect($this->getJson('/api/accounting/expenses')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame([true, null, true], [$row['can_reverse'], $row['reverse_blocked_reason'], $row['can_edit']]);
        $this->assertSame([true, false], [$row['payments'][0]['reversed'], $row['payments'][0]['can_reverse']]);

        $this->reverseExpense($id)->assertOk();
        $data = $this->getJson("/api/accounting/expenses/{$id}")->assertOk()->json('data');
        $this->assertSame([false, null, false], [$data['can_reverse'], $data['reverse_blocked_reason'], $data['can_edit']]);
    }

    public function test_a_payment_or_an_expense_already_reversed_is_refused_in_words(): void
    {
        $id = $this->record(1000000);
        $payment = $this->pay($id, 400000, '2026-10-05');
        $this->reversePayment($id, $payment)->assertOk();
        $journals = AccountingJournal::query()->count();

        $this->reversePayment($id, $payment)->assertUnprocessable()
            ->assertJsonPath('errors.payment.0', 'This payment was already reversed.');

        $this->reverseExpense($id)->assertOk();
        $this->reverseExpense($id)->assertUnprocessable()
            ->assertJsonPath('errors.expense.0', 'This expense was already reversed.');

        // Only the expense's own reversal was posted after the first refusal.
        $this->assertSame($journals + 1, AccountingJournal::query()->count());
    }

    public function test_a_reason_is_required_and_kept_short(): void
    {
        $id = $this->record(1000000);
        $payment = $this->pay($id, 400000, '2026-10-05');

        $this->postJson("/api/accounting/expenses/{$id}/payments/{$payment}/reverse", ['reason' => '   '])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Say why this payment is being reversed.');
        $this->postJson("/api/accounting/expenses/{$id}/reverse", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Say why this expense is being reversed.');
        $this->postJson("/api/accounting/expenses/{$id}/payments/{$payment}/reverse", ['reason' => str_repeat('a', 301)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'The reason can be at most 300 characters.');

        $this->assertSame('posted', AccountingJournal::query()->findOrFail(AccountingExpensePayment::query()->findOrFail($payment)->journal_id)->status);
    }

    /**
     * A reversal is never dated before the entry it reverses: a payment dated
     * after today is reversed on its own date, where the pair cancels out.
     */
    public function test_a_payment_dated_after_today_is_reversed_on_its_own_date(): void
    {
        $id = $this->record(1000000);
        $payment = $this->pay($id, 400000, '2026-10-25');

        $this->reversePayment($id, $payment)->assertOk();

        $journal = AccountingJournal::query()->findOrFail(AccountingExpensePayment::query()->findOrFail($payment)->journal_id);
        $this->assertSame('2026-10-25', AccountingJournal::query()->findOrFail($journal->reversed_by_journal_id)->date->toDateString());
    }

    public function test_a_payment_of_another_expense_is_not_found(): void
    {
        $id = $this->record(1000000);
        $other = $this->record(500000);
        $payment = $this->pay($other, 100000, '2026-10-05');

        $this->reversePayment($id, $payment)->assertNotFound();
        $this->assertSame('posted', AccountingJournal::query()->findOrFail(AccountingExpensePayment::query()->findOrFail($payment)->journal_id)->status);
    }

    /**
     * A reversed payment no longer counts, so what it paid is owed again and
     * can be paid again. The stored `amount_paid` follows the payments each
     * action counts, so it never runs past the amount.
     */
    public function test_what_a_reversed_payment_paid_can_be_paid_again(): void
    {
        $id = $this->record(1000000);
        $payment = $this->pay($id, 1000000, '2026-10-05');
        $this->reversePayment($id, $payment)->assertOk()->assertJsonPath('data.amount_due', 1000000);

        $this->pay($id, 1000000, '2026-10-07');

        $this->getJson("/api/accounting/expenses/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', 1000000)
            ->assertJsonPath('data.amount_due', 0);
        $stored = AccountingExpense::query()->findOrFail($id);
        $this->assertSame([1000000, 'paid'], [$stored->amount_paid, $stored->settlement_status]);
    }

    public function test_a_reversed_expense_cant_be_edited(): void
    {
        $id = $this->record(1000000);
        $this->putJson("/api/accounting/expenses/{$id}", ['payee' => 'Meralco Inc.'])->assertOk();

        $this->reverseExpense($id)->assertOk();

        $this->putJson("/api/accounting/expenses/{$id}", ['payee' => 'Someone else', 'due_date' => '2026-12-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.expense.0', "This expense was reversed, so it can't be edited. Record it again if it's still owed.");

        $stored = AccountingExpense::query()->findOrFail($id);
        $this->assertSame(['Meralco Inc.', null], [$stored->payee, $stored->due_date]);
    }

    /**
     * Reverse payment needs `expenses:pay` and `journals:reverse`; Reverse
     * expense needs `expenses:update` and `journals:reverse`.
     */
    public function test_each_action_needs_both_of_its_permissions(): void
    {
        $id = $this->record(1000000);
        $payment = $this->pay($id, 400000, '2026-10-05');

        foreach ([['expenses:pay'], ['journals:reverse'], ['expenses:update', 'journals:reverse']] as $permissions) {
            $this->actingAs($this->userWith($permissions));
            $this->reversePayment($id, $payment)->assertForbidden();
        }

        foreach ([['expenses:update'], ['journals:reverse'], ['expenses:pay', 'journals:reverse']] as $permissions) {
            $this->actingAs($this->userWith($permissions));
            $this->reverseExpense($id)->assertForbidden();
        }

        $this->assertSame('posted', AccountingJournal::query()->findOrFail(AccountingExpensePayment::query()->findOrFail($payment)->journal_id)->status);

        $this->actingAs($this->userWith(['expenses:pay', 'journals:reverse']));
        $this->reversePayment($id, $payment)->assertOk();

        $this->actingAs($this->userWith(['expenses:update', 'journals:reverse']));
        $this->reverseExpense($id)->assertOk()->assertJsonPath('data.status', 'reversed');
    }

    public function test_each_reversal_writes_an_audit_row_in_words(): void
    {
        $id = $this->record(1000000);
        $payment = $this->pay($id, 400000, '2026-10-05');
        $paymentJournal = AccountingJournal::query()->findOrFail(AccountingExpensePayment::query()->findOrFail($payment)->journal_id);

        $this->reversePayment($id, $payment, 'Paid twice')->assertOk();
        $paymentReversal = AccountingJournal::query()->findOrFail($paymentJournal->refresh()->reversed_by_journal_id);

        $row = AuditLog::query()->where('action', 'expense_payment_reversed')->sole();
        $this->assertSame([AccountingExpense::class, $id, $this->admin->id, $this->branch->id], [$row->auditable_type, $row->auditable_id, $row->user_id, $row->branch_id]);
        $this->assertSame(
            "Reversed the payment of ₱4,000.00 made on Oct 5, 2026 to Meralco: {$paymentJournal->journal_no}, reversed by {$paymentReversal->journal_no}. Reason: Paid twice",
            $row->description,
        );
        $this->assertSameValues(['journal_id' => $paymentJournal->id, 'reversed_by_journal_id' => $paymentReversal->id, 'reason' => 'Paid twice'], $row->new_values);

        $this->reverseExpense($id, 'Recorded twice')->assertOk();
        $expenseJournal = AccountingJournal::query()->findOrFail(AccountingExpense::query()->findOrFail($id)->journal_id);

        $row = AuditLog::query()->where('action', 'expense_reversed')->sole();
        $this->assertSame([AccountingExpense::class, $id, $this->admin->id], [$row->auditable_type, $row->auditable_id, $row->user_id]);
        $this->assertSame(
            "Reversed the expense of ₱10,000.00 to Meralco dated Oct 1, 2026: {$expenseJournal->journal_no}, reversed by "
            .AccountingJournal::query()->findOrFail($expenseJournal->reversed_by_journal_id)->journal_no.'. Reason: Recorded twice',
            $row->description,
        );
        $this->assertSameValues(['journal_id' => $expenseJournal->id, 'reversed_by_journal_id' => $expenseJournal->reversed_by_journal_id, 'reason' => 'Recorded twice'], $row->new_values);

        // The Audit Trail reads both entries by their numbers.
        $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field');
        $this->assertSame($expenseJournal->journal_no, $changes['journal_id']['new'] ?? null);
    }

    /**
     * An audit row's values as stored: MySQL's JSON column keeps its keys in
     * its own order.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $actual
     */
    private function assertSameValues(array $expected, ?array $actual): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
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

    /** Pays the expense from Cash on Hand and returns the payment's id. */
    private function pay(int $expenseId, int $amount, string $date): int
    {
        $this->postJson("/api/accounting/expenses/{$expenseId}/pay", [
            'date' => $date, 'amount' => $amount, 'account_id' => $this->account('1010'),
        ])->assertOk();

        return (int) AccountingExpensePayment::query()->where('accounting_expense_id', $expenseId)->max('id');
    }

    private function reversePayment(int $expenseId, int $paymentId, string $reason = 'Paid twice'): TestResponse
    {
        return $this->postJson("/api/accounting/expenses/{$expenseId}/payments/{$paymentId}/reverse", ['reason' => $reason]);
    }

    private function reverseExpense(int $expenseId, string $reason = 'Recorded in error'): TestResponse
    {
        return $this->postJson("/api/accounting/expenses/{$expenseId}/reverse", ['reason' => $reason]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo(['expenses:view', ...$permissions]);

        return $user;
    }

    /**
     * Cash on Hand, Accounts Payable and the expense account, each as debits
     * less credits over the entries in the books.
     *
     * @return array<string, int>
     */
    private function balances(): array
    {
        $balances = [];

        foreach (['1010', '2010', '5030'] as $code) {
            $balances[$code] = (int) DB::table('accounting_journal_lines')
                ->join('accounting_journals', 'accounting_journals.id', '=', 'accounting_journal_lines.accounting_journal_id')
                ->whereIn('accounting_journals.status', AccountingJournal::HISTORICAL_STATUSES)
                ->where('accounting_journal_lines.accounting_account_id', $this->account($code))
                ->selectRaw('COALESCE(SUM(accounting_journal_lines.debit), 0) - COALESCE(SUM(accounting_journal_lines.credit), 0) as balance')
                ->value('balance');
        }

        return $balances;
    }
}
