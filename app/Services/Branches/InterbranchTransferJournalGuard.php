<?php

namespace App\Services\Branches;

use App\Models\AccountingJournal;
use App\Models\InterbranchTransfer;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * A journal an interbranch transfer posted (either leg, or the reversal of
 * one) is never reversed from the journal screen: reversing it alone would
 * leave the transfer reading "in transit" or "received" while the books say
 * otherwise. The transfer's own reverse step does it, with both legs. So
 * `POST /accounting/journals/{id}/reverse` refuses it (422) and the journal
 * resource reads `reversible: false` with the reason, as SavingsJournalGuard
 * does for savings entries.
 *
 * Transfer journals carry no postable (the transfer number is their
 * reference), so they are found through the transfer's four journal columns:
 * one query per page of journals (self::preload()), never one per row.
 */
final class InterbranchTransferJournalGuard
{
    public const REVERSE_THE_TRANSFER = 'This entry comes from an interbranch transfer. Reverse the transfer instead, so the transfer and the books stay the same.';

    /**
     * The reason per journal, or false when no transfer posted it: a null
     * entry would read as missing to offsetExists() and cost a query a row.
     *
     * @var WeakMap<AccountingJournal, string|false>|null
     */
    private static ?WeakMap $reasons = null;

    /** Why the journal screen can't reverse this entry, or null when no transfer posted it. */
    public static function reasonNotReversible(AccountingJournal $journal): ?string
    {
        $reasons = self::reasons();

        if (! $reasons->offsetExists($journal)) {
            self::preload([$journal]);
        }

        return $reasons[$journal] ?: null;
    }

    /** @throws ValidationException 422 for a transfer's journal */
    public static function assertReversibleFromJournals(AccountingJournal $journal): void
    {
        $reason = self::reasonNotReversible($journal);

        if ($reason !== null) {
            throw ValidationException::withMessages(['journal' => [$reason]]);
        }
    }

    /**
     * Works out the reason for every journal given, in one query over the
     * `transfer` journals among them.
     *
     * @param  iterable<AccountingJournal>  $journals
     */
    public static function preload(iterable $journals): void
    {
        $journals = collect($journals);
        $ids = $journals
            ->filter(fn (AccountingJournal $journal): bool => in_array($journal->source, ['transfer', 'reversal'], true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $linked = $ids === [] ? [] : InterbranchTransfer::query()
            ->where(function ($query) use ($ids): void {
                foreach (InterbranchTransfer::JOURNAL_COLUMNS as $column) {
                    $query->orWhereIn($column, $ids);
                }
            })
            ->get(InterbranchTransfer::JOURNAL_COLUMNS)
            ->flatMap(fn (InterbranchTransfer $transfer): array => array_values($transfer->only(InterbranchTransfer::JOURNAL_COLUMNS)))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        $reasons = self::reasons();

        foreach ($journals as $journal) {
            $reasons[$journal] = isset($linked[(int) $journal->id]) ? self::REVERSE_THE_TRANSFER : false;
        }
    }

    /** @return WeakMap<AccountingJournal, string|false> */
    private static function reasons(): WeakMap
    {
        return self::$reasons ??= new WeakMap;
    }
}
