<?php

namespace App\Services;

use App\Models\AutoCreditRun;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ShareCapitalService
{
    /**
     * Post one credit per eligible pledge and record the run.
     *
     * `forMembers()` is a financial control, not cosmetics: toggleAutoCredit()
     * performs no status check, so a `pending` applicant's auto-created pledge
     * can already carry `auto_credit = true` with a non-zero amount. Without
     * this scope the run would post real share_capital_ledger credits for
     * somebody who is not a member — and now that the pledge list hides those
     * rows, the flag would not even be visible to switch back off.
     */
    public function processAutoCredit(User $user, ?User $scopeTo = null): AutoCreditRun
    {
        $eligiblePledges = ShareCapitalPledge::with('borrower')
            ->forMembers()
            ->when($scopeTo !== null, fn ($q) => $q->whereHas('borrower', fn ($b) => $b->visibleTo($scopeTo)))
            ->where('auto_credit', true)
            ->where('amount', '>', 0)
            ->get();

        return GeneratedNumberWrite::run(function () use ($eligiblePledges, $user) {
            $today = now()->toDateString();
            $totalAmount = 0.0;

            foreach ($eligiblePledges as $pledge) {
                $totalAmount += (float) $pledge->amount;

                ShareCapitalLedger::create([
                    'borrower_id' => $pledge->borrower_id,
                    'date' => $today,
                    'description' => 'Monthly pledge - auto-credit',
                    'debit' => 0,
                    'credit' => $pledge->amount,
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
        }, GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
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

    public function createManualEntry(
        ShareCapitalPledge $pledge,
        float $amount,
        string $type,
        string $date,
        User $user,
        ?string $description = null,
    ): ShareCapitalLedger {
        return GeneratedNumberWrite::run(fn (): ShareCapitalLedger => ShareCapitalLedger::create([
            'borrower_id' => $pledge->borrower_id,
            'date' => $date,
            'description' => $description ?? ('Manual '.$type.' entry'),
            'debit' => $type === 'debit' ? $amount : 0,
            'credit' => $type === 'credit' ? $amount : 0,
            'created_by' => $user->id,
        ]), GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
    }

    public function bulkManualEntries(array $entries, User $user): Collection
    {
        return GeneratedNumberWrite::run(function () use ($entries, $user) {
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
                ));
            }

            return $results;
        }, GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT);
    }
}
