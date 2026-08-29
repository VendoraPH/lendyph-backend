<?php

namespace App\Console\Commands;

use App\Models\AmortizationSchedule;
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
        //
        // AmortizationSchedule::penalisableSql() is applied here and on the
        // complement below. It is a different KIND of rule from grace: it does
        // not shift a cutoff, it removes schedules from the population
        // entirely. A loan migrated in from a coop's existing book arrives with
        // due dates already months old, so without this it would be marked
        // defaulted the night after the import, on arrears the coop was already
        // carrying and had already provisioned for.
        //
        // THIS half is the load-bearing one. Drop it and a loan qualifies on
        // pre-import arrears alone, with nothing below able to hold it back.
        $loans = Loan::whereIn('status', ['released', 'ongoing'])
            ->whereHas('amortizationSchedules', function ($q) use ($cutoffDate) {
                $q->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->where('due_date', '<', $cutoffDate)
                    ->whereRaw(AmortizationSchedule::penalisableSql());
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
            //
            // Written as the SAME query with the comparison flipped, rather
            // than as a plain exists() on the relation, so that being
            // complements is visible instead of remembered. Going back through
            // `loans` is what puts `loans` in scope for
            // AmortizationSchedule::penalisableSql(), which reads a column on
            // that table — the relation query alone selects from
            // `amortization_schedules` only, and the alternative would be a
            // fourth hand-rolled copy of the baseline comparison.
            //
            // The baseline filter on THIS half is, as the two cutoffs stand
            // today, provably redundant — and it stays anyway. A row can only
            // be dropped from this set by the baseline if
            // `cutoffDate <= due_date < baseline`, which needs the baseline
            // NEWER than the cutoff; the candidate query above only picks a
            // loan up when some unpaid row satisfies
            // `baseline <= due_date < cutoffDate`, which needs the baseline
            // OLDER than it. Both cannot hold, so nothing reaches here that
            // this predicate removes. (Confirmed by deleting it and re-running
            // ImportedArrearsBaselineTest: nothing fails.)
            //
            // It stays because that redundancy is a property of the current
            // cutoff, not of the rule. The paragraph above already warns that
            // these two are complements of ONE cutoff and must move together;
            // the moment either comparison changes — grace-shifted, a
            // per-product threshold, an as-of date other than today — the
            // algebra stops holding and this is the only thing left between a
            // migrated member and a default. Dropping it would also make "the
            // same query with the comparison flipped" untrue, which is what
            // makes the pairing checkable at a glance in the first place.
            $hasRecentDue = Loan::whereKey($loan->getKey())
                ->whereHas('amortizationSchedules', function ($q) use ($cutoffDate) {
                    $q->whereIn('status', ['pending', 'partial', 'overdue'])
                        ->where('due_date', '>=', $cutoffDate)
                        ->whereRaw(AmortizationSchedule::penalisableSql());
                })
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
