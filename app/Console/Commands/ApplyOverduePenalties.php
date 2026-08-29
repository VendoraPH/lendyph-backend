<?php

namespace App\Console\Commands;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\RepaymentService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:apply-penalties')]
#[Description('Compute and apply penalties on all overdue loan schedules')]
class ApplyOverduePenalties extends Command
{
    public function handle(RepaymentService $repaymentService): int
    {
        $today = Carbon::today();

        // Must use the SAME definition of late as
        // RepaymentService::applyPenalties(), which is the only thing that then
        // writes anything. A bare `due_date < today` here loaded every loan
        // inside its grace period so the service could skip it — work done to
        // reach a no-op, and a candidate count that did not describe what the
        // command actually did.
        $loans = Loan::whereIn('status', Loan::ACTIVE_STATUSES)
            ->where('penalty_rate', '>', 0)
            ->whereHas('amortizationSchedules', function ($q) use ($today) {
                $q->whereRaw(AmortizationSchedule::pastGraceSql(), [$today->toDateString()])
                    // Same reason as the grace filter beside it: a loan
                    // migrated in from a coop's existing book is overdue on
                    // every schedule that predates the import, and the service
                    // will penalise none of them. Without this the whole
                    // imported book loads as candidates every night to reach a
                    // no-op, and the count reported below describes work that
                    // did not happen.
                    ->whereRaw(AmortizationSchedule::penalisableSql())
                    ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES);
            })
            ->get();

        $count = 0;
        foreach ($loans as $loan) {
            $repaymentService->applyPenalties($loan, $today);
            $count++;
        }

        $this->info("Applied penalties on {$count} loan(s) with overdue schedules.");

        return self::SUCCESS;
    }
}
