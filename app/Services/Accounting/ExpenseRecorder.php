<?php

namespace App\Services\Accounting;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording a cost, and settling it later.
 *
 * ## The three rules this implements, and where they come from
 *
 * The journal shapes are NOT invented here. They are `expense_cash`,
 * `expense_accrual` and `payable_payment` in
 * `src/lib/accounting/posting-rules.ts`, which is the one written statement of
 * what each lending event posts, is unit-tested there, and is what the UI uses
 * to preview an entry before it exists. This class's job is to decide WHICH of
 * the three applies and to get the amounts right; the debits and credits are
 * transcribed:
 *
 * - paid on the spot → `expense_cash`   Dr expense account  Cr money account
 * - owed             → `expense_accrual` Dr expense account  Cr accounts payable
 * - settling it      → `payable_payment` Dr accounts payable Cr money account
 *
 * The automatic posting engine owns those three rules as a rule SET. When it
 * lands, the three `postImmediately` calls below should collapse into calls on
 * it and this class should keep only the lifecycle — which row to write, what
 * `amount_paid` becomes, whether a payment is even allowed. The account
 * resolution and the fail-closed behaviour move with them.
 *
 * ## One deliberate divergence from the rule set
 *
 * `expense_cash` and `payable_payment` resolve their money account through
 * `settlementAccountId(method, mapping)` — one of the four roles `cash`,
 * `gcash`, `maya`, `bank`. This class takes an ACCOUNT ID instead, because the
 * expense dialog and the pay form both let the user pick from the real money
 * accounts on the Cash & Bank screen, and an organisation with three bank
 * accounts has one `bank` role and three places the money could have come from.
 * The journal is the same shape either way; only the way the account is named
 * differs, and naming it explicitly is strictly more truthful.
 *
 * ## Money is integer centavos
 *
 * Every amount crossing this boundary is an integer number of centavos.
 * ₱15,000.50 is 1500050. Nothing here divides, so nothing here can produce a
 * fraction of a centavo.
 */
class ExpenseRecorder
{
    use ResolvesPostingAccounts;

    public function __construct(private JournalPoster $poster) {}

    /**
     * Records a cost and posts its journal in the same transaction.
     *
     * The two are one operation. An expense row written without its journal is
     * a cost that appears on the Expenses screen, is added into the headline
     * "Outstanding" figure, and is absent from the income statement, the trial
     * balance and every report built on them — with nothing anywhere to point
     * at the difference. So if the journal cannot be written, the expense is
     * not recorded either, and the caller is told why.
     *
     * @param  array{
     *     date: string,
     *     payee: string,
     *     expense_account_id: int,
     *     amount: int,
     *     payment_account_id?: int|null,
     *     branch_id?: int|null,
     *     reference?: string|null,
     *     description?: string|null,
     *     due_date?: string|null,
     * }  $data
     */
    public function record(array $data, ?int $userId = null): AccountingExpense
    {
        $paymentAccountId = $data['payment_account_id'] ?? null;
        $amount = (int) $data['amount'];

        return DB::transaction(function () use ($data, $paymentAccountId, $amount, $userId): AccountingExpense {
            // Resolved BEFORE anything is written. Inside a transaction the
            // rollback would undo the row anyway; doing it in this order means
            // the failure is about the mapping rather than about a half-built
            // expense, and the message says so.
            $expenseAccount = $this->requireExpenseAccount(
                (int) $data['expense_account_id'],
                'expense_account_id',
            );

            $creditAccount = $paymentAccountId !== null
                ? $this->requireMoneyAccount((int) $paymentAccountId, 'payment_account_id')
                : $this->requirePostingRole('accounts_payable', 'An expense recorded as owed');

            $paidOnTheSpot = $paymentAccountId !== null;

            $expense = AccountingExpense::create([
                'date' => $data['date'],
                'payee' => $data['payee'],
                'expense_account_id' => $expenseAccount->id,
                'amount' => $amount,
                // Paid on the spot means settled in full the moment it exists —
                // the `expense_cash` entry credits the money account directly,
                // so there is no payable left behind to settle.
                'amount_paid' => $paidOnTheSpot ? $amount : 0,
                'payment_account_id' => $paidOnTheSpot ? $creditAccount->id : null,
                'branch_id' => $data['branch_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                // A due date on something already paid is meaningless, and the
                // dialog does not send one. Dropped rather than stored so it
                // cannot later make a settled expense read as overdue.
                'due_date' => $paidOnTheSpot ? null : ($data['due_date'] ?? null),
                'settlement_status' => $paidOnTheSpot ? 'paid' : 'unpaid',
                'created_by' => $userId,
            ]);

            $journal = $this->poster->postImmediately(
                [
                    'date' => $expense->date->toDateString(),
                    // `expense` for a cash expense, `payable` for an accrual —
                    // the sources the two rules carry. They differ, which is
                    // what lets the books be read back as "what we spent" and
                    // "what we took on as a liability" separately.
                    'source' => $paidOnTheSpot ? 'expense' : 'payable',
                    'reference' => $expense->reference,
                    'description' => ($paidOnTheSpot ? 'Expense paid' : 'Expense accrued').' — '.$expense->payee,
                    'branch_id' => $expense->branch_id,
                    'postable_type' => AccountingExpense::class,
                    'postable_id' => $expense->id,
                ],
                [
                    ['account_id' => $expenseAccount->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $amount],
                ],
                $userId,
            );

            $expense->journal_id = $journal->id;
            $expense->save();

            return $expense->refresh();
        });
    }

    /**
     * Settles part or all of an accrued expense.
     *
     * Takes a row lock first. Two people paying the same bill at once would
     * otherwise both read `amount_paid = 0`, both pass the "is there anything
     * left to pay" check, and both post — paying the supplier twice, in the
     * books and out of the cash account. The CHECK constraint would catch the
     * second write only if the two amounts happened to exceed the total.
     *
     * @param  array{date: string, amount: int, account_id: int}  $data
     */
    public function pay(AccountingExpense $expense, array $data, ?int $userId = null): AccountingExpense
    {
        $amount = (int) $data['amount'];

        return DB::transaction(function () use ($expense, $data, $amount, $userId): AccountingExpense {
            $locked = AccountingExpense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->wasPaidOnTheSpot()) {
                throw ValidationException::withMessages([
                    'expense' => [
                        "This expense was paid from {$locked->paymentAccount?->name} when it was recorded, so "
                        .'there is no payable to settle. Paying it again would credit the money account a second '
                        .'time against a liability that was never booked.',
                    ],
                ]);
            }

            $outstanding = $locked->outstanding();

            if ($outstanding <= 0) {
                throw ValidationException::withMessages([
                    'expense' => ['This expense is already settled in full.'],
                ]);
            }

            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'That is more than is outstanding. '.Money::format($outstanding).' is owed on this expense, '
                        .'and paying more would leave a negative balance that subtracts from the Outstanding total '
                        .'on the Expenses screen.',
                    ],
                ]);
            }

            $moneyAccount = $this->requireMoneyAccount((int) $data['account_id'], 'account_id');
            $payableAccount = $this->requirePostingRole('accounts_payable', 'Settling a payable');

            $payment = AccountingExpensePayment::create([
                'accounting_expense_id' => $locked->id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_account_id' => $moneyAccount->id,
                'created_by' => $userId,
            ]);

            // `payable_payment`: Dr Accounts Payable, Cr the money account. NO
            // expense line — the cost was recognised on accrual, and debiting
            // it again here would report the same spending twice.
            //
            // The postable is the PAYMENT, not the expense. Both this rule and
            // `expense_accrual` post with `source = 'payable'`, and the poster
            // is idempotent on (postable_type, postable_id, source): anchored to
            // the expense, this call would find the accrual's journal already
            // there and hand it back as though the payment had posted. Nothing
            // would fail, and no money would ever leave the cash account.
            $journal = $this->poster->postImmediately(
                [
                    'date' => $payment->date->toDateString(),
                    'source' => 'payable',
                    'reference' => $locked->reference,
                    'description' => 'Payable settled — '.$locked->payee,
                    'branch_id' => $locked->branch_id,
                    'postable_type' => AccountingExpensePayment::class,
                    'postable_id' => $payment->id,
                ],
                [
                    ['account_id' => $payableAccount->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $moneyAccount->id, 'debit' => 0, 'credit' => $amount],
                ],
                $userId,
            );

            $payment->journal_id = $journal->id;
            $payment->save();

            $paid = $locked->amount_paid + $amount;

            $locked->amount_paid = $paid;
            $locked->settlement_status = $paid >= $locked->amount ? 'paid' : 'partially_paid';
            $locked->save();

            return $locked->refresh();
        });
    }
}
