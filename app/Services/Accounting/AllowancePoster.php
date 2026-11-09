<?php

namespace App\Services\Accounting;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccount;
use App\Models\AccountingAllowancePosting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\LoanWriteTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The allowance for credit losses, previewed and posted (decision 12): one
 * `credit_loss` journal per branch whose 1200 balance differs from what its
 * past-due loans require ({@see AllowanceCalculator}).
 *
 * Three dates and one balance stop a run: a closed period, an allowance
 * already posted as of a later date (a back-dated run would misstate every
 * date after it), a 1200 balance on journals with no branch (an opening
 * balance posts there, and a run that left it alone would provide the same
 * allowance again in the branches), and any no-branch 1200 entry dated after
 * the run (a run dated before the opening balances would provide the full
 * allowance, with its expense landing before the books start). That balance
 * is moved to the branches with one entry per branch first; a run never
 * adjusts it. Dates in the messages read in words ("Nov 9, 2026").
 *
 * Lock order, the one every writer of 1200 lines follows: the allowance
 * account row first, before any period, journal or line lock, and before
 * this transaction's first plain read, so its snapshot is taken after the
 * wait. The loan figures are plain reads, and no loan row is ever locked, so
 * a restructure release (which locks its loans, then the allowance) queues
 * behind a run instead of deadlocking with it.
 */
final class AllowancePoster
{
    public const STALE = 'The figures changed since the preview; review them again';

    public const NOTHING_TO_POST = 'Nothing to post: every branch\'s allowance already equals what is required.';

    public const NO_BOOKS = 'The books aren\'t set up yet: seed the chart of accounts first.';

    public const NO_PERMISSION = 'You don\'t have permission to post the allowance for credit losses.';

    /** `%s`: the no-branch 1200 balance, in ₱ with two decimals. */
    public const UNASSIGNED = 'The allowance has %s on entries with no branch. Move it to the branches with one entry per branch first, then post.';

    /** `%1$s`: the latest no-branch 1200 entry's date, in words. */
    public const UNASSIGNED_LATER = 'The allowance has entries with no branch dated as late as %1$s, after this run\'s date. Post as of %1$s or later.';

    /** `%s`: the latest posting's `as_of`, in words. */
    public const LATER_POSTING = 'An allowance was already posted as of %s. Post only on or after that date.';

    /** How the messages and audit rows write a date: "Nov 9, 2026". */
    private const DATE_IN_WORDS = 'M j, Y';

    private const CONFLICT = 'Another change to the allowance was saved at the same time. Review the preview and try again.';

    /** The default accounts a posting needs, in words. */
    private const ACCOUNTS = [
        'allowance_credit_losses' => 'Allowance for Credit Losses',
        'credit_loss_expense' => 'Credit Loss Expense',
    ];

    public function __construct(
        private AllowanceCalculator $calculator,
        private AutomaticPoster $poster,
        private PeriodGuard $periods,
    ) {}

    /**
     * The preview: the calculator's figures with plain reads, the last run,
     * and whether `$user` can post them now (with every reason why not).
     *
     * @return array<string, mixed>
     */
    public function preview(string $asOf, User $user): array
    {
        $balances = $this->calculator->balances($asOf);
        $figures = $this->calculator->withBalances($this->calculator->forDate($asOf), $balances);

        $reasons = $this->booksReasons();

        if ($reasons === []) {
            $reasons = array_values(array_filter([
                $this->closedPeriodReason($asOf),
                $this->laterPostingReason($asOf),
                $this->unassignedReason($figures['unassigned_balance']),
                $this->unassignedLaterReason($balances['unassigned_latest_after']),
                $this->nothingChanges($figures) ? self::NOTHING_TO_POST : null,
            ]));
        }

        if (! $user->can('accounting:view') || ! $user->can('accounting:allowance') || ! $user->can(BranchAccess::VIEW_ALL)) {
            $reasons[] = self::NO_PERMISSION;
        }

        return [
            ...$figures,
            'last_posting' => $this->lastPosting(),
            'can_post' => $reasons === [],
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Posts the run the preview showed, in one transaction:
     *
     * 1. the allowance account row lock, as the transaction's first
     *    statement (the account id is resolved before it begins);
     * 2. 422 on `as_of` for a closed period (the period guard's shared lock)
     *    or a posting already made as of a later date;
     * 3. the loan figures, with plain reads (no loan row is locked), then
     *    each branch's 1200 balance with locking reads;
     * 4. 422 while 1200 holds a balance on journals with no branch, or has
     *    a no-branch line dated after `as_of`, 409 when
     *    the recomputed total isn't `$expectedAdjustment`, and 422 when no
     *    branch changes, each with nothing written;
     * 5. per changed branch, a posting row, its journal (keyed on that row,
     *    never a Loan) and one audit row.
     *
     * A deadlock or lock wait answers 409.
     *
     * @return array{run_id: string, as_of: string, postings: list<array<string, mixed>>, totals: array{required: int, previous_balance: int, adjustment: int}}
     *
     * @throws CannotPostToTheBooksException 422 without books or a default account
     * @throws ValidationException 422 for a closed period, a later posting, a no-branch balance or later no-branch entry, or nothing to post
     */
    public function post(string $asOf, int $expectedAdjustment, User $user): array
    {
        $reasons = $this->booksReasons();

        if ($reasons !== []) {
            throw CannotPostToTheBooksException::because($reasons[0]);
        }

        $allowanceAccountId = AccountMap::resolve()->accountFor('allowance_credit_losses');

        return LoanWriteTransaction::run(function () use ($allowanceAccountId, $asOf, $expectedAdjustment, $user): array {
            $this->lockTheAllowance($allowanceAccountId);
            $this->assertOpen($asOf);
            $this->assertNoLaterPosting($asOf);

            $balances = $this->calculator->balances($asOf, lock: true);
            $figures = $this->calculator->withBalances($this->calculator->forDate($asOf), $balances);

            $unassigned = $this->unassignedReason($figures['unassigned_balance'])
                ?? $this->unassignedLaterReason($balances['unassigned_latest_after']);

            if ($unassigned !== null) {
                throw ValidationException::withMessages(['allowance' => [$unassigned]]);
            }

            if ($figures['totals']['adjustment'] !== $expectedAdjustment) {
                throw LoanWriteTransaction::conflict(self::STALE);
            }

            if ($this->nothingChanges($figures)) {
                throw ValidationException::withMessages(['allowance' => [self::NOTHING_TO_POST]]);
            }

            $runId = (string) Str::uuid();
            $postings = [];

            foreach ($figures['branches'] as $branch) {
                if ($branch['adjustment'] !== 0) {
                    $postings[] = $this->postBranch($runId, $asOf, $branch, $user);
                }
            }

            return [
                'run_id' => $runId,
                'as_of' => $asOf,
                'postings' => array_map(fn (AccountingAllowancePosting $row): array => $row->toApiArray(), $postings),
                'totals' => [
                    'required' => array_sum(array_map(fn (AccountingAllowancePosting $row): int => $row->required, $postings)),
                    'previous_balance' => array_sum(array_map(fn (AccountingAllowancePosting $row): int => $row->previous_balance, $postings)),
                    'adjustment' => array_sum(array_map(fn (AccountingAllowancePosting $row): int => $row->adjustment, $postings)),
                ],
            ];
        }, conflictMessage: self::CONFLICT);
    }

    /**
     * One branch's posting row, its journal and its audit row.
     *
     * @param  array{branch: array{id: int, name: string}, required: int, current_balance: int, adjustment: int}  $branch
     */
    private function postBranch(string $runId, string $asOf, array $branch, User $user): AccountingAllowancePosting
    {
        $row = AccountingAllowancePosting::query()->create([
            'run_id' => $runId,
            'as_of' => $asOf,
            'branch_id' => $branch['branch']['id'],
            'required' => $branch['required'],
            'previous_balance' => $branch['current_balance'],
            'adjustment' => $branch['adjustment'],
            'created_by' => $user->id,
        ]);

        $journal = $this->poster->allowanceAdjustment($row, $branch['adjustment'], $branch['branch']['id'], $asOf, $user->id);
        $row->forceFill(['accounting_journal_id' => $journal?->id])->save();
        $row->load(['branch:id,name', 'journal:id,journal_no']);

        $verb = $branch['adjustment'] > 0 ? 'provided' : 'released';

        AuditLogService::log(
            'allowance_posted',
            $row,
            null,
            [
                'run_id' => $runId,
                'as_of' => $asOf,
                'branch_id' => $row->branch_id,
                'required' => $row->required,
                'previous_balance' => $row->previous_balance,
                'adjustment' => $row->adjustment,
                'accounting_journal_id' => $row->accounting_journal_id,
                'journal_no' => $row->journal?->journal_no,
            ],
            sprintf(
                'Allowance for credit losses, %s, as of %s: %s required against %s held, %s %s (%s).',
                $branch['branch']['name'],
                $this->inWords($asOf),
                Money::format($row->required),
                Money::format($row->previous_balance),
                Money::format(abs($row->adjustment)),
                $verb,
                $row->journal?->journal_no ?? 'no journal',
            ),
            $user->id,
        );

        return $row;
    }

    /**
     * Why the books can't take an allowance posting at all, before any
     * figure: no chart, or a default account the posting needs isn't set.
     *
     * @return list<string>
     */
    private function booksReasons(): array
    {
        if (! AccountMap::chartExists()) {
            return [self::NO_BOOKS];
        }

        $map = AccountMap::resolve();
        $reasons = [];

        foreach (self::ACCOUNTS as $role => $name) {
            if (! $map->has($role)) {
                $reasons[] = "The {$name} default account isn't set: choose it in Accounting Settings, under Default Accounts.";
            }
        }

        return $reasons;
    }

    /**
     * The allowance account row lock, as {@see AutomaticPoster::lockTheAllowance()}
     * takes it, held to the end of the transaction. Taken before the period
     * check, every journal or line lock and every plain read.
     */
    private function lockTheAllowance(int $allowanceAccountId): void
    {
        AccountingAccount::query()->whereKey($allowanceAccountId)->lockForUpdate()->first();
    }

    /**
     * Refuses a run dated before the latest posting. A locking read, taken
     * after the allowance lock that every run takes first, so no other run
     * can post between this read and this run's own rows, even when this
     * call isn't the outermost transaction's first lock.
     */
    private function assertNoLaterPosting(string $asOf): void
    {
        $reason = $this->laterPostingReason($asOf, lock: true);

        if ($reason !== null) {
            throw ValidationException::withMessages(['as_of' => [$reason]]);
        }
    }

    private function laterPostingReason(string $asOf, bool $lock = false): ?string
    {
        $latest = AccountingAllowancePosting::query()
            ->where('as_of', '>', $asOf)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->max('as_of');

        return $latest === null ? null : sprintf(self::LATER_POSTING, $this->inWords((string) $latest));
    }

    private function unassignedReason(int $unassigned): ?string
    {
        return $unassigned === 0 ? null : sprintf(self::UNASSIGNED, Money::format($unassigned));
    }

    private function unassignedLaterReason(?string $latest): ?string
    {
        return $latest === null ? null : sprintf(self::UNASSIGNED_LATER, $this->inWords($latest));
    }

    /** A stored date ("2026-11-09", or with a time) in words: "Nov 9, 2026". */
    private function inWords(string $date): string
    {
        return Carbon::parse(substr($date, 0, 10))->format(self::DATE_IN_WORDS);
    }

    /** PeriodGuard's refusal of a closed period, on `as_of`. */
    private function assertOpen(string $asOf): void
    {
        try {
            $this->periods->assertOpen($asOf, 'The allowance posting');
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['as_of' => $e->errors()['date'] ?? [$e->getMessage()]]);
        }
    }

    private function closedPeriodReason(string $asOf): ?string
    {
        try {
            $this->periods->assertOpen($asOf, 'The allowance posting');
        } catch (ValidationException $e) {
            return $e->errors()['date'][0] ?? $e->getMessage();
        }

        return null;
    }

    /** @param  array{branches: list<array{adjustment: int}>}  $figures */
    private function nothingChanges(array $figures): bool
    {
        foreach ($figures['branches'] as $branch) {
            if ($branch['adjustment'] !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @return array{as_of: string, posted_at: mixed, posted_by: array{id: int, name: string}|null}|null */
    private function lastPosting(): ?array
    {
        $last = AccountingAllowancePosting::query()->with('creator')->latest('id')->first();

        if ($last === null) {
            return null;
        }

        return [
            'as_of' => $last->as_of->toDateString(),
            'posted_at' => $last->created_at,
            'posted_by' => $last->creator === null ? null : ['id' => $last->creator->id, 'name' => $last->creator->full_name],
        ];
    }
}
