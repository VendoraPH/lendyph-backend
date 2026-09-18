<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where accounting periods come from, and what closing and reopening one does.
 *
 * ## Nobody creates a period by hand
 *
 * There is no "new period" endpoint and no such button, deliberately: the
 * months a co-op's books span are a FACT about the ledger, not a decision. So
 * {@see self::ensureProvisioned()} derives them — every calendar month from the
 * earliest journal in the books through the current one — and the Period
 * Closing screen finds its rows already there.
 *
 * The screen's own empty state ("No accounting period has been set up") is then
 * only reachable on a database with no journals at all, which is the one case
 * where it is true.
 *
 * ## Provisioning runs on a GET, and that is a considered exception
 *
 * A read endpoint that writes is normally a smell. It is justified here because
 * the alternative is worse in both directions: without it the screen is empty
 * on every deployment until someone runs a command nobody documented, and with
 * a POST instead, the frontend would need a create dialog for rows whose
 * contents are entirely determined by the calendar.
 *
 * It is made safe rather than merely convenient: `insertOrIgnore` against a
 * unique `code`, so two concurrent readers cannot create the same month twice
 * and neither of them fails; the range is bounded by
 * {@see AccountingPeriod::MAX_MONTHS_BACK}, so one journal mis-keyed as the
 * year 0201 cannot generate twenty thousand rows; and it never touches a row
 * that already exists, so nothing it does can reopen a closed month.
 */
class PeriodCalendar
{
    /**
     * Makes sure a period row exists for every month the books cover.
     *
     * @return int how many were created, for tests and diagnostics
     */
    public function ensureProvisioned(): int
    {
        $today = CarbonImmutable::now()->startOfMonth();

        $earliest = AccountingJournal::query()->min('date');

        $from = $earliest === null
            ? $today
            : CarbonImmutable::parse((string) $earliest)->startOfMonth();

        // The floor. See AccountingPeriod::MAX_MONTHS_BACK — a guard against
        // one bad date, not a policy about how far back books may go.
        $floor = $today->subMonths(AccountingPeriod::MAX_MONTHS_BACK);

        if ($from->lt($floor)) {
            $from = $floor;
        }

        // A journal dated in the future — a post-dated cheque, a scheduled
        // entry — still needs a period to live in, or it would post into a
        // month the screen never shows.
        $latest = AccountingJournal::query()->max('date');

        $to = $latest === null
            ? $today
            : CarbonImmutable::parse((string) $latest)->startOfMonth();

        if ($to->lt($today)) {
            $to = $today;
        }

        $existing = AccountingPeriod::query()->pluck('code')->flip();

        $rows = [];
        $now = now();

        for ($month = $from; $month->lte($to); $month = $month->addMonth()) {
            $attributes = AccountingPeriod::attributesForMonth($month);

            if ($existing->has($attributes['code'])) {
                continue;
            }

            $rows[] = $attributes + [
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // insertOrIgnore, not insert. Two people opening the screen at once
        // would otherwise race on the unique `code` and one of them would get a
        // 500 on a page load that created nothing it needed.
        AccountingPeriod::query()->insertOrIgnore($rows);

        return count($rows);
    }

    /**
     * Closes a period. After this, nothing can be posted into it.
     *
     * Takes an exclusive lock first, which is the other half of the shared lock
     * {@see PeriodGuard} takes while posting. Whichever transaction starts
     * first wins: a post already in flight finishes and this waits for it, or
     * this commits and the post is refused. Without the pair, an entry could
     * read "open", spend a few milliseconds writing lines, and land inside a
     * month that had been signed off in between — leaving no trace beyond an
     * entry dated in a closed period.
     */
    public function close(AccountingPeriod $period, ?User $user): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $user): AccountingPeriod {
            $fresh = $this->lock($period->id);

            if ($fresh->isClosed()) {
                throw ValidationException::withMessages([
                    'period' => ["{$fresh->name} is already closed."],
                ]);
            }

            $fresh->update([
                'status' => 'closed',
                'closed_by' => $user?->id,
                'closed_at' => now(),
                // Cleared on close so the pair always describes the CURRENT
                // state: a period reopened in March and closed again in April
                // is closed, and leaving March's reopening stamped on it would
                // read as though it were still open.
                'reopened_by' => null,
                'reopened_at' => null,
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * Reopens a period, and records that it happened.
     *
     * Reopening is not an undo — the month was signed off and somebody is
     * taking that back. The dialog on the Period Closing screen promises this
     * is recorded, and it is recorded twice over: in the audit log through the
     * model's Auditable trait, and on the row itself, so an auditor asking "was
     * this month ever reopened?" gets the answer from the period rather than
     * from a log search.
     */
    public function reopen(AccountingPeriod $period, ?User $user): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $user): AccountingPeriod {
            $fresh = $this->lock($period->id);

            if (! $fresh->isClosed()) {
                throw ValidationException::withMessages([
                    'period' => ["{$fresh->name} is already open."],
                ]);
            }

            $fresh->update([
                'status' => 'open',
                // `closed_by` and `closed_at` are deliberately LEFT ALONE. They
                // are the record that this month was once signed off, and by
                // whom; clearing them on reopen would erase exactly the fact
                // that makes reopening worth recording.
                'reopened_by' => $user?->id,
                'reopened_at' => now(),
            ]);

            return $fresh->refresh();
        });
    }

    private function lock(int $periodId): AccountingPeriod
    {
        $period = AccountingPeriod::query()->whereKey($periodId)->lockForUpdate()->first();

        if ($period === null) {
            throw ValidationException::withMessages([
                'period' => ['That accounting period no longer exists.'],
            ]);
        }

        return $period;
    }
}
