<?php

namespace App\Services;

use App\Models\AutoCreditRun;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ShareCapitalService
{
    public function processAutoCredit(User $user): AutoCreditRun
    {
        $eligiblePledges = ShareCapitalPledge::with('borrower')
            ->where('auto_credit', true)
            ->where('amount', '>', 0)
            ->get();

        return DB::transaction(function () use ($eligiblePledges, $user) {
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
        });
    }

    public function getAutoCreditStatus(): array
    {
        $allPledges = ShareCapitalPledge::with('borrower')->get();

        $activeMembers = $allPledges->filter(fn ($p) => $p->auto_credit && (float) $p->amount > 0);
        $disabledMembers = $allPledges->filter(fn ($p) => ! $p->auto_credit);
        $noPledgeMembers = $allPledges->filter(fn ($p) => (float) $p->amount === 0.0);

        $lastRun = AutoCreditRun::latest('processed_at')->first();

        return [
            'active_count' => $activeMembers->count(),
            'total_to_credit' => round($activeMembers->sum(fn ($p) => (float) $p->amount), 2),
            'disabled_count' => $disabledMembers->count(),
            'no_pledge_count' => $noPledgeMembers->count(),
            'last_run' => $lastRun ? [
                'id' => $lastRun->id,
                'total_amount' => (float) $lastRun->total_amount,
                'member_count' => $lastRun->member_count,
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
        return ShareCapitalLedger::create([
            'borrower_id' => $pledge->borrower_id,
            'date' => $date,
            'description' => $description ?? ('Manual '.$type.' entry'),
            'debit' => $type === 'debit' ? $amount : 0,
            'credit' => $type === 'credit' ? $amount : 0,
            'created_by' => $user->id,
        ]);
    }

    public function bulkManualEntries(array $entries, User $user): Collection
    {
        return DB::transaction(function () use ($entries, $user) {
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
        });
    }
}
