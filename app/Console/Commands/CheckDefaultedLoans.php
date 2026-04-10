<?php

namespace App\Console\Commands;

use App\Models\Loan;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:check-defaulted {--days=90 : Days overdue before marking as defaulted}')]
#[Description('Mark loans as defaulted when all unpaid schedules are overdue past threshold')]
class CheckDefaultedLoans extends Command
{
    public function handle(): int
    {
        $thresholdDays = (int) $this->option('days');
        $cutoffDate = Carbon::today()->subDays($thresholdDays);

        // Find released/ongoing loans where the EARLIEST unpaid schedule is past the threshold
        $loans = Loan::whereIn('status', ['released', 'ongoing'])
            ->whereHas('amortizationSchedules', function ($q) use ($cutoffDate) {
                $q->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->where('due_date', '<', $cutoffDate);
            })
            ->get();

        $count = 0;
        foreach ($loans as $loan) {
            // Only default if ALL unpaid schedules are past the cutoff (not just one)
            $hasRecentDue = $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->where('due_date', '>=', $cutoffDate)
                ->exists();

            if (! $hasRecentDue) {
                $loan->update(['status' => 'defaulted']);
                $count++;
            }
        }

        $this->info("Marked {$count} loan(s) as defaulted (>{$thresholdDays} days overdue).");

        return self::SUCCESS;
    }
}
