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
 * resource reads `reversible: false` with the reason. The reversal Savings
 * posts of its own entry (source `reversal`) is held the same way, as
 * YearEndCloser::assertNotPartOfAClose() holds a closing entry's reversal:
 * reversing it would put the savings entry back in the books without its
 * savings record. Every other source is left as it was.
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

    /**
     * Why the journal screen can't reverse this entry, or null when its source
     * doesn't stop it: a savings entry, or the reversal of one.
     *
     * A reversal reads the entry it reverses from the loaded `reverses`
     * relation when that carries `source` (the register and the journal
     * routes load it), else with one query.
     */
    public static function reasonNotReversible(AccountingJournal $journal): ?string
    {
        if (self::isFromSavings($journal->source)) {
            return self::REFUSAL;
        }

        if ($journal->source !== 'reversal' || $journal->reverses_journal_id === null) {
            return null;
        }

        return self::isFromSavings(self::sourceOfReversed($journal)) ? self::REFUSAL : null;
    }

    private static function sourceOfReversed(AccountingJournal $journal): ?string
    {
        $reversed = $journal->relationLoaded('reverses') ? $journal->getRelation('reverses') : null;

        if ($reversed instanceof AccountingJournal && array_key_exists('source', $reversed->getAttributes())) {
            return $reversed->source;
        }

        return AccountingJournal::query()->whereKey($journal->reverses_journal_id)->value('source');
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
