<?php

namespace App\Services\Accounting;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccount;
use App\Models\AccountingAllowancePosting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\LoanWriteTransaction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The allowance for credit losses, previewed and posted (decision 12): one
 * `credit_loss` journal per branch whose 1200 balance differs from what its
 * past-due loans require ({@see AllowanceCalculator}). The 1200 balance on
 * journals with no branch is shown and never adjusted.
 *
 * Lock order, the one every writer of 1200 lines follows: the allowance
 * account row first, before any period, journal or line lock. The loan
 * figures are plain reads taken before it, and no loan row is ever locked
 * after it, so a restructure release (which locks its loans, then the
 * allowance) queues behind a run instead of deadlocking with it.
 */
final class AllowancePoster
{
    public const STALE = 'The figures changed since the preview; review them again';

    public const NOTHING_TO_POST = 'Nothing to post: every branch\'s allowance already equals what is required.';

    public const NO_BOOKS = 'The books aren\'t set up yet: seed the chart of accounts first.';

    public const NO_PERMISSION = 'You don\'t have permission to post the allowance for credit losses.';

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
        $figures = $this->calculator->withBalances(
            $this->calculator->forDate($asOf),
            $this->calculator->balances($asOf),
        );

        $reasons = $this->booksReasons();

        if ($reasons === []) {
            $closed = $this->closedPeriodReason($asOf);

            if ($closed !== null) {
                $reasons[] = $closed;
            }

            if ($this->nothingChanges($figures)) {
                $reasons[] = self::NOTHING_TO_POST;
            }
        }

        if (! $user->can('accounting:allowance') || ! $user->can(BranchAccess::VIEW_ALL)) {
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
     * 1. the loan figures, with plain reads (no loan row is locked);
     * 2. the allowance account row lock, then the period check (its shared
     *    lock), then each branch's 1200 balance with locking reads;
     * 3. 409 when the recomputed total isn't `$expectedAdjustment`, 422 when
     *    no branch changes, with nothing written;
     * 4. per changed branch, a posting row, its journal (keyed on that row,
     *    never a Loan) and one audit row.
     *
     * A deadlock or lock wait answers 409.
     *
     * @return array{run_id: string, as_of: string, postings: list<array<string, mixed>>, totals: array{required: int, previous_balance: int, adjustment: int}}
     *
     * @throws CannotPostToTheBooksException 422 without books or a default account
     * @throws ValidationException 422 for a closed period or nothing to post
     */
    public function post(string $asOf, int $expectedAdjustment, User $user): array
    {
        $reasons = $this->booksReasons();

        if ($reasons !== []) {
            throw CannotPostToTheBooksException::because($reasons[0]);
        }

        return LoanWriteTransaction::run(function () use ($asOf, $expectedAdjustment, $user): array {
            $base = $this->calculator->forDate($asOf);

            $this->lockTheAllowance();
            $this->assertOpen($asOf);

            $figures = $this->calculator->withBalances($base, $this->calculator->balances($asOf, lock: true));

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
                $asOf,
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
     * check and every journal or line lock.
     */
    private function lockTheAllowance(): void
    {
        AccountingAccount::query()
            ->whereKey(AccountMap::resolve()->accountFor('allowance_credit_losses'))
            ->lockForUpdate()
            ->first();
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
