<?php

namespace App\Services\Accounting;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Services\AuditLogService;
use App\Services\Branches\BranchRowLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
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
            // The branch row first (BranchRowLock's lock order): a closed or
            // closing branch takes no new expense.
            BranchRowLock::lock(['branch_id' => $data['branch_id'] ?? null]);

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
     * otherwise both read what is paid so far, both pass the "is there
     * anything left to pay" check, and both post — paying the supplier twice,
     * in the books and out of the cash account.
     *
     * What is paid so far is the payments whose journal stands
     * (AccountingExpense::paidAmount()), read under the locks a reversal takes
     * (self::lockTheJournals()), so a payment never counts one whose reversal
     * is in flight. The stored `amount_paid` and `settlement_status` are
     * written as what stands after this payment, which keeps them within
     * their CHECK when a reversed payment's amount is paid again; no figure
     * reads them.
     *
     * @param  array{date: string, amount: int, account_id: int}  $data
     */
    public function pay(AccountingExpense $expense, array $data, ?int $userId = null): AccountingExpense
    {
        $amount = (int) $data['amount'];

        return DB::transaction(function () use ($expense, $data, $amount, $userId): AccountingExpense {
            // The branch row before the expense row (BranchRowLock's lock
            // order): a closed or closing branch's payable isn't paid. An
            // expense's branch never changes.
            BranchRowLock::lock(['branch' => $expense->branch_id]);

            $locked = $this->lockTheExpense($expense);
            $payments = $this->lockThePayments($locked);
            $reversed = $this->lockTheJournals($locked, $payments);

            // A reversed expense owes nothing: its payable was undone in the
            // books, so a payment would pay out cash against nothing (finish
            // run round 4). Refused before anything is written.
            if ($locked->journal_id !== null && $reversed[$locked->journal_id]) {
                throw ValidationException::withMessages([
                    'expense' => ["This expense was reversed, so it can't be paid. Record it again if it's still owed."],
                ]);
            }

            if ($locked->wasPaidOnTheSpot()) {
                throw ValidationException::withMessages([
                    'expense' => [
                        "This expense was paid from {$locked->paymentAccount?->name} when it was recorded, so "
                        .'there is no payable to settle. Paying it again would credit the money account a second '
                        .'time against a liability that was never booked.',
                    ],
                ]);
            }

            $paid = self::standingTotal($payments, $reversed);
            $outstanding = $locked->amount - $paid;

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

            $this->storePaid($locked, $paid + $amount);

            return $locked->refresh();
        });
    }

    /**
     * Reverses one payment from Expenses & Payables (finish run round 5): its
     * journal's mirror is posted through JournalPoster::reverse(), the
     * Journals screen's own path, so the period and date rules apply, and an
     * audit row says so in words. The payment then no longer counts as paid
     * (AccountingExpensePayment::isReversed()), so what it paid is owed again.
     * A payment on an expense whose own journal was reversed by hand before
     * can be reversed too: that puts back the cash it paid against a payable
     * that no longer exists.
     *
     * Under the locks paying takes, in the same order, so a payment and a
     * reversal on one expense, or two reversals, never interleave. Refused
     * (422 in words) when the payment already stands reversed or has no
     * journal.
     */
    public function reversePayment(AccountingExpense $expense, AccountingExpensePayment $payment, string $reason, ?int $userId = null): AccountingExpense
    {
        return DB::transaction(function () use ($expense, $payment, $reason, $userId): AccountingExpense {
            BranchRowLock::lock(['branch' => $expense->branch_id]);

            $locked = $this->lockTheExpense($expense);
            $payments = $this->lockThePayments($locked);
            $reversed = $this->lockTheJournals($locked, $payments);
            $target = $payments->firstWhere('id', $payment->id) ?? $payment;

            if ($target->journal_id === null) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment has no journal entry to reverse.'],
                ]);
            }

            if ($reversed[$target->journal_id] ?? false) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment was already reversed.'],
                ]);
            }

            $journal = AccountingJournal::query()->findOrFail($target->journal_id);
            $reversal = $this->poster->reverse($journal, self::reversalDate($journal), $reason, $userId);
            $reversed[$target->journal_id] = true;

            $this->storePaid($locked, self::standingTotal($payments, $reversed));

            AuditLogService::log(
                'expense_payment_reversed',
                $locked,
                // The entry reversed and its reversal, which the Audit Trail
                // reads by their numbers, and the reason.
                newValues: ['journal_id' => $journal->id, 'reversed_by_journal_id' => $reversal->id, 'reason' => $reason],
                description: sprintf(
                    'Reversed the payment of %s made on %s to %s: %s, reversed by %s. Reason: %s',
                    Money::format((int) $target->amount),
                    self::dateInWords($target->date->toDateString()),
                    $locked->payee,
                    $journal->journal_no,
                    $reversal->journal_no,
                    $reason,
                ),
                userId: $userId,
            );

            return $locked->refresh();
        });
    }

    /**
     * Reverses an expense from Expenses & Payables (finish run round 5): its
     * own journal's mirror is posted through JournalPoster::reverse(), as for
     * a payment, and audited. The expense then reads Reversed and counts in
     * no total (AccountingExpense::isReversed()). An expense paid on the spot
     * gets its cash back the same way, since its journal is the payment.
     *
     * Refused (422 in words) while any of its payments stands: reversing the
     * accrual alone would leave the payment against a payable that no longer
     * exists, and Accounts Payable below zero. Also refused when it already
     * stands reversed or has no journal. Under the locks paying takes.
     */
    public function reverse(AccountingExpense $expense, string $reason, ?int $userId = null): AccountingExpense
    {
        return DB::transaction(function () use ($expense, $reason, $userId): AccountingExpense {
            BranchRowLock::lock(['branch' => $expense->branch_id]);

            $locked = $this->lockTheExpense($expense);
            $payments = $this->lockThePayments($locked);
            $reversed = $this->lockTheJournals($locked, $payments);

            if ($locked->journal_id === null) {
                throw ValidationException::withMessages([
                    'expense' => ['This expense has no journal entry to reverse.'],
                ]);
            }

            if ($reversed[$locked->journal_id]) {
                throw ValidationException::withMessages([
                    'expense' => ['This expense was already reversed.'],
                ]);
            }

            $standing = self::standing($payments, $reversed);

            if ($standing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'expense' => [AccountingExpense::reversePaymentsFirst((int) $standing->sum('amount'), $standing->count())],
                ]);
            }

            $journal = AccountingJournal::query()->findOrFail($locked->journal_id);
            $reversal = $this->poster->reverse($journal, self::reversalDate($journal), $reason, $userId);

            AuditLogService::log(
                'expense_reversed',
                $locked,
                newValues: ['journal_id' => $journal->id, 'reversed_by_journal_id' => $reversal->id, 'reason' => $reason],
                description: sprintf(
                    'Reversed the expense of %s to %s dated %s: %s, reversed by %s. Reason: %s',
                    Money::format((int) $locked->amount),
                    $locked->payee,
                    self::dateInWords($locked->date->toDateString()),
                    $journal->journal_no,
                    $reversal->journal_no,
                    $reason,
                ),
                userId: $userId,
            );

            return $locked->refresh();
        });
    }

    /**
     * Saves an expense's descriptive fields, refused (422 in words) once its
     * journal stands reversed (finish run round 5): the cost was undone, and
     * is recorded again if it's still owed. Under the expense row lock and its
     * journal's, as a reversal takes them, so an edit never lands on an
     * expense reversed in the meantime.
     *
     * @param  array{payee?: string, reference?: string|null, description?: string|null, due_date?: string|null}  $data
     */
    public function update(AccountingExpense $expense, array $data): AccountingExpense
    {
        return DB::transaction(function () use ($expense, $data): AccountingExpense {
            $locked = $this->lockTheExpense($expense);
            $reversed = $this->lockTheJournals($locked, new Collection);

            if ($locked->journal_id !== null && $reversed[$locked->journal_id]) {
                throw ValidationException::withMessages([
                    'expense' => ["This expense was reversed, so it can't be edited. Record it again if it's still owed."],
                ]);
            }

            $locked->update($data);

            return $locked->refresh();
        });
    }

    private function lockTheExpense(AccountingExpense $expense): AccountingExpense
    {
        return AccountingExpense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * The expense's payments, read FOR UPDATE after the expense row: every
     * one committed before this write, in id order.
     *
     * @return Collection<int, AccountingExpensePayment>
     */
    private function lockThePayments(AccountingExpense $locked): Collection
    {
        return AccountingExpensePayment::query()
            ->where('accounting_expense_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'accounting_expense_id', 'date', 'amount', 'journal_id']);
    }

    /**
     * Which of the expense's journal and its payments' journals stand
     * reversed (AccountingJournal::endsReversed()), read under FOR UPDATE
     * locks on each journal and every entry that reversed it, one level of
     * the chains at a time in id order.
     *
     * Those are the rows JournalPoster::reverse() locks. A reversal never
     * touches the expense row, so the expense row lock alone wouldn't stop a
     * hand reversal made before the Journals screen refused one, and the
     * branch lock is shared (and absent with no branch). This way a reversal
     * in flight finishes first and is seen here, or waits until this write
     * commits. No expense entry has a line on the allowance account (an
     * expense account against a money account or Accounts Payable), so
     * JournalPoster::reverse() takes no allowance lock behind these.
     *
     * @param  Collection<int, AccountingExpensePayment>  $payments
     * @return array<int, bool> journal id => stands reversed
     */
    private function lockTheJournals(AccountingExpense $locked, Collection $payments): array
    {
        $ids = array_values(array_unique(array_filter(
            [$locked->journal_id, ...$payments->pluck('journal_id')->all()],
            static fn (mixed $id): bool => $id !== null,
        )));
        $reversed = array_fill_keys($ids, false);
        $links = array_combine($ids, $ids);

        for ($reversals = 0; $links !== [] && $reversals < AccountingJournal::MAX_REVERSALS; $reversals++) {
            $rows = AccountingJournal::query()
                ->whereIn('id', array_values(array_unique($links)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status', 'reversed_by_journal_id'])
                ->keyBy('id');

            foreach ($links as $journalId => $linkId) {
                $row = $rows->get($linkId);

                if ($row === null || $row->status !== 'reversed' || $row->reversed_by_journal_id === null) {
                    unset($links[$journalId]);

                    continue;
                }

                $reversed[$journalId] = ! $reversed[$journalId];
                $links[$journalId] = (int) $row->reversed_by_journal_id;
            }
        }

        return $reversed;
    }

    /**
     * The payments whose journal stands (one with no journal does).
     *
     * @param  Collection<int, AccountingExpensePayment>  $payments
     * @param  array<int, bool>  $reversed
     * @return Collection<int, AccountingExpensePayment>
     */
    private static function standing(Collection $payments, array $reversed): Collection
    {
        return $payments->reject(static fn (AccountingExpensePayment $payment): bool => $payment->journal_id !== null && ($reversed[$payment->journal_id] ?? false))->values();
    }

    /**
     * What the standing payments paid, in centavos.
     *
     * @param  Collection<int, AccountingExpensePayment>  $payments
     * @param  array<int, bool>  $reversed
     */
    private static function standingTotal(Collection $payments, array $reversed): int
    {
        return (int) self::standing($payments, $reversed)->sum('amount');
    }

    /**
     * The stored settlement columns, as what stands after this write. No
     * figure reads them (AccountingExpense::paidAmount()); kept so the row
     * stays within its CHECK and its audit trail shows each change.
     */
    private function storePaid(AccountingExpense $locked, int $paid): void
    {
        $locked->amount_paid = $paid;
        $locked->settlement_status = match (true) {
            $paid >= $locked->amount => 'paid',
            $paid > 0 => 'partially_paid',
            default => 'unpaid',
        };
        $locked->save();
    }

    /**
     * Where a reversal lands: today in Manila, or the entry's own date when
     * that is later, since a reversal is never dated before the entry it
     * reverses (ReverseJournalRequest's rule) and the pair cancels out on the
     * entry's own date.
     */
    private static function reversalDate(AccountingJournal $journal): string
    {
        return max(CarbonImmutable::now()->toDateString(), $journal->date->toDateString());
    }

    /** "Oct 5, 2026". */
    private static function dateInWords(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('M j, Y');
    }
}
