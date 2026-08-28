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
        //
        // Struck from the bare due date, and deliberately NOT through
        // AmortizationSchedule::pastGraceSql(), which is what decides lateness
        // for penalties, the `overdue` stamp and the loans screen's Past Due
        // tab. This is recorded rather than decided: the --days threshold is a
        // prudential figure (PAR90/NPL is conventionally measured from the due
        // date), so honouring grace would push a 7-day-grace loan's default
        // from day 90 to day 97 and change reported portfolio quality. That is
        // a provisioning decision for whoever owns the coop's policy.
        //
        // If it is ever revisited, this line and the $hasRecentDue check below
        // are complements of ONE cutoff and must move together — grace-shifting
        // only one leaves schedules that satisfy neither, and loans would
        // default that should not. Both are covered by the shared helpers:
        // pastGraceSql() here, pastGraceCutoff($loan->grace_period_days,
        // $cutoffDate) there.
        $loans = Loan::whereIn('status', ['released', 'ongoing'])
            ->whereHas('amortizationSchedules', function ($q) use ($cutoffDate) {
                $q->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->where('due_date', '<', $cutoffDate);
            })
            ->get();

        $count = 0;
        foreach ($loans as $loan) {
            // Only default if ALL unpaid schedules are past the cutoff (not just one)
            //
            // The complement of the whereHas above and on the same bare due
            // date for the same reason. These two partition the unpaid
            // schedules between them; grace-shifting one without the other
            // opens a gap that lets a loan default on the strength of
            // schedules neither side accounted for.
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
