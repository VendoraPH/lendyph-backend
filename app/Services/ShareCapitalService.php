<?php

namespace App\Services;

use App\Models\AutoCreditRun;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use Illuminate\Database\Eloquent\Collection;

class ShareCapitalService
{
    /**
     * How a standalone entry's money can move: the Record Payment methods
     * (StoreRepaymentRequest). Each posts to its own settlement account.
     */
    public const ENTRY_METHODS = ['cash', 'gcash', 'maya', 'bank_transfer', 'online'];

    /** The settlement accounts an auto-credit run can be picked through. */
    public const AUTO_CREDIT_METHODS = ['cash', 'gcash', 'maya', 'bank_transfer'];

    public function __construct(private AutomaticPoster $poster) {}

    /**
     * Post one credit per eligible pledge and record the run.
     *
     * `forMembers()` is a financial control, not cosmetics: toggleAutoCredit()
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
     */
    public function processAutoCredit(
        User $user,
        ?User $scopeTo = null,
        string $method = 'bank_transfer',
        ?string $referenceNumber = null,
    ): AutoCreditRun {
        $eligiblePledges = ShareCapitalPledge::with('borrower')
            ->forMembers()
            ->when($scopeTo !== null, fn ($q) => $q->whereHas('borrower', fn ($b) => $b->visibleTo($scopeTo)))
            ->where('auto_credit', true)
            ->where('amount', '>', 0)
            ->get();

        // Never tried again. The pledges were read before this transaction, so
        // a retry after another run committed would credit each pledge twice.
        // The loser of a collision answers 409 and posts nothing.
        return LoanWriteTransaction::run(function () use ($eligiblePledges, $user, $method, $referenceNumber) {
            $today = now()->toDateString();
            $totalAmount = 0.0;

            foreach ($eligiblePledges as $pledge) {
                $totalAmount += (float) $pledge->amount;

                $this->writeEntry([
                    'borrower_id' => $pledge->borrower_id,
                    'date' => $today,
                    'description' => 'Monthly pledge - auto-credit',
                    'debit' => 0,
                    'credit' => $pledge->amount,
                    'method' => $method,
                    'reference_number' => $referenceNumber,
                    'created_by' => $user->id,
                ]);
            }

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
     * Member-scoped so it agrees with what processAutoCredit() would actually
     * post; a `pending` applicant must not show up under `no_pledge_members`
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
            ->forMembers()
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
        return GeneratedNumberWrite::run(fn (): ShareCapitalLedger => $this->writeEntry([
            'borrower_id' => $borrowerId,
            'date' => $date,
            'description' => $description,
            'debit' => $type === 'debit' ? $amount : 0,
            'credit' => $type === 'credit' ? $amount : 0,
            'method' => $method,
            'reference_number' => $referenceNumber,
            'created_by' => $user->id,
        ]), GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
    }

    /**
     * Entries for many pledges, all through one method and reference, one
     * journal per row.
     *
     * @param  list<array{pledge_id: int, amount: float|int|string, type: string, date: string, description?: string|null}>  $entries
     */
    public function bulkManualEntries(array $entries, User $user, string $method = 'cash', ?string $referenceNumber = null): Collection
    {
        // Never tried again, as processAutoCredit() isn't: a retry could post the
        // whole batch a second time when the same batch was submitted twice. The
        // loser of a collision answers 409 and posts nothing: no row and no
        // journal, since both are written in this one transaction.
        return LoanWriteTransaction::run(function () use ($entries, $user, $method, $referenceNumber) {
            $results = new Collection;

            foreach ($entries as $entry) {
                $pledge = ShareCapitalPledge::findOrFail($entry['pledge_id']);

                $results->push($this->createManualEntry(
                    $pledge,
                    (float) $entry['amount'],
                    $entry['type'],
                    $entry['date'],
                    $user,
                    $entry['description'] ?? null,
                    $method,
                    $referenceNumber,
                ));
            }

            return $results;
        }, GeneratedNumberWrite::SHARE_CAPITAL_REFERENCE_INDEX, GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
    }

    /**
     * Writes a standalone row and posts it. The caller holds the transaction.
     * Only for rows with no `repayment_id` or `loan_id`: those are already in
     * their payment's or release's journal, and the poster refuses them.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeEntry(array $attributes): ShareCapitalLedger
    {
        $row = ShareCapitalLedger::create($attributes);

        $this->poster->shareCapitalEntry($row, $attributes['method'], $attributes['created_by']);

        return $row;
    }
}
