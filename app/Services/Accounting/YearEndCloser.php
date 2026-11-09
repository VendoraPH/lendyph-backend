<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AccountingYearClose;
use App\Models\AccountingYearCloseEntry;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;

/**
 * The year-end close (Phase 2, round C, slice C3; calendar fiscal year).
 *
 * Closing a year posts one `closing` journal per branch that had income or
 * expense in it, plus one for the journals with no branch, dated 31 December.
 * Each empties that branch's income and expense accounts into the
 * `net_surplus` account (3030 Retained Earnings on the lending company chart,
 * 3080 Undivided Net Surplus on the cooperative one), and then every month of
 * the year still open is closed. Reopening the latest closed year reopens
 * the months the close closed and reverses its closing journals on
 * 31 December, with a reason.
 *
 * The income statement leaves closing journals and their reversals out
 * (IncomeStatementBuilder::withoutClosingEntries()), so a closed year reads
 * as it did. The trial balance and the balance sheet count them: that is how
 * the result reaches net surplus, and why the balance sheet's derived
 * earnings lines read ₱0.00 after a close.
 *
 * ## Locks
 *
 * - The period rows of the year, FOR UPDATE in date order, are each
 *   transaction's FIRST statement. PeriodGuard reads the covering period
 *   under a shared lock, so a posting into the year that is in flight
 *   finishes first, and one that starts later waits and then meets a closed
 *   month. Being first matters under REPEATABLE READ as well: the snapshot
 *   the figures are read from is only taken at the first plain read, after
 *   the locks, so it holds every posting that got in before them.
 * - Then the previous year's close row (close, always) or this year's close
 *   row (reopen), FOR UPDATE, both through the UNIQUE `closed_year` entry. A
 *   close of 2026 and a reopen of 2025 queue on 2025's entry, so 2026 can
 *   never be closed on top of a 2025 that was reopened at the same moment.
 *   The reopen takes that entry before it reads its row by id: locked by id
 *   first, a close could take the entry and wait for the row while the
 *   reopen, clearing `closed_year`, waited for the entry. A reopen also reads
 *   the later closes with a locking read, so a later close being inserted
 *   waits for it, or it waits for that close and is refused.
 * - No closing line is ever on the 1200 allowance account (income, expense
 *   and the equity net surplus account only), so neither the close nor the
 *   reversals of a reopen take the allowance lock that JournalPoster takes
 *   first for postings that touch it.
 *
 * A deadlock, a lock wait timeout or a second live close of the same year
 * (the UNIQUE `closed_year`) answers 409, never 500.
 *
 * MONEY IS IN CENTAVOS. A net surplus is signed: a profit is positive.
 */
final class YearEndCloser
{
    use DetectsConcurrencyErrors;

    /** The journal source of a closing entry. Last in the column's enum. */
    public const SOURCE = 'closing';

    public const AUDIT_CLOSED = 'year_closed';

    public const AUDIT_REOPENED = 'year_reopened';

    /** The posting role the result moves to. */
    public const NET_SURPLUS_ROLE = 'net_surplus';

    /** Whether a year has ended is decided on the Manila calendar. */
    public const TIMEZONE = 'Asia/Manila';

    private const CLOSED_YEAR_INDEX = 'accounting_year_closes_closed_year_unique';

    private const SETTINGS_HINT = 'Choose an equity account for it in Accounting → Settings → Default Accounts.';

    public function __construct(
        private PeriodCalendar $calendar,
        private JournalPoster $poster,
    ) {}

    /**
     * What a close of `$year` would post and close, and why it can't run yet.
     *
     * Makes sure every month of an ended year the books cover has its period
     * row (provision()), the same idempotent provisioning the Period Closing
     * screen does on read, so the months listed are exactly the ones the
     * close closes. A year before the books start gets none. Posts nothing.
     *
     * @return array<string, mixed> `YearClosePreview` in src/types/accounting.ts
     */
    public function preview(int $year): array
    {
        $this->provision($year);

        return $this->document($year, lockingTheYearBefore: false);
    }

    /**
     * Closes `$year`. See the class docblock for what it posts and the locks.
     *
     * @throws ValidationException 422 on `year` with every blocking reason
     * @throws HttpResponseException 409 when the year is already closed, a
     *                               close is racing this one, or the first
     *                               journal of an ended year raced its
     *                               provisioning (a retry adds the months)
     */
    public function close(int $year, User $user): AccountingYearClose
    {
        return $this->withConflictsAs409($year, function () use ($year, $user): AccountingYearClose {
            // Outside the transaction on purpose: provisioning reads, and a
            // plain read inside it would fix the snapshot before the locks.
            $this->provision($year);

            return DB::transaction(function () use ($year, $user): AccountingYearClose {
                $periods = $this->lockPeriods($year);
                $preview = $this->document($year, lockingTheYearBefore: true);

                if ($this->liveClose($year) !== null) {
                    throw $this->conflict("{$year} is already closed.");
                }

                if (! $preview['can_close']) {
                    throw ValidationException::withMessages(['year' => $preview['blocking_reasons']]);
                }

                // The books' coverage was read before the transaction: a first
                // journal posted into the year since then left its months
                // without rows, and a close now would close none of them. The
                // retry's provisioning adds them.
                if ($this->hasEnded($year) && $periods->count() < 12) {
                    throw $this->conflict($this->racedMessage($year));
                }

                $netSurplusAccountId = (int) $preview['net_surplus_account']['id'];

                $close = AccountingYearClose::query()->create([
                    'year' => $year,
                    'status' => AccountingYearClose::STATUS_CLOSED,
                    'closed_year' => $year,
                    'net_surplus_account_id' => $netSurplusAccountId,
                    'net_surplus' => 0,
                    'periods_closed' => [],
                    'closed_by' => $user->id,
                    'closed_at' => now(),
                ]);

                $entries = [];

                foreach ($preview['branches'] as $group) {
                    $entries[] = $this->postEntry($close, $group, $netSurplusAccountId, $user);
                }

                $closedPeriodIds = [];

                foreach ($periods as $period) {
                    if (! $period->isClosed()) {
                        $closedPeriodIds[] = $this->calendar->close($period, $user)->id;
                    }
                }

                $close->update([
                    'net_surplus' => Money::sum(array_column($entries, 'net_surplus')),
                    'periods_closed' => $closedPeriodIds,
                ]);

                AuditLogService::log(self::AUDIT_CLOSED, $close, null, [
                    'year' => $year,
                    'status' => AccountingYearClose::STATUS_CLOSED,
                    'net_surplus' => $close->net_surplus,
                    'net_surplus_account_id' => $netSurplusAccountId,
                    'entries' => $entries,
                    'periods_closed' => $closedPeriodIds,
                ], sprintf(
                    'Closed %d: net surplus of %s posted to %s %s in %d closing %s; %d %s closed.',
                    $year,
                    Money::format($close->net_surplus),
                    $preview['net_surplus_account']['code'],
                    $preview['net_surplus_account']['name'],
                    count($entries),
                    count($entries) === 1 ? 'entry' : 'entries',
                    count($closedPeriodIds),
                    count($closedPeriodIds) === 1 ? 'period' : 'periods',
                ), $user->id);

                return $close->refresh();
            });
        });
    }

    /**
     * Reopens a close: the latest closed year only, with a reason.
     *
     * Reopens the months in `periods_closed` that are still closed (each
     * writes its own audit row), reverses each closing journal on 31 December
     * with the reason, and keeps the row as `reopened`. A month that was
     * already closed before the close is not in `periods_closed`, so it stays
     * closed.
     *
     * @throws ValidationException 422 on `close` when the row isn't closed, a
     *                             later year is closed, or an account on a
     *                             closing entry is now a heading
     */
    public function reopen(AccountingYearClose $close, string $reason, User $user): AccountingYearClose
    {
        return $this->withConflictsAs409($close->year, function () use ($close, $reason, $user): AccountingYearClose {
            return DB::transaction(function () use ($close, $reason, $user): AccountingYearClose {
                $periods = $this->lockPeriods($close->year);

                // Through the `closed_year` entry first, as a close of the
                // next year takes it, then by id: see the class docblock.
                AccountingYearClose::query()->where('closed_year', $close->year)->lockForUpdate()->first();
                $fresh = AccountingYearClose::query()->whereKey($close->id)->lockForUpdate()->firstOrFail();

                if (! $fresh->isClosed()) {
                    throw ValidationException::withMessages([
                        'close' => ["This close of {$fresh->year} has already been reopened."],
                    ]);
                }

                // A locking read: a close of a later year inserting its row
                // now waits for this reopen, and one already inserted makes
                // this read wait for it and then see it.
                $laterYear = AccountingYearClose::query()
                    ->where('status', AccountingYearClose::STATUS_CLOSED)
                    ->where('year', '>', $fresh->year)
                    ->lockForUpdate()
                    ->max('year');

                if ($laterYear !== null) {
                    throw ValidationException::withMessages([
                        'close' => ["Only the latest closed year can be reopened. Reopen {$laterYear} first."],
                    ]);
                }

                $journals = AccountingJournal::query()
                    ->whereIn('id', $fresh->entries()->whereNotNull('accounting_journal_id')->pluck('accounting_journal_id'))
                    ->where('source', self::SOURCE)
                    ->where('status', 'posted')
                    ->orderBy('id')
                    ->get();

                $this->assertTheClosingEntriesCanBeReversed($fresh->year, $journals->pluck('id')->all());

                $closedByTheClose = array_map('intval', $fresh->periods_closed ?? []);
                $reopened = [];

                foreach ($periods as $period) {
                    if (in_array($period->id, $closedByTheClose, true) && $period->isClosed()) {
                        $reopened[] = $this->calendar->reopen($period, $user)->id;
                    }
                }

                $reversals = [];

                foreach ($journals as $journal) {
                    $reversal = $this->poster->reverse($journal, "{$fresh->year}-12-31", $reason, $user->id);
                    $reversals[] = ['journal_id' => $journal->id, 'reversal_journal_id' => $reversal->id, 'reversal_journal_no' => $reversal->journal_no];
                }

                $fresh->update([
                    'status' => AccountingYearClose::STATUS_REOPENED,
                    'closed_year' => null,
                    'reopened_by' => $user->id,
                    'reopened_at' => now(),
                    'reopen_reason' => $reason,
                ]);

                AuditLogService::log(self::AUDIT_REOPENED, $fresh, [
                    'year' => $fresh->year,
                    'status' => AccountingYearClose::STATUS_CLOSED,
                ], [
                    'year' => $fresh->year,
                    'status' => AccountingYearClose::STATUS_REOPENED,
                    'reopen_reason' => $reason,
                    'periods_reopened' => $reopened,
                    'reversals' => $reversals,
                ], sprintf(
                    'Reopened %d: %d closing %s reversed; %d %s reopened. Reason: %s',
                    $fresh->year,
                    count($reversals),
                    count($reversals) === 1 ? 'entry' : 'entries',
                    count($reopened),
                    count($reopened) === 1 ? 'period' : 'periods',
                    $reason,
                ), $user->id);

                return $fresh->refresh();
            });
        });
    }

    /**
     * Refuses a reopen whose closing entries can't be reversed: JournalPoster
     * reverses onto an inactive account (the entry is being undone, not
     * added to) but never onto a heading, whose balance is its subtree's.
     * Checked before anything is written, so the refusal is on `close`, in
     * words, naming each account.
     *
     * @param  list<int>  $journalIds
     */
    private function assertTheClosingEntriesCanBeReversed(int $year, array $journalIds): void
    {
        $headings = DB::table('accounting_journal_lines as l')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('l.accounting_journal_id', $journalIds)
            ->where('a.is_group', true)
            ->distinct()
            ->orderBy('a.code')
            ->get(['a.code', 'a.name']);

        if ($headings->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'close' => $headings->map(fn (object $account): string => "{$account->code} {$account->name} is now a heading, so the "
                ."closing entries of {$year} can't be reversed. Make it a postable account again before reopening.")->all(),
        ]);
    }

    /**
     * Reopens one month from the Period Closing screen, unless it belongs to
     * a closed year.
     *
     * A month of a year with a live close is refused, whether the close
     * closed it or it was closed before: reopened, it would hold the closing
     * entries (December) or take postings into a year whose income and
     * expense were emptied into net surplus. The year is reopened as a whole
     * through {@see self::reopen()} instead, which reopens the months it
     * closed itself.
     *
     * The period row is locked first, the lock a close of its year takes too,
     * so a month reopen and a year close never overtake each other.
     *
     * @throws ValidationException 422 on `period`
     */
    public function reopenPeriod(AccountingPeriod $period, ?User $user): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $user): AccountingPeriod {
            $locked = AccountingPeriod::query()->whereKey($period->id)->lockForUpdate()->first() ?? $period;
            $year = $locked->start_date->year;

            if ($this->liveClose($year) !== null) {
                throw ValidationException::withMessages([
                    'period' => ["This month is part of the {$year} year-end close. Reopen the year instead."],
                ]);
            }

            return $this->calendar->reopen($locked, $user);
        });
    }

    /**
     * Refuses a manual reverse (the journal screen) of a closing journal or
     * of the reversal of one.
     *
     * A closing entry is undone only by reopening its year, which reverses
     * every entry of the close on 31 December, reopens its months and records
     * why. Reversed by hand, the mirror could land in another year while the
     * close still read "closed"; reversing a reopen's reversal would put a
     * closing entry back with no close behind it. Neither field changes after
     * posting, so there is nothing to lock.
     *
     * @throws ValidationException 422 on `journal`
     */
    public function assertNotPartOfAClose(AccountingJournal $journal): void
    {
        $closing = $journal->source === self::SOURCE
            ? $journal
            : ($journal->reverses_journal_id === null ? null : AccountingJournal::query()
                ->whereKey($journal->reverses_journal_id)
                ->where('source', self::SOURCE)
                ->first());

        if ($closing === null) {
            return;
        }

        throw ValidationException::withMessages([
            'journal' => ["This entry is part of the {$closing->date->year} year-end close. Reopen the year instead."],
        ]);
    }

    /**
     * Posts one branch's closing journal and returns what the audit row keeps.
     *
     * Each account is moved by its net for the year in the opposite direction
     * (a credit net is debited, a debit net credited), so it ends the year at
     * zero; the difference goes to net surplus, credited for a profit and
     * debited for a loss. A group reaches here only with at least one line
     * that isn't zero, so the journal always records something.
     *
     * @param  array<string, mixed>  $group  one of document()'s `branches`
     * @return array{branch_id: int|null, journal_id: int, journal_no: string|null, net_surplus: int}
     */
    private function postEntry(AccountingYearClose $close, array $group, int $netSurplusAccountId, User $user): array
    {
        $branchId = $group['branch']['id'] ?? null;

        $entry = AccountingYearCloseEntry::query()->create([
            'accounting_year_close_id' => $close->id,
            'branch_id' => $branchId,
            'net_surplus' => $group['net_surplus'],
        ]);

        $lines = [];

        foreach ($group['lines'] as $line) {
            // Credit less debit for the year, whatever the account's type.
            $creditNet = $line['type'] === 'income' ? $line['amount'] : -$line['amount'];

            $lines[] = [
                'account_id' => $line['account_id'],
                'description' => "Close {$line['account_code']} {$line['account_name']} to net surplus",
                'debit' => max($creditNet, 0),
                'credit' => max(-$creditNet, 0),
            ];
        }

        $net = (int) $group['net_surplus'];

        if ($net !== 0) {
            $lines[] = [
                'account_id' => $netSurplusAccountId,
                'description' => ($net > 0 ? 'Net surplus' : 'Net loss')." for {$close->year}",
                'debit' => max(-$net, 0),
                'credit' => max($net, 0),
            ];
        }

        $journal = $this->poster->postImmediately([
            'date' => "{$close->year}-12-31",
            'source' => self::SOURCE,
            'description' => "Year-end close {$close->year}",
            'branch_id' => $branchId,
            'postable_type' => AccountingYearCloseEntry::class,
            'postable_id' => $entry->id,
        ], $lines, $user->id);

        $entry->update(['accounting_journal_id' => $journal->id]);

        return [
            'branch_id' => $branchId,
            'journal_id' => $journal->id,
            'journal_no' => $journal->journal_no,
            'net_surplus' => $net,
        ];
    }

    /**
     * The preview document. Read under the close's locks too, so the figures
     * posted are the ones checked.
     *
     * @return array<string, mixed>
     */
    private function document(int $year, bool $lockingTheYearBefore): array
    {
        $account = $this->netSurplusAccount();
        $rows = $this->yearRows($year);
        $branches = $this->groups($rows);
        $periods = $this->periodsOf($year);
        $drafts = AccountingJournal::query()
            ->where('status', 'draft')
            ->where('date', '>=', "{$year}-01-01")
            ->where('date', '<=', "{$year}-12-31")
            ->count();

        $reasons = $this->blockingReasons($year, $account, $drafts, $periods, $this->unpostableAccounts($rows, $year), $lockingTheYearBefore);
        $totalIncome = Money::sum(array_column($branches, 'total_income'));
        $totalExpenses = Money::sum(array_column($branches, 'total_expenses'));

        return [
            'year' => $year,
            'as_of' => "{$year}-12-31",
            'net_surplus_account' => $account === null ? null : [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
            ],
            'branches' => $branches,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_surplus' => $totalIncome - $totalExpenses,
            'periods_to_close' => $periods
                ->reject(fn (AccountingPeriod $period): bool => $period->isClosed())
                ->map(fn (AccountingPeriod $period): array => ['id' => $period->id, 'name' => $period->name])
                ->values()
                ->all(),
            'draft_journals' => $drafts,
            'can_close' => $reasons === [],
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Every reason the close can't run, in words, in a fixed order. A year
     * already closed, or one the books don't cover, has that one reason only:
     * the others (December is closed, for one) follow from it and would only
     * mislead.
     *
     * @param  Collection<int, AccountingPeriod>  $periods
     * @param  list<string>  $unpostable  unpostableAccounts()
     * @return list<string>
     */
    private function blockingReasons(int $year, ?AccountingAccount $account, int $drafts, Collection $periods, array $unpostable, bool $lockingTheYearBefore): array
    {
        if ($this->liveClose($year) !== null) {
            return ["{$year} is already closed."];
        }

        $notCovered = $this->notCoveredByTheBooks($year);

        if ($notCovered !== null) {
            return [$notCovered];
        }

        $reasons = [];
        $next = $year + 1;

        if (! $this->hasEnded($year)) {
            $reasons[] = "{$year} ends on Dec 31, {$year}; it can be closed from Jan 1, {$next}.";
        }

        if ($this->previousYearMustBeClosedFirst($year, $lockingTheYearBefore)) {
            $reasons[] = 'Close '.($year - 1).' first.';
        }

        if ($drafts === 1) {
            $reasons[] = "1 draft journal is dated in {$year}. Post it or move it out of the year first.";
        } elseif ($drafts > 1) {
            $reasons[] = "{$drafts} draft journals are dated in {$year}. Post them or move them out of the year first.";
        }

        $december = $periods->firstWhere('code', "{$year}-12");

        if ($december !== null && $december->isClosed()) {
            $reasons[] = "December {$year} is closed. Reopen it so the closing entries can be dated Dec 31, {$year}.";
        }

        if ($account === null) {
            $reasons[] = 'The net surplus account is not set. '.self::SETTINGS_HINT;
        } elseif ($account->type !== 'equity') {
            $reasons[] = "The net surplus account, {$account->code} {$account->name}, is not an equity account. ".self::SETTINGS_HINT;
        } elseif ($account->is_group || ! $account->is_active) {
            $reasons[] = "The net surplus account, {$account->code} {$account->name}, can't take postings because it is "
                .($account->is_group ? 'a heading' : 'inactive').'. '.self::SETTINGS_HINT;
        } elseif (($reserved = AccountingAccountMapping::reservedAccountReason(self::NET_SURPLUS_ROLE, (string) $account->code, "{$account->code} {$account->name}")) !== null) {
            // A chart mapped this way before the mapping refused it.
            $reasons[] = $reserved.' '.self::SETTINGS_HINT;
        }

        return [...$reasons, ...$unpostable];
    }

    /**
     * Why the books don't cover `$year`, or null when they do: from the year
     * of the earliest journal (any status, the range PeriodCalendar
     * provisions) on. A year before it has nothing to close, and its preview
     * and close write nothing.
     */
    private function notCoveredByTheBooks(int $year): ?string
    {
        $earliest = AccountingJournal::query()->min('date');

        if ($earliest === null) {
            return "The books have no entries yet, so {$year} has nothing to close.";
        }

        $firstYear = (int) substr((string) $earliest, 0, 4);

        return $year < $firstYear ? "The books start in {$firstYear}, so {$year} has nothing to close." : null;
    }

    /**
     * An income or expense account the close would have to post to but
     * can't: inactive, or turned into a heading, while a branch holds a net
     * on it for the year. JournalPoster refuses both, so each is a blocking
     * reason in words rather than a refusal on `lines` halfway through the
     * close. The amount is the account's net for the year, as it normally
     * reads.
     *
     * @param  Collection<int, object>  $rows  yearRows()
     * @return list<string>
     */
    private function unpostableAccounts(Collection $rows, int $year): array
    {
        $reasons = [];

        foreach ($rows->groupBy('account_id') as $accountRows) {
            $first = $accountRows->first();

            if (! $first->is_group && $first->is_active) {
                continue;
            }

            $holdsANet = $accountRows->contains(fn (object $row): bool => (int) $row->debit !== (int) $row->credit);

            if (! $holdsANet) {
                continue;
            }

            $amount = Money::format(BalanceSheetBuilder::netFor([
                'type' => (string) $first->type,
                'debit' => Money::sum($accountRows->map(fn (object $row): int => (int) $row->debit)->all()),
                'credit' => Money::sum($accountRows->map(fn (object $row): int => (int) $row->credit)->all()),
            ]));

            $reasons[] = $first->is_group
                ? "{$first->account_code} {$first->account_name} is a heading and holds {$amount} for {$year}. Make it a postable account again before closing."
                : "{$first->account_code} {$first->account_name} is inactive and holds {$amount} for {$year}. Reactivate it before closing.";
        }

        return $reasons;
    }

    /**
     * Whether a year before this one has to be closed first: the previous
     * year must be closed once anything is posted before this year (Lead's
     * call 6), so the first year of the books needs no earlier close.
     *
     * Under the close's locks the previous year's close row is always read
     * FOR UPDATE first, whatever was posted, which a reopen of that year also
     * takes (see the class docblock).
     */
    private function previousYearMustBeClosedFirst(int $year, bool $locking): bool
    {
        $previousClose = AccountingYearClose::query()
            ->where('closed_year', $year - 1)
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($previousClose !== null) {
            return false;
        }

        return AccountingJournal::query()
            ->whereIn('status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('date', '<', "{$year}-01-01")
            ->exists();
    }

    /**
     * The year's income and expense by branch and account: one grouped
     * query on posted and reversed lines dated in the year, closing entries
     * and their reversals left out, so a close after a reopen sees the year's
     * real figures. Headings are kept here (unpostableAccounts() needs them)
     * and left out of the groups, as the income statement leaves them out.
     *
     * @return Collection<int, object>
     */
    private function yearRows(int $year): Collection
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('j.date', '>=', "{$year}-01-01")
            ->where('j.date', '<=', "{$year}-12-31")
            ->tap(fn (Builder $query) => IncomeStatementBuilder::withoutClosingEntries($query, 'j'))
            ->whereIn('a.type', ['income', 'expense'])
            ->groupBy('j.branch_id', 'a.id', 'a.code', 'a.name', 'a.type', 'a.is_group', 'a.is_active')
            ->orderBy('a.code')
            ->select([
                'j.branch_id',
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                'a.type',
                'a.is_group',
                'a.is_active',
                DB::raw('sum(l.debit) as debit'),
                DB::raw('sum(l.credit) as credit'),
            ])
            ->get();
    }

    /**
     * The year's rows by branch, as the close posts them. Groups with nothing
     * to close are left out. Named branches in name order, the journals with
     * no branch last.
     *
     * @param  Collection<int, object>  $rows  yearRows()
     * @return list<array{branch: array{id: int, name: string}|null, lines: list<array<string, mixed>>, total_income: int, total_expenses: int, net_surplus: int}>
     */
    private function groups(Collection $rows): array
    {
        $rows = $rows->reject(fn (object $row): bool => (bool) $row->is_group);

        $names = Branch::query()
            ->whereIn('id', $rows->pluck('branch_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        $groups = [];

        foreach ($rows->groupBy(fn (object $row): string => (string) ($row->branch_id ?? '')) as $key => $branchRows) {
            $lines = [];

            foreach ($branchRows as $row) {
                // MySQL returns SUM() as a DECIMAL string.
                $amount = BalanceSheetBuilder::netFor([
                    'type' => (string) $row->type,
                    'debit' => (int) $row->debit,
                    'credit' => (int) $row->credit,
                ]);

                if ($amount === 0) {
                    continue;
                }

                $lines[] = [
                    'account_id' => (int) $row->account_id,
                    'account_code' => (string) $row->account_code,
                    'account_name' => (string) $row->account_name,
                    'type' => (string) $row->type,
                    'amount' => $amount,
                ];
            }

            if ($lines === []) {
                continue;
            }

            $income = Money::sum(array_column(array_filter($lines, fn (array $line): bool => $line['type'] === 'income'), 'amount'));
            $expenses = Money::sum(array_column(array_filter($lines, fn (array $line): bool => $line['type'] === 'expense'), 'amount'));
            $branchId = $key === '' ? null : (int) $key;

            $groups[] = [
                'branch' => $branchId === null ? null : ['id' => $branchId, 'name' => (string) ($names[$branchId] ?? '')],
                'lines' => $lines,
                'total_income' => $income,
                'total_expenses' => $expenses,
                'net_surplus' => $income - $expenses,
            ];
        }

        usort($groups, static function (array $a, array $b): int {
            if ($a['branch'] === null || $b['branch'] === null) {
                return ($a['branch'] === null) <=> ($b['branch'] === null);
            }

            return [$a['branch']['name'], $a['branch']['id']] <=> [$b['branch']['name'], $b['branch']['id']];
        });

        return $groups;
    }

    private function netSurplusAccount(): ?AccountingAccount
    {
        $accountId = AccountingAccountMapping::resolved()[self::NET_SURPLUS_ROLE] ?? null;

        return $accountId === null ? null : AccountingAccount::query()->find($accountId);
    }

    private function liveClose(int $year): ?AccountingYearClose
    {
        return AccountingYearClose::query()->where('closed_year', $year)->first();
    }

    /**
     * The year's period rows, read plainly, in date order.
     *
     * @return Collection<int, AccountingPeriod>
     */
    private function periodsOf(int $year): Collection
    {
        return AccountingPeriod::query()
            ->whereIn('code', self::monthCodes($year))
            ->orderBy('code')
            ->get();
    }

    /**
     * The year's period rows FOR UPDATE, in date order: the transaction's
     * first statement. Looked up by their unique codes so the lock holds
     * those twelve rows and no gap or row of another year.
     *
     * @return Collection<int, AccountingPeriod>
     */
    private function lockPeriods(int $year): Collection
    {
        return AccountingPeriod::query()
            ->whereIn('code', self::monthCodes($year))
            ->orderBy('code')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Period rows for the books' months (PeriodCalendar), and, once the year
     * has ended, for each of its twelve months. Nothing for a year the books
     * don't cover (notCoveredByTheBooks()). A month with no row doesn't
     * lock (PeriodGuard), so a close that left January without one would
     * leave it open to postings. Only missing rows are inserted, with
     * insertOrIgnore against the unique code, as PeriodCalendar does: an
     * existing row is never touched.
     */
    private function provision(int $year): void
    {
        if ($this->notCoveredByTheBooks($year) !== null) {
            return;
        }

        $this->calendar->ensureProvisioned();

        if (! $this->hasEnded($year)) {
            return;
        }

        $existing = AccountingPeriod::query()->whereIn('code', self::monthCodes($year))->pluck('code')->flip();
        $now = now();
        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $attributes = AccountingPeriod::attributesForMonth(CarbonImmutable::create($year, $month, 1));

            if (! $existing->has($attributes['code'])) {
                $rows[] = $attributes + ['status' => 'open', 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($rows !== []) {
            AccountingPeriod::query()->insertOrIgnore($rows);
        }
    }

    private function hasEnded(int $year): bool
    {
        return CarbonImmutable::now(self::TIMEZONE)->year > $year;
    }

    /** @return list<string> */
    private static function monthCodes(int $year): array
    {
        return array_map(static fn (int $month): string => sprintf('%04d-%02d', $year, $month), range(1, 12));
    }

    /**
     * Runs a close or a reopen, answering a collision with a 409: a deadlock
     * or a lock wait timeout (another close, reopen or posting held the
     * year's rows), or a second live close of the year on the UNIQUE
     * `closed_year`. Every other error is rethrown untouched.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withConflictsAs409(int $year, Closure $callback): mixed
    {
        $message = $this->racedMessage($year);

        try {
            return $callback();
        } catch (UniqueConstraintViolationException $e) {
            if ($e->index !== self::CLOSED_YEAR_INDEX) {
                throw $e;
            }

            throw $this->conflict($message);
        } catch (PDOException $e) {
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw $this->conflict($message);
        }
    }

    private function racedMessage(int $year): string
    {
        return "Another year-end close or reopen of {$year} was saved at the same time. Reload and try again.";
    }

    private function conflict(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message], 409));
    }
}
