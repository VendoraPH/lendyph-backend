<?php

namespace App\Services\Branches;

use App\Models\AccountingAccount;
use App\Models\AccountingExpense;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\GCashTransaction;
use App\Models\InterbranchTransfer;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A branch's status, its main-office flag and its closure (Phase 6,
 * multi-branch decisions 2 to 4). Callers check `branches:manage` and
 * `branches:view_all` first (BranchStatusRequest).
 *
 * - Active and suspended change back and forth with a reason. The main
 *   office stays active, a closed branch stays closed, and a branch waiting
 *   to close isn't reactivated until its request is rejected or cancelled.
 * - Closing takes a second person: one user asks with an effective date (not
 *   after today) and a reason, and a different user approves. Both steps are
 *   refused while the branch is active (suspend it first, Arvin 2026-11-19)
 *   or the checklist isn't clear, and approval checks both again under the
 *   branch's row lock. The requester may cancel; another user may
 *   reject with a note.
 * - Each step writes one audit row on the branch, in words.
 *
 * Every closure step takes the branch row FOR UPDATE as its first statement,
 * the lock the writes in BranchRowLock hold FOR SHARE, so a closure and a
 * user save, journal, expense, fund transfer or reconciliation action in the
 * branch never interleave; while a request waits, those writes are refused.
 * A deadlock or lock wait timeout on a closure step, a status change or a
 * main-office move answers 409 with nothing saved.
 *
 * A suspended or closed status is enforced on writes by BranchWriteGuard and
 * the BranchTakesNew rules (MB-BE2). A status change and a closure approval
 * hold the guard's branch lock, so a guarded write never interleaves with
 * them. Interbranch transfers (MB-BE5) count both ways: one waiting for
 * approval is a pending approval, and one approved and not yet received
 * keeps both its branches open, since 1190 is still open on each.
 */
class BranchStatus
{
    /** A loan that isn't finished: being drafted, approved, or still owed. */
    public const OPEN_LOAN_STATUSES = ['draft', 'for_review', 'approved', 'released', 'ongoing', 'defaulted'];

    /** A time deposit that hasn't been paid out or rolled over yet. */
    public const OPEN_TIME_DEPOSIT_STATUSES = ['active', 'matured'];

    public function __construct(
        private TrialBalanceBuilder $trialBalance,
        private BranchWriteGuard $guard,
    ) {}

    /**
     * Suspend or reactivate a branch, with the reason its audit row carries.
     * This is the only way a status changes outside a closure: the profile
     * edit refuses a changed `is_active`.
     */
    public function change(Branch $branch, string $to, string $reason): Branch
    {
        return $this->guard->exclusively((int) $branch->getKey(), fn (): Branch => BranchRowLock::transaction(function () use ($branch, $to, $reason): Branch {
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanMove($locked, $to);

            // A branch waiting to close stays suspended until its request is
            // rejected or cancelled. Read under the branch row's lock and then
            // the request's, the order the approval and the main-office move
            // take, so a reactivation and an approval never both go ahead.
            if ($to === Branch::STATUS_ACTIVE
                && BranchClosure::query()->where('branch_id', $locked->id)->where('status', BranchClosure::STATUS_PENDING)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['branch' => ['A request to close this branch is waiting. Reject or cancel it before reactivating the branch.']]);
            }

            if ($locked->status === $to) {
                throw ValidationException::withMessages(['status' => ["This branch is already {$to}."]]);
            }

            $locked->status = $to;
            $locked->statusAuditNote = $reason;
            $locked->save();

            return $locked;
        }));
    }

    /**
     * Why a branch may not move to `$to` through a status change, if it may
     * not: closing goes through the closure, a closed branch stays closed, and
     * the main office stays active.
     *
     * @throws ValidationException
     */
    public function assertCanMove(Branch $branch, string $to): void
    {
        if ($branch->status === Branch::STATUS_CLOSED) {
            throw ValidationException::withMessages(['status' => ['A closed branch stays closed.']]);
        }

        if ($to !== Branch::STATUS_ACTIVE && $branch->is_main_office) {
            throw ValidationException::withMessages(['status' => ['The main office stays active. Make another branch the main office first.']]);
        }
    }

    /**
     * Move the main-office flag to an active branch. The old main office is
     * unflagged first (the unique `main_office_key` allows one), and each
     * branch gets its own audit row with the reason.
     */
    public function makeMainOffice(Branch $branch, string $reason): Branch
    {
        return BranchRowLock::transaction(function () use ($branch, $reason): Branch {
            $current = Branch::query()->where('is_main_office', true)->lockForUpdate()->get();
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Branch::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['branch' => ['Only an active branch can be the main office.']]);
            }

            if ($locked->is_main_office) {
                return $locked;
            }

            // A branch waiting to close can't become the main office, which
            // stays open (MB-BE1 browser check M1). Read under the branch row's
            // lock and then the request's, the closure approval's order, so a
            // move and a closure request on the same branch serialize: the
            // later one is refused in words, and no request is ever left
            // waiting on the main office.
            if (BranchClosure::query()->where('branch_id', $locked->id)->where('status', BranchClosure::STATUS_PENDING)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['branch' => ['A request to close this branch is waiting. Reject or cancel it before making it the main office.']]);
            }

            foreach ($current as $old) {
                $old->is_main_office = false;
                $old->statusAuditNote = $reason;
                $old->save();
            }

            $locked->is_main_office = true;
            $locked->statusAuditNote = $reason;
            $locked->save();

            return $locked;
        });
    }

    /**
     * What still keeps a branch open, in a fixed order. `amount` is in pesos
     * with two decimals, for money only.
     *
     * @return list<array{key: string, label: string, count: int, amount: string|null, clear: bool}>
     */
    public function checklist(Branch $branch): array
    {
        $id = $branch->getKey();
        $item = fn (string $key, string $label, int $count, ?string $amount = null): array => [
            'key' => $key, 'label' => $label, 'count' => $count, 'amount' => $amount, 'clear' => $count === 0,
        ];

        $accounts = SavingsAccount::query()->where('branch_id', $id);
        $deposits = DB::table('time_deposits')->where('branch_id', $id);
        [$moneyAccounts, $moneyCentavos] = $this->money($id);

        $pending = LoanAdjustment::query()->where('status', 'pending')
            ->whereIn('loan_id', Loan::query()->select('id')->where('branch_id', $id))
            ->count()
            + DB::table('savings_approvals')->where('status', 'pending')
                ->where(fn ($q) => $q
                    ->whereIn('account_id', (clone $accounts)->select('id'))
                    ->orWhereIn('time_deposit_id', (clone $deposits)->select('id')))
                ->count()
            + PortalRequest::query()->where('branch_id', $id)->whereIn('status', PortalRequest::OPEN_STATUSES)->count()
            + $this->transfers($id, InterbranchTransfer::STATUS_PENDING);

        return [
            $item('open_loans', 'Open loans', Loan::query()->where('branch_id', $id)->whereIn('status', self::OPEN_LOAN_STATUSES)->count()),
            $item('open_savings_accounts', 'Open savings accounts', (clone $accounts)->where('status', '!=', SavingsAccount::STATUS_CLOSED)->count()),
            $item('open_time_deposits', 'Open time deposits', (clone $deposits)->whereIn('status', self::OPEN_TIME_DEPOSIT_STATUSES)->count()),
            $item('money_in_accounts', 'Money in its cash and bank accounts', $moneyAccounts, $moneyAccounts === 0 ? null : self::pesos($moneyCentavos)),
            $item('pending_approvals', 'Pending approvals and requests', $pending),
            $item('transfers_in_transit', 'Transfers in transit', $this->transfers($id, InterbranchTransfer::STATUS_IN_TRANSIT)),
            $item('pending_gcash_cash_ins', 'GCash Cash Ins not yet paid', $this->pendingCashIns($id)),
            $item('unpaid_payables', 'Unpaid payables', $this->unpaidPayables($id)),
            $item('assigned_users', 'Assigned users', DB::table('branch_user')->where('branch_id', $id)->count()),
        ];
    }

    public function requestClosure(Branch $branch, string $effectiveOn, string $reason, User $by): BranchClosure
    {
        return BranchRowLock::transaction(function () use ($branch, $effectiveOn, $reason, $by): BranchClosure {
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();

            $this->assertClosable($locked);

            if ($locked->opened_on !== null && $effectiveOn < $locked->opened_on->toDateString()) {
                throw ValidationException::withMessages(['effective_on' => ['The closing date cannot be before the branch opened.']]);
            }

            if (BranchClosure::query()->where('branch_id', $locked->id)->where('status', BranchClosure::STATUS_PENDING)->exists()) {
                $this->alreadyWaiting();
            }

            $this->assertChecklistClear($locked);

            try {
                $closure = BranchClosure::create([
                    'branch_id' => $locked->id,
                    'status' => BranchClosure::STATUS_PENDING,
                    'effective_on' => $effectiveOn,
                    'reason' => $reason,
                    'requested_by' => $by->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                $this->alreadyWaiting();
            }

            AuditLogService::log(
                'branch_closure_requested',
                $locked,
                null,
                ['closure_id' => $closure->id, 'effective_on' => $effectiveOn, 'reason' => $reason],
                sprintf('Closing branch %s on %s was requested. Reason: %s', $locked->auditName(), self::dateWords($effectiveOn), $reason),
            );

            return $closure;
        });
    }

    public function approveClosure(Branch $branch, User $by, ?string $note): BranchClosure
    {
        return $this->guard->exclusively((int) $branch->getKey(), fn (): BranchClosure => BranchRowLock::transaction(function () use ($branch, $by, $note): BranchClosure {
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $closure = $this->waiting($locked);

            if ((int) $closure->requested_by === (int) $by->id) {
                throw new HttpException(403, 'You asked to close this branch, so someone else has to approve it.');
            }

            $this->assertClosable($locked);
            $this->assertChecklistClear($locked);

            $this->decide($locked, $closure, BranchClosure::STATUS_APPROVED, $by, $note);

            $locked->status = Branch::STATUS_CLOSED;
            $locked->closed_on = $closure->effective_on;
            $locked->statusAuditNote = sprintf(
                'on %s, requested by %s and approved by %s.',
                self::dateWords($closure->effective_on->toDateString()),
                $closure->requester->full_name,
                $by->full_name,
            );
            $locked->save();

            return $closure;
        }));
    }

    public function rejectClosure(Branch $branch, User $by, string $note): BranchClosure
    {
        return BranchRowLock::transaction(function () use ($branch, $by, $note): BranchClosure {
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $closure = $this->waiting($locked);

            if ((int) $closure->requested_by === (int) $by->id) {
                throw new HttpException(403, 'You asked to close this branch, so someone else has to reject it. You can cancel it instead.');
            }

            return $this->decide($locked, $closure, BranchClosure::STATUS_REJECTED, $by, $note);
        });
    }

    public function cancelClosure(Branch $branch, User $by): BranchClosure
    {
        return BranchRowLock::transaction(function () use ($branch, $by): BranchClosure {
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $closure = $this->waiting($locked);

            if ((int) $closure->requested_by !== (int) $by->id) {
                throw new HttpException(403, 'Only the person who asked to close this branch can cancel the request.');
            }

            return $this->decide($locked, $closure, BranchClosure::STATUS_CANCELLED, $by, null);
        });
    }

    /** The request waiting on this branch, locked, or 404 in words. */
    public function waiting(Branch $branch): BranchClosure
    {
        $closure = BranchClosure::query()
            ->where('branch_id', $branch->getKey())
            ->where('status', BranchClosure::STATUS_PENDING)
            ->lockForUpdate()
            ->first();

        if ($closure === null) {
            throw new HttpException(404, 'No request to close this branch is waiting.');
        }

        return $closure;
    }

    private function decide(Branch $branch, BranchClosure $closure, string $status, User $by, ?string $note): BranchClosure
    {
        $closure->update([
            'status' => $status,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        $what = match ($status) {
            BranchClosure::STATUS_APPROVED => 'approved',
            BranchClosure::STATUS_REJECTED => 'rejected',
            default => 'cancelled',
        };

        AuditLogService::log(
            "branch_closure_{$what}",
            $branch,
            ['closure_id' => $closure->id, 'status' => BranchClosure::STATUS_PENDING],
            ['closure_id' => $closure->id, 'status' => $status, 'decision_note' => $note],
            sprintf(
                'The request to close branch %s on %s was %s.%s',
                $branch->auditName(),
                self::dateWords($closure->effective_on->toDateString()),
                $what,
                $note === null ? '' : " Note: {$note}",
            ),
        );

        return $closure;
    }

    /**
     * Whether a branch may be asked to close, or its closure approved, apart
     * from the checklist: not closed, not the main office, and suspended first
     * (Arvin, 2026-11-19). Read on the branch row its caller locked, so a
     * status change can't slip in between.
     */
    public function closable(Branch $branch): bool
    {
        return $branch->status === Branch::STATUS_SUSPENDED && ! $branch->is_main_office;
    }

    /** @throws ValidationException */
    private function assertClosable(Branch $branch): void
    {
        if ($branch->status === Branch::STATUS_CLOSED) {
            throw ValidationException::withMessages(['branch' => ['This branch is already closed.']]);
        }

        if ($branch->is_main_office) {
            throw ValidationException::withMessages(['branch' => ['The main office stays open. Make another branch the main office first.']]);
        }

        if (! $this->closable($branch)) {
            throw ValidationException::withMessages(['branch' => ['This branch is still active. Suspend it before asking to close it.']]);
        }
    }

    /** @throws ValidationException */
    private function assertChecklistClear(Branch $branch): void
    {
        $open = array_values(array_filter($this->checklist($branch), fn (array $item): bool => ! $item['clear']));

        if ($open === []) {
            return;
        }

        $parts = array_map(fn (array $item): string => match ($item['key']) {
            'open_loans' => self::counted($item['count'], 'open loan'),
            'open_savings_accounts' => self::counted($item['count'], 'open savings account'),
            'open_time_deposits' => self::counted($item['count'], 'open time deposit'),
            'money_in_accounts' => self::pesosWords((string) $item['amount']).' in its cash and bank accounts',
            'pending_approvals' => $item['count'] === 1 ? '1 pending approval or request' : "{$item['count']} pending approvals or requests",
            'transfers_in_transit' => self::counted($item['count'], 'transfer').' in transit',
            'pending_gcash_cash_ins' => self::counted($item['count'], 'GCash Cash In').' not yet paid',
            'unpaid_payables' => self::counted($item['count'], 'unpaid payable'),
            'assigned_users' => self::counted($item['count'], 'assigned user'),
        }, $open);

        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts).' and '.$last;

        throw ValidationException::withMessages(['branch' => ["This branch can't be closed yet: {$list}."]]);
    }

    /**
     * How many of the branch's money accounts (those with a `cash_kind`) hold
     * a balance in its posted journals, and their total in centavos, by the
     * trial balance's own aggregate (as the Cash & Bank screen reads it).
     *
     * @return array{0: int, 1: int}
     */
    private function money(int $branchId): array
    {
        $ids = AccountingAccount::query()->whereNotNull('cash_kind')->where('is_group', false)->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();

        $balances = array_filter($this->trialBalance->signedBalances(null, $branchId, $ids), fn (int $centavos): bool => $centavos !== 0);

        return [count($balances), array_sum($balances)];
    }

    /**
     * GCash Cash Ins not yet paid in the branch: walk-in rows recorded in it
     * and its members' rows (finish run round 2, review of S2). Marking one
     * paid posts cash, so a closure waits for them, as a member transfer does
     * (MemberTransfer).
     */
    private function pendingCashIns(int $branchId): int
    {
        return GCashTransaction::query()
            ->where('type', 'cash_in')
            ->where('status', 'pending')
            ->where(fn ($q) => $q
                ->where('branch_id', $branchId)
                ->orWhereIn('borrower_id', Borrower::query()->select('id')->where('branch_id', $branchId)))
            ->count();
    }

    /**
     * Expenses recorded in the branch as owed and not yet settled in full.
     * Paying one posts into the branch, which a closed branch refuses, so a
     * closure waits for them (finish run round 2, review of #369). One whose
     * journal was reversed by hand on the Journals screen keeps its unpaid
     * status but owes nothing in the books, so it doesn't count; one with no
     * journal does.
     */
    private function unpaidPayables(int $branchId): int
    {
        return AccountingExpense::query()
            ->where('branch_id', $branchId)
            ->where('settlement_status', '!=', 'paid')
            ->whereDoesntHave('journal', fn ($q) => $q->where('status', 'reversed'))
            ->count();
    }

    /** Interbranch transfers in `$status` with the branch as their source or destination. */
    private function transfers(int $branchId, string $status): int
    {
        return InterbranchTransfer::query()
            ->where('status', $status)
            ->where(fn ($q) => $q->where('source_branch_id', $branchId)->orWhere('destination_branch_id', $branchId))
            ->count();
    }

    private function alreadyWaiting(): never
    {
        throw new HttpException(409, 'A request to close this branch is already waiting. Approve, reject or cancel it first.');
    }

    private static function counted(int $count, string $noun): string
    {
        return $count === 1 ? "1 {$noun}" : "{$count} {$noun}s";
    }

    private static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    private static function pesosWords(string $pesos): string
    {
        $value = (float) $pesos;

        return ($value < 0 ? '-₱' : '₱').number_format(abs($value), 2);
    }

    private static function dateWords(string $date): string
    {
        return Carbon::parse($date)->format('M j, Y');
    }
}
