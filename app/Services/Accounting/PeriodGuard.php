<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * What makes a closed period actually closed.
 *
 * A "closed" flag that only greys out a button is not a lock — it is a label,
 * and the entries it was supposed to stop go in anyway through the manual entry
 * screen, through an expense, through a fund transfer, and through every
 * automatic posting a loan release or collection raises. So this is consulted
 * inside {@see JournalPoster}, which is the ONE writer of journals in this
 * application, and every one of those paths therefore goes through it whether
 * its author thought about periods or not.
 *
 * ## An absent period does not lock
 *
 * `assertOpen()` on a date with no period row succeeds. Periods are provisioned
 * by the Period Closing screen, and a co-op that has never opened it must not
 * find its books refusing every entry on the day this shipped. Only a row that
 * someone explicitly CLOSED refuses anything.
 *
 * ## Why the read takes a shared lock
 *
 * Posting and closing race. Without a lock, an entry can read "open", spend a
 * few milliseconds writing its lines, and commit into a period that was closed
 * in between — which is the one thing closing exists to prevent, and it leaves
 * no trace beyond an entry dated inside a month someone has already signed off.
 * A shared lock on the period row blocks the closing UPDATE until the posting
 * transaction ends, and `PeriodCloser` takes an exclusive lock in the other
 * direction, so whichever starts first wins and the other one is told why.
 */
class PeriodGuard
{
    /**
     * Whether the periods table has been migrated yet.
     *
     * Memoised in the POSITIVE direction only. `Schema::hasTable()` is a query,
     * and this runs on every journal write in the application; caching a `true`
     * costs nothing and can only become wrong if the table is dropped, which
     * outside of `migrate:fresh` does not happen. Caching a `false` would be
     * the dangerous direction — the table would appear mid-process and the
     * guard would stay switched off — so a negative answer is never kept.
     */
    private static bool $tableExists = false;

    /**
     * Refuses a journal dated inside a closed period.
     *
     * @param  string  $date  A calendar date, `Y-m-d`.
     * @param  string  $what  What is being written, for the message.
     */
    public function assertOpen(string $date, string $what = 'This entry'): void
    {
        $period = $this->periodFor($date);

        if ($period === null || ! $period->isClosed()) {
            return;
        }

        $closedAt = $period->closed_at?->format('j M Y');
        $when = $closedAt === null ? '' : " on {$closedAt}";

        throw ValidationException::withMessages([
            'date' => [
                "{$what} is dated {$date}, which falls in {$period->name} — a period that was closed{$when}. "
                .'Nothing can be posted into a closed period. Reopen it on the Period Closing screen if the entry '
                .'genuinely belongs there, or date the entry in an open period.',
            ],
        ]);
    }

    /**
     * The period covering a date, read under a shared lock.
     *
     * The `Schema::hasTable` check is not defensive clutter — it is what lets
     * this class be consulted by JournalPoster on a database whose migrations
     * have not reached this one yet. Every seeder and every test that posts a
     * journal runs against a schema built one migration at a time, and a hard
     * failure here would take all of them down.
     */
    private function periodFor(string $date): ?AccountingPeriod
    {
        if (! self::$tableExists) {
            if (! Schema::hasTable('accounting_periods')) {
                return null;
            }

            self::$tableExists = true;
        }

        return AccountingPeriod::query()
            ->covering($date)
            // LOCK IN SHARE MODE. Held until the surrounding posting
            // transaction commits, which is exactly as long as it needs to be
            // for a concurrent close to wait rather than overtake.
            ->sharedLock()
            ->first();
    }
}
