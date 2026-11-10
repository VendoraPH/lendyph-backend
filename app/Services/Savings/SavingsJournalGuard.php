<?php

namespace App\Services\Savings;

use App\Models\AccountingJournal;
use Illuminate\Validation\ValidationException;

/**
 * Savings journals are reversed only from Savings (Phase 3, S5). Each one
 * mirrors a savings record (a transaction, an interest run or a time
 * deposit), and Savings reverses the two together (Reverser, RunReverser).
 * Reversing the journal alone from the journal screen would leave the
 * savings records and the books disagreeing, so `POST
 * /accounting/journals/{id}/reverse` refuses it (422) and the journal
 * resource reads `reversible: false` with the reason. Every other source is
 * left as it was.
 */
final class SavingsJournalGuard
{
    /** The journal sources Savings posts (AutomaticPoster). */
    public const SOURCES = ['savings', 'savings_interest', 'time_deposit', 'time_deposit_settlement'];

    public const REFUSAL = 'This entry comes from Savings. Reverse it there, so the savings records and the books stay the same.';

    public static function isFromSavings(?string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    /** Why the journal screen can't reverse this entry, or null when the source doesn't stop it. */
    public static function reasonNotReversible(AccountingJournal $journal): ?string
    {
        return self::isFromSavings($journal->source) ? self::REFUSAL : null;
    }

    /** @throws ValidationException 422 for a journal Savings posted */
    public static function assertReversibleFromJournals(AccountingJournal $journal): void
    {
        $reason = self::reasonNotReversible($journal);

        if ($reason !== null) {
            throw ValidationException::withMessages(['journal' => [$reason]]);
        }
    }
}
