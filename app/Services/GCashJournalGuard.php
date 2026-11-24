<?php

namespace App\Services;

use App\Models\AccountingJournal;
use Illuminate\Validation\ValidationException;

/**
 * A GCash Cash In or Cash Out journal is never reversed from the journal
 * screen (finish run round 2, from the #368 money review). Each mirrors a
 * GCash transaction, and the GCash income report counts that transaction's
 * charge, so reversing the journal alone would take the charge off 4090 GCash
 * Service Income while the report still counts it. GCash has no reversal of
 * its own, so `POST /accounting/journals/{id}/reverse` refuses it (422) and the
 * journal resource reads `reversible: false` with the reason, as
 * SavingsJournalGuard does for Savings entries. A correcting journal entry
 * fixes a wrong one.
 *
 * Only the entries a GCash transaction posts: `cash_in` and `cash_out`
 * (AutomaticPoster::gcashTransaction()). `gcash` is PostingRules::walletCharge(),
 * a wallet's own fee, which nothing writes and the report doesn't count.
 *
 * Unlike a Savings entry's, the reversal of a GCash entry stays reversible:
 * GCash reverses nothing, so one can only have been made by hand before this
 * guard, and reversing it puts the entry back in step with GCash.
 */
final class GCashJournalGuard
{
    /** The journal sources a GCash transaction posts. */
    public const SOURCES = ['cash_in', 'cash_out'];

    public const NO_REVERSAL = 'This entry comes from a GCash Cash In or Cash Out and can\'t be reversed, so the GCash records and the books stay the same. To fix it, record a correcting journal entry.';

    /** Why the journal screen can't reverse this entry, or null when GCash didn't post it. */
    public static function reasonNotReversible(AccountingJournal $journal): ?string
    {
        return in_array($journal->source, self::SOURCES, true) ? self::NO_REVERSAL : null;
    }

    /** @throws ValidationException 422 for a GCash entry */
    public static function assertReversibleFromJournals(AccountingJournal $journal): void
    {
        $reason = self::reasonNotReversible($journal);

        if ($reason !== null) {
            throw ValidationException::withMessages(['journal' => [$reason]]);
        }
    }
}
