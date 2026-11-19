<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;

/**
 * Whether the books can be read as a position yet (Phase 6, 2026-11-17
 * decision 11), for the executive dashboard's book figures and controls:
 *
 * - `books_not_set_up` ("Books not set up"): there is no chart of accounts,
 *   so there are no books (AccountMap::chartExists()).
 * - `requires_configuration` ("Requires configuration"): there is a chart, but
 *   the opening balances have not been finalised, so balance-sheet figures and
 *   book controls would read the books without what they started from.
 * - `ready`: the opening balances were finalised on the Opening Balances
 *   screen, or posted as an `opening_balance` journal from the command line
 *   (`accounting:opening-balances`).
 *
 * Reads only.
 */
final class BooksState
{
    public const READY = 'ready';

    public const REQUIRES_CONFIGURATION = 'requires_configuration';

    public const BOOKS_NOT_SET_UP = 'books_not_set_up';

    /**
     * @return array{state: string, label: string, reason: string|null}
     */
    public static function current(): array
    {
        if (! AccountMap::chartExists()) {
            return [
                'state' => self::BOOKS_NOT_SET_UP,
                'label' => 'Books not set up',
                'reason' => 'No chart of accounts has been set up.',
            ];
        }

        if (! self::openingBalancesFinalised()) {
            return [
                'state' => self::REQUIRES_CONFIGURATION,
                'label' => 'Requires configuration',
                'reason' => 'The opening balances have not been finalised yet.',
            ];
        }

        return ['state' => self::READY, 'label' => 'Ready', 'reason' => null];
    }

    public static function isReady(): bool
    {
        return self::current()['state'] === self::READY;
    }

    private static function openingBalancesFinalised(): bool
    {
        return AccountingOpeningBalance::query()->where('status', AccountingOpeningBalance::STATUS_FINALISED)->exists()
            || AccountingJournal::query()
                ->where('source', OpeningBalancePoster::SOURCE)
                ->where('status', 'posted')
                ->exists();
    }
}
