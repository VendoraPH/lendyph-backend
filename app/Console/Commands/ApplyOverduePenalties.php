<?php

namespace App\Console\Commands;

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

        $loans = Loan::whereIn('status', ['released', 'ongoing'])
            ->where('penalty_rate', '>', 0)
            ->whereHas('amortizationSchedules', function ($q) use ($today) {
                $q->where('due_date', '<', $today)
                    ->whereIn('status', ['pending', 'partial', 'overdue']);
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
