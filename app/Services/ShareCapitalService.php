<?php

namespace App\Services;

use App\Models\AutoCreditRun;
use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Branches\BranchRowLock;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ShareCapitalService
{
    /**
     * How a standalone entry's money can move: the Record Payment methods
     * (StoreRepaymentRequest). Each posts to its own settlement account.
     */
    public const ENTRY_METHODS = ['cash', 'gcash', 'maya', 'bank_transfer', 'online'];

    /** The settlement accounts an auto-credit run can be picked through. */
    public const AUTO_CREDIT_METHODS = ['cash', 'gcash', 'maya', 'bank_transfer'];

    /**
     * Why a run stops at a member's branch that is closed or waiting to close
     * (BranchRowLock's refusal, `Branch "…" (…) is closed, so …`).
     */
    public const AUTO_CREDIT_BRANCH_CONSEQUENCE = "auto-credit can't credit its members' share capital. Switch their auto-credit off, or transfer them, first";

    /** The 409 when the member moved branch while their entry was being saved. */
    public const MEMBER_MOVED = 'This member was moved to another branch while the entry was being saved. Nothing was saved; reload and try again.';

    /** The 409 when a batch's member moved branch while it was being saved. */
    public const BATCH_MEMBER_MOVED = 'A member in this batch was moved to another branch while it was being saved. Nothing was posted; reload and try again.';

    /** The 409 when a member moved branch while the run was being saved. */
    public const AUTO_CREDIT_MEMBER_MOVED = 'A member was moved to another branch while auto-credit was running. Nothing was posted; run it again.';

    /** The 409 when the member withdrew while their entry was being saved. */
    public const MEMBER_LEFT = 'This member withdrew from the cooperative while the entry was being saved, so no share capital is recorded for them. Nothing was saved.';

    /** The 409 when a batch's member withdrew while it was being saved. */
    public const BATCH_MEMBER_LEFT = 'A member in this batch withdrew from the cooperative while it was being saved, so no share capital is recorded for them. Nothing was posted; take them out of the batch and try again.';

    /** The 409 when a member's membership ended while the run was being saved. */
    public const AUTO_CREDIT_MEMBER_LEFT = 'A member\'s membership ended while auto-credit was running, so it no longer credits them. Nothing was posted; run it again.';

    public function __construct(private AutomaticPoster $poster, private CashReferences $cashReferences) {}

    /**
     * Post one credit per eligible pledge and record the run.
     *
     * `forCurrentMembers()` is a financial control, not cosmetics: toggleAutoCredit()
     * performs no status check, so a `pending` applicant's auto-created pledge
     * can already carry `auto_credit = true` with a non-zero amount. Without
     * this scope the run would post real share_capital_ledger credits for
     * somebody who is not a member — and now that the pledge list hides those
     * rows, the flag would not even be visible to switch back off.
     *
     * Each member's credit posts as a contribution, one journal per row,
     * through the settlement account picked on the run (`$method`, the bank by
     * default, as auto-pay settles), in the run's transaction: a run that
     * fails leaves no row and no journal.
     *
     * Its first statement locks the branches of the members it credits
     * (BranchRowLock): a member's branch that is closed or waiting to close
     * stops the whole run, in words, with nothing posted. The members' branches
     * and statuses are then read again under their rows' shared locks: a member
     * moved since, or no longer a current member, answers 409. Each row posts
     * in the branch that was locked for it. The rows stay unnumbered: a run's
     * credits get no CSH reference (finish run round 2, decision 5).
     */
    public function processAutoCredit(
        User $user,
        ?User $scopeTo = null,
        string $method = 'bank_transfer',
        ?string $referenceNumber = null,
    ): AutoCreditRun {
        $eligiblePledges = ShareCapitalPledge::with('borrower')
            ->forCurrentMembers()
            ->when($scopeTo !== null, fn ($q) => $q->whereHas('borrower', fn ($b) => $b->visibleTo($scopeTo)))
            ->where('auto_credit', true)
            ->where('amount', '>', 0)
            ->get();

        $branchIds = $eligiblePledges->map(fn (ShareCapitalPledge $pledge): ?int => $pledge->borrower?->branch_id)->all();

        // Never tried again. The pledges were read before this transaction, so
        // a retry after another run committed would credit each pledge twice.
        // The loser of a collision answers 409 and posts nothing.
        return LoanWriteTransaction::run(function () use ($eligiblePledges, $branchIds, $user, $method, $referenceNumber) {
            BranchRowLock::lock(['branch' => $branchIds], ['branch' => self::AUTO_CREDIT_BRANCH_CONSEQUENCE]);
            $this->assertMembersStayed(
                $eligiblePledges->mapWithKeys(fn (ShareCapitalPledge $pledge): array => [$pledge->borrower_id => $pledge->borrower?->branch_id])->all(),
                self::AUTO_CREDIT_MEMBER_MOVED,
                fn (?string $status): bool => self::isCurrentMember($status),
                self::AUTO_CREDIT_MEMBER_LEFT,
            );

            $today = now()->toDateString();
            $totalAmount = 0.0;

            $rows = [];

            foreach ($eligiblePledges as $pledge) {
                $totalAmount += (float) $pledge->amount;

                $rows[] = ShareCapitalLedger::create([
                    'borrower_id' => $pledge->borrower_id,
                    'branch_id' => $pledge->borrower?->branch_id,
                    'date' => $today,
                    'description' => 'Monthly pledge - auto-credit',
                    'debit' => 0,
                    'credit' => $pledge->amount,
                    'method' => $method,
                    'reference_number' => $referenceNumber,
                    'created_by' => $user->id,
                ]);
            }

            // Every row first, then the journals: the journal-number lock is
            // taken by the first posting and held to commit, so posting last
            // keeps it for the shortest time.
            $this->postEntries($rows);

            return AutoCreditRun::create([
                'total_amount' => round($totalAmount, 2),
                'member_count' => $eligiblePledges->count(),
                'processed_at' => now(),
                'processed_by' => $user->id,
                'status' => 'completed',
            ]);
        }, GeneratedNumberWrite::SHARE_CAPITAL_REFERENCE_INDEX, GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
    }

    /**
     * Auto-credit dashboard figures.
     *
     * Scoped to current members (withdrawn and deceased left out) so it
     * agrees with what processAutoCredit() would actually post; a `pending` applicant must not show up under `no_pledge_members`
     * as somebody to chase for a pledge amount.
     *
     * With `$scopeTo`, only the pledges of members in that user's branches
     * (Borrower::visibleTo), the same set processAutoCredit() posts for them.
     *
     * @return array<string, mixed>
     */
    public function getAutoCreditStatus(?User $scopeTo = null): array
    {
        $allPledges = ShareCapitalPledge::with('borrower')
            ->forCurrentMembers()
            ->when($scopeTo !== null, fn ($q) => $q->whereHas('borrower', fn ($b) => $b->visibleTo($scopeTo)))
            ->get();

        $activeMembers = $allPledges->filter(fn ($p) => $p->auto_credit && (float) $p->amount > 0);
        $disabledMembers = $allPledges->filter(fn ($p) => ! $p->auto_credit);
        $noPledgeMembers = $allPledges->filter(fn ($p) => (float) $p->amount === 0.0);

        $lastRun = AutoCreditRun::latest('processed_at')->first();

        // A run records no branches, so its totals cover whatever its caller
        // could see, often the whole organisation. A caller limited to some
        // branches gets the run's date and status but not those totals.
        $runTotalsVisible = $scopeTo === null || app(BranchAccess::class)->for($scopeTo) === null;

        return [
            'active_count' => $activeMembers->count(),
            'total_to_credit' => round($activeMembers->sum(fn ($p) => (float) $p->amount), 2),
            'disabled_count' => $disabledMembers->count(),
            'no_pledge_count' => $noPledgeMembers->count(),
            'last_run' => $lastRun ? [
                'id' => $lastRun->id,
                'total_amount' => $runTotalsVisible ? (float) $lastRun->total_amount : null,
                'member_count' => $runTotalsVisible ? $lastRun->member_count : null,
                'processed_at' => $lastRun->processed_at?->toDateTimeString(),
                'status' => $lastRun->status,
            ] : null,
            'active_members' => $activeMembers->map(fn ($p) => [
                'id' => $p->id,
                'borrower_name' => $p->borrower?->full_name,
                'pledge_amount' => (float) $p->amount,
            ])->values(),
            'disabled_members' => $disabledMembers->map(fn ($p) => [
                'id' => $p->id,
                'borrower_name' => $p->borrower?->full_name,
                'pledge_amount' => (float) $p->amount,
            ])->values(),
            'no_pledge_members' => $noPledgeMembers->map(fn ($p) => [
                'id' => $p->id,
                'borrower_name' => $p->borrower?->full_name,
            ])->values(),
        ];
    }

    /**
     * One entry on a pledge's member, posted in the row's own transaction.
     */
    public function createManualEntry(
        ShareCapitalPledge $pledge,
        float $amount,
        string $type,
        string $date,
        User $user,
        ?string $description = null,
        string $method = 'cash',
        ?string $referenceNumber = null,
    ): ShareCapitalLedger {
        return $this->createEntry(
            $pledge->borrower_id,
            $amount,
            $type,
            $date,
            $user,
            $description ?? ('Manual '.$type.' entry'),
            $method,
            $referenceNumber,
        );
    }

    /**
     * One standalone entry for a member: the row and its journal (Dr the
     * method's account / Cr 3060 for a credit, the reverse for a debit) in one
     * transaction, so a refused posting leaves no row. Tried again on a
     * reference collision (GeneratedNumberWrite), which is safe here: each try
     * starts only after the one before it was fully rolled back.
     *
     * In order (finish run round 2):
     * 1. the member's branch row (BranchRowLock), the write's first statement:
     *    a branch that is closed or waiting to close takes nothing, in words,
     *    on `borrower_id`. The row posts in that branch. Then the member's row
     *    FOR SHARE: a member moved to another branch since it was read, or who
     *    withdrew meanwhile, answers 409, with nothing saved.
     * 2. Cash: the CSH series row, for the entry's `CSH-[id]-[year]-[0001]`
     *    (CashReferences, decision 5). A typed reference is ignored for cash;
     *    any other method keeps the one it was given.
     * 3. The row, then its journal.
     *
     * The member's branch and the series' owner are read before the
     * transaction, so its first plain read comes after those locks.
     */
    public function createEntry(
        int $borrowerId,
        float|string $amount,
        string $type,
        string $date,
        User $user,
        string $description,
        string $method = 'cash',
        ?string $referenceNumber = null,
    ): ShareCapitalLedger {
        $branchId = $this->memberBranch($borrowerId);
        $owner = $method === 'cash' ? $this->cashReferences->owner() : null;

        return GeneratedNumberWrite::run(function () use ($borrowerId, $branchId, $owner, $amount, $type, $date, $user, $description, $method, $referenceNumber): ShareCapitalLedger {
            BranchRowLock::lock(['borrower_id' => $branchId]);
            $this->assertMembersStayed([$borrowerId => $branchId], self::MEMBER_MOVED, fn (?string $status): bool => self::takesEntries($status), self::MEMBER_LEFT);

            $reference = $method === 'cash'
                ? $this->cashReferences->assign(CashReferences::SHARE_CAPITAL, $owner, [$date], 'reference_number')[0]
                : $referenceNumber;

            $row = ShareCapitalLedger::create([
                ...$this->entryAttributes($borrowerId, $amount, $type, $date, $user, $description, $method, $reference),
                'branch_id' => $branchId,
            ]);
            $this->postEntries([$row]);

            return $row;
        }, GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
    }

    /**
     * A standalone row's attributes.
     *
     * @return array<string, mixed>
     */
    private function entryAttributes(
        int $borrowerId,
        float|string $amount,
        string $type,
        string $date,
        User $user,
        string $description,
        string $method,
        ?string $referenceNumber,
    ): array {
        return [
            'borrower_id' => $borrowerId,
            'date' => $date,
            'description' => $description,
            'debit' => $type === 'debit' ? $amount : 0,
            'credit' => $type === 'credit' ? $amount : 0,
            'method' => $method,
            'reference_number' => $referenceNumber,
            'created_by' => $user->id,
        ];
    }

    /**
     * Entries for many pledges, all through one method, one journal per row.
     * A cash batch numbers each row in turn with its own CSH reference, in the
     * batch's order (finish run round 2, decision 5); any other method stores
     * the batch's one reference on every row.
     *
     * The first statement locks every row's member branch in one statement
     * (BranchRowLock): one closed or closing branch refuses the whole batch,
     * in words, on that row's `entries.N.pledge_id`. The members' branches
     * and statuses are then read again under their rows' shared locks: a
     * member moved since, or who withdrew meanwhile, answers 409 with nothing
     * posted. The pledges, their members' branches and the series' owner are
     * read before the transaction.
     *
     * @param  list<array{pledge_id: int, amount: float|int|string, type: string, date: string, description?: string|null}>  $entries
     */
    public function bulkManualEntries(array $entries, User $user, string $method = 'cash', ?string $referenceNumber = null): Collection
    {
        $entries = array_values($entries);
        $pledges = ShareCapitalPledge::query()->with('borrower:id,branch_id')->findMany(array_column($entries, 'pledge_id'))->keyBy('id');
        $branches = [];
        $members = [];

        foreach ($entries as $i => $entry) {
            $pledge = $pledges->get($entry['pledge_id']);
            $branches["entries.{$i}.pledge_id"] = $pledge?->borrower?->branch_id;

            if ($pledge !== null) {
                $members[$pledge->borrower_id] = $pledge->borrower?->branch_id;
            }
        }

        $owner = $method === 'cash' ? $this->cashReferences->owner() : null;

        // Never tried again, as processAutoCredit() isn't: a retry could post the
        // whole batch a second time when the same batch was submitted twice. The
        // loser of a collision answers 409 and posts nothing: no row and no
        // journal, since both are written in this one transaction.
        return LoanWriteTransaction::run(function () use ($entries, $pledges, $branches, $members, $owner, $user, $method, $referenceNumber) {
            BranchRowLock::lock($branches);
            $this->assertMembersStayed($members, self::BATCH_MEMBER_MOVED, fn (?string $status): bool => self::takesEntries($status), self::BATCH_MEMBER_LEFT);

            $references = $method === 'cash'
                ? $this->cashReferences->assign(CashReferences::SHARE_CAPITAL, $owner, array_column($entries, 'date'), 'reference_number')
                : array_fill(0, count($entries), $referenceNumber);

            $results = new Collection;

            foreach ($entries as $i => $entry) {
                $pledge = $pledges->get($entry['pledge_id'])
                    ?? throw (new ModelNotFoundException)->setModel(ShareCapitalPledge::class, [$entry['pledge_id']]);

                $results->push(ShareCapitalLedger::create([
                    ...$this->entryAttributes(
                        $pledge->borrower_id,
                        (float) $entry['amount'],
                        $entry['type'],
                        $entry['date'],
                        $user,
                        $entry['description'] ?? ('Manual '.$entry['type'].' entry'),
                        $method,
                        $references[$i],
                    ),
                    'branch_id' => $branches["entries.{$i}.pledge_id"],
                ]));
            }

            // As processAutoCredit(): the rows first, then their journals.
            $this->postEntries($results->all());

            return $results;
        }, GeneratedNumberWrite::SHARE_CAPITAL_REFERENCE_INDEX, GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
    }

    /**
     * Read the members' branches and statuses again, in one statement under
     * their rows' shared locks, right after their branch rows were locked.
     * Each answers 409 in words, with nothing saved:
     *
     * - a member who can no longer take the entry (`$mayTake`): their
     *   membership was ended at the same moment (MembershipStatus::end()
     *   locks the member FOR UPDATE, so this read waits for it and sees it);
     * - a member moved to another branch since the read before the
     *   transaction (a member transfer at the same moment), so nothing posts
     *   in a branch that wasn't locked and checked.
     *
     * The member rows stay locked until the write commits, so a transfer or
     * an ended membership that starts now waits for it.
     *
     * @param  array<int, int|null>  $expected  member id => the branch read before the transaction
     * @param  Closure(string|null): bool  $mayTake  whether a member with this status still takes the entry
     */
    private function assertMembersStayed(array $expected, string $moved, Closure $mayTake, string $left): void
    {
        if ($expected === []) {
            return;
        }

        $now = Borrower::query()->whereIn('id', array_keys($expected))->orderBy('id')->sharedLock()->get(['id', 'branch_id', 'status'])->keyBy('id');

        foreach ($expected as $memberId => $branchId) {
            $member = $now->get($memberId);

            if ($member === null || ! $mayTake($member->status)) {
                throw LoanWriteTransaction::conflict($left);
            }

            if (($member->branch_id === null ? null : (int) $member->branch_id) !== $branchId) {
                throw LoanWriteTransaction::conflict($moved);
            }
        }
    }

    /**
     * Whether a member with this status takes a standalone entry, as the
     * requests decide (ShareCapitalPledgeController::assertBorrowerIsMember(),
     * StoreShareCapitalLedgerRequest): not before their registration is
     * approved and not once they withdrew. A deceased member's estate still
     * settles through an entry (C1 review, M1).
     */
    private static function takesEntries(?string $status): bool
    {
        return $status !== null && $status !== 'withdrawn' && ! in_array($status, Borrower::NON_MEMBER_STATUSES, true);
    }

    /**
     * Whether a member with this status is still credited by auto-credit:
     * a current member (Borrower::scopeCurrentMembers()), whose membership
     * hasn't ended.
     */
    private static function isCurrentMember(?string $status): bool
    {
        return $status !== null && ! in_array($status, [...Borrower::NON_MEMBER_STATUSES, ...Borrower::FORMER_MEMBER_STATUSES], true);
    }

    /** The member's branch, where a standalone entry for them posts. */
    private function memberBranch(int $borrowerId): ?int
    {
        $branchId = Borrower::query()->whereKey($borrowerId)->value('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }

    /**
     * Posts each standalone row, one journal per row, through its own method.
     * The caller holds the transaction, so a refused posting takes every row
     * with it. Only for rows with no `repayment_id` or `loan_id`: those are
     * already in their payment's or release's journal, and the poster refuses
     * them.
     *
     * @param  list<ShareCapitalLedger>  $rows
     */
    private function postEntries(array $rows): void
    {
        foreach ($rows as $row) {
            $this->poster->shareCapitalEntry($row, (string) $row->method, (int) $row->created_by);
        }
    }
}
