<?php

namespace App\Services\Accounting;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Services\Branches\InterbranchTransferJournalGuard;
use App\Services\GCashJournalGuard;
use App\Services\Savings\SavingsJournalGuard;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * An expense's journal and each of its payments' journals are reversed from
 * Expenses & Payables, never by hand on the Journals screen (finish run round
 * 5), as Savings ({@see SavingsJournalGuard}), interbranch transfer
 * ({@see InterbranchTransferJournalGuard}) and GCash ({@see GCashJournalGuard})
 * entries are. Reversing the journal alone left the expense and the books
 * disagreeing: a partly paid expense reversed that way kept its payments
 * standing, so Accounts Payable went below zero. Expenses & Payables reverses
 * a payment, or the expense once none of its payments stands, under the
 * expense's locks and with an audit row (ExpenseRecorder). So `POST
 * /accounting/journals/{id}/reverse` refuses it (422) and the journal
 * resource reads `reversible: false` with the reason.
 *
 * The reversal of one is refused too, however far down its chain: reversing
 * it would put a payment or a cost back in the books without Expenses &
 * Payables, which reverses no reversal. Hand reversals made before this stay
 * as they are; the Reversed and paid amount rules read them
 * (AccountingExpense::isReversed(), AccountingExpensePayment::isReversed()).
 *
 * An entry is found by its postable, which ExpenseRecorder sets to the
 * expense or the payment, and a reversal (which has none) by the first entry
 * of its chain. A page of journals is read in one go (self::preload(), run by
 * AccountingJournal::scopeWithRegisterRelations()): no query for an entry or
 * a reversal whose loaded `reverses` names its document, and one per level of
 * a longer chain, never one per row.
 */
final class ExpenseJournalGuard
{
    public const REVERSE_IN_EXPENSES = 'This entry comes from Expenses & Payables. Reverse it from Expenses & Payables, so the expense and the books stay the same.';

    public const NO_REVERSAL = 'This entry reverses an expense or an expense payment and can\'t be reversed, so the expense and the books stay the same. If it is still owed or was paid, record it again in Expenses & Payables.';

    /** The documents whose journals Expenses & Payables reverses. */
    private const POSTABLES = [AccountingExpense::class, AccountingExpensePayment::class];

    /**
     * The reason per journal, or false when Expenses & Payables doesn't own
     * it: a null entry would read as missing to offsetExists() and cost a
     * query a row.
     *
     * @var WeakMap<AccountingJournal, string|false>|null
     */
    private static ?WeakMap $reasons = null;

    /** Why the journal screen can't reverse this entry, or null when it isn't an expense's. */
    public static function reasonNotReversible(AccountingJournal $journal): ?string
    {
        $reasons = self::reasons();

        if (! $reasons->offsetExists($journal)) {
            self::preload([$journal]);
        }

        return $reasons[$journal] ?: null;
    }

    /** @throws ValidationException 422 for an expense's or a payment's journal, or the reversal of one */
    public static function assertReversibleFromJournals(AccountingJournal $journal): void
    {
        $reason = self::reasonNotReversible($journal);

        if ($reason !== null) {
            throw ValidationException::withMessages(['journal' => [$reason]]);
        }
    }

    /**
     * Works out the reason for every journal given. A reversal's original is
     * read from its loaded `reverses` relation when it carries `source` and
     * `postable_type`; otherwise, and for each further level of a chain of
     * reversals, one query over the page.
     *
     * @param  iterable<AccountingJournal>  $journals
     */
    public static function preload(iterable $journals): void
    {
        $reasons = self::reasons();
        /** @var list<array{0: AccountingJournal, 1: int}> $pending a reversal, and the next entry up its chain to read */
        $pending = [];

        foreach ($journals as $journal) {
            if (self::fromExpenses($journal->postable_type)) {
                $reasons[$journal] = self::REVERSE_IN_EXPENSES;

                continue;
            }

            if ($journal->source !== 'reversal' || $journal->reverses_journal_id === null) {
                $reasons[$journal] = false;

                continue;
            }

            $original = $journal->relationLoaded('reverses') ? $journal->getRelation('reverses') : null;

            if ($original instanceof AccountingJournal && array_diff(['source', 'postable_type'], array_keys($original->getAttributes())) === []) {
                if (self::fromExpenses($original->postable_type)) {
                    $reasons[$journal] = self::NO_REVERSAL;

                    continue;
                }

                if ($original->source !== 'reversal') {
                    $reasons[$journal] = false;

                    continue;
                }
            }

            $pending[] = [$journal, (int) $journal->reverses_journal_id];
        }

        for ($level = 0; $pending !== [] && $level < AccountingJournal::MAX_REVERSALS; $level++) {
            $entries = AccountingJournal::query()
                ->whereIn('id', array_values(array_unique(array_column($pending, 1))))
                ->get(['id', 'source', 'postable_type', 'reverses_journal_id'])
                ->keyBy('id');
            $next = [];

            foreach ($pending as [$journal, $entryId]) {
                $entry = $entries->get($entryId);

                if ($entry !== null && self::fromExpenses($entry->postable_type)) {
                    $reasons[$journal] = self::NO_REVERSAL;
                } elseif ($entry !== null && $entry->source === 'reversal' && $entry->reverses_journal_id !== null) {
                    $next[] = [$journal, (int) $entry->reverses_journal_id];
                } else {
                    $reasons[$journal] = false;
                }
            }

            $pending = $next;
        }

        // A chain longer than any real one: not an expense's, as far as it was read.
        foreach ($pending as [$journal]) {
            $reasons[$journal] = false;
        }
    }

    private static function fromExpenses(?string $postableType): bool
    {
        return in_array($postableType, self::POSTABLES, true);
    }

    /** @return WeakMap<AccountingJournal, string|false> */
    private static function reasons(): WeakMap
    {
        return self::$reasons ??= new WeakMap;
    }
}
