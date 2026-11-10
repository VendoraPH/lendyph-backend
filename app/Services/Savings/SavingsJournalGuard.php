<?php

namespace App\Services\Savings;

use App\Models\AccountingJournal;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * Savings journals are never reversed from the journal screen (Phase 3, S5).
 * Each one mirrors a savings record, and reversing the journal alone would
 * leave the savings records and the books disagreeing. So `POST
 * /accounting/journals/{id}/reverse` refuses it (422), and the journal
 * resource reads `reversible: false` with the reason, in one of two ways:
 *
 * - **Savings can reverse it** (a deposit or a withdrawal, source `savings`;
 *   an interest run, `savings_interest` on a run; transfers post nothing):
 *   self::REVERSE_IN_SAVINGS. Savings reverses the record and its journal
 *   together (Reverser, RunReverser).
 * - **Nothing can reverse it** (a time deposit placement or settlement; an
 *   account-closing payout, `savings` on a `closing` row; the interest
 *   credited at closing, `savings_interest` on a transaction):
 *   self::NO_REVERSAL. A correcting journal entry fixes the cash or bank side.
 *
 * The reversal of either kind (source `reversal`) gets its original's reason,
 * as YearEndCloser::assertNotPartOfAClose() holds a closing entry's reversal:
 * reversing it would put the savings entry back in the books without its
 * savings record. Every other source is left as it was.
 *
 * A page of journals is read in one go (self::preload(), run by
 * AccountingJournal::scopeWithRegisterRelations()): at most one query, for
 * the savings rows' types, never one per row.
 */
final class SavingsJournalGuard
{
    /** The journal sources Savings posts (AutomaticPoster). */
    public const SOURCES = ['savings', 'savings_interest', 'time_deposit', 'time_deposit_settlement'];

    public const REVERSE_IN_SAVINGS = 'This entry comes from Savings. Reverse it there, so the savings records and the books stay the same.';

    public const NO_REVERSAL = 'This entry comes from a time deposit or an account closing and can\'t be reversed. To fix the cash or bank side, record a correcting journal entry.';

    /** @var WeakMap<AccountingJournal, string|null>|null */
    private static ?WeakMap $reasons = null;

    public static function isFromSavings(?string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    /** Why the journal screen can't reverse this entry, or null when its source doesn't stop it. */
    public static function reasonNotReversible(AccountingJournal $journal): ?string
    {
        $reasons = self::reasons();

        if (! $reasons->offsetExists($journal)) {
            self::preload(collect([$journal]));
        }

        return $reasons[$journal];
    }

    /** @throws ValidationException 422 for a savings entry or the reversal of one */
    public static function assertReversibleFromJournals(AccountingJournal $journal): void
    {
        $reason = self::reasonNotReversible($journal);

        if ($reason !== null) {
            throw ValidationException::withMessages(['journal' => [$reason]]);
        }
    }

    /**
     * Works out the reason for every journal given, reading a reversal's
     * original from its loaded `reverses` relation (with `source`,
     * `postable_type` and `postable_id`) when it carries them, and the type of
     * every savings transaction they name in one query.
     *
     * @param  iterable<AccountingJournal>  $journals
     */
    public static function preload(iterable $journals): void
    {
        $origins = collect($journals)->mapWithKeys(fn (AccountingJournal $journal) => [spl_object_id($journal) => [$journal, self::originOf($journal)]]);

        $transactionIds = $origins
            ->map(fn (array $pair) => $pair[1])
            ->filter(fn (?array $origin) => $origin !== null && $origin['source'] === 'savings' && $origin['postable_type'] === SavingsTransaction::class && $origin['postable_id'] !== null)
            ->map(fn (array $origin) => (int) $origin['postable_id'])
            ->unique()
            ->values();

        /** @var Collection<int, string> $types */
        $types = $transactionIds->isEmpty()
            ? collect()
            : SavingsTransaction::query()->whereIn('id', $transactionIds)->pluck('type', 'id');

        $reasons = self::reasons();

        foreach ($origins as [$journal, $origin]) {
            $reasons[$journal] = $origin === null ? null : self::reasonFor($origin, $types);
        }
    }

    /**
     * The savings entry this journal stands for: itself, or the entry a
     * reversal reverses. Null for anything else.
     *
     * @return array{source: string, postable_type: string|null, postable_id: int|string|null}|null
     */
    private static function originOf(AccountingJournal $journal): ?array
    {
        if (self::isFromSavings($journal->source)) {
            return self::originFields($journal);
        }

        if ($journal->source !== 'reversal' || $journal->reverses_journal_id === null) {
            return null;
        }

        $reversed = $journal->relationLoaded('reverses') ? $journal->getRelation('reverses') : null;

        if (! $reversed instanceof AccountingJournal || array_diff(['source', 'postable_type', 'postable_id'], array_keys($reversed->getAttributes())) !== []) {
            $reversed = AccountingJournal::query()->whereKey($journal->reverses_journal_id)->first(['id', 'source', 'postable_type', 'postable_id']);
        }

        return $reversed !== null && self::isFromSavings($reversed->source) ? self::originFields($reversed) : null;
    }

    /** @return array{source: string, postable_type: string|null, postable_id: int|string|null} */
    private static function originFields(AccountingJournal $journal): array
    {
        return ['source' => (string) $journal->source, 'postable_type' => $journal->postable_type, 'postable_id' => $journal->postable_id];
    }

    /**
     * @param  array{source: string, postable_type: string|null, postable_id: int|string|null}  $origin
     * @param  Collection<int, string>  $types
     */
    private static function reasonFor(array $origin, Collection $types): string
    {
        return match ($origin['source']) {
            'savings' => $types->get((int) $origin['postable_id']) === 'closing' ? self::NO_REVERSAL : self::REVERSE_IN_SAVINGS,
            'savings_interest' => $origin['postable_type'] === SavingsInterestRun::class ? self::REVERSE_IN_SAVINGS : self::NO_REVERSAL,
            default => self::NO_REVERSAL,
        };
    }

    /** @return WeakMap<AccountingJournal, string|null> */
    private static function reasons(): WeakMap
    {
        return self::$reasons ??= new WeakMap;
    }
}
