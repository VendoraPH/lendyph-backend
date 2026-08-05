<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('loans:backfill-term-drift {--dry-run : Show what would be updated without writing}')]
#[Description('Reset loans.term to its pre-extension value for loans whose term drifted from repeated Extend Loan calls')]
class BackfillLoanTermDrift extends Command
{
    public function handle(): int
    {
        // extendLoan() used to bump `term` by one on every call, so the
        // earliest 'extension' adjustment for a loan is the only row that
        // still remembers what `term` was actually agreed at — every later
        // one just recorded the already-drifted value as its "old" term.
        // adjustments() defaults to latest()-first, so this reads directly
        // from loan_adjustments rather than relying on that ordering.
        $firstExtensionIds = DB::table('loan_adjustments')
            ->select('loan_id')
            ->selectRaw('MIN(id) as first_extension_id')
            ->where('adjustment_type', 'extension')
            ->groupBy('loan_id')
            ->pluck('first_extension_id', 'loan_id');

        if ($firstExtensionIds->isEmpty()) {
            $this->info('No loans have an extension adjustment.');

            return self::SUCCESS;
        }

        $trueTerms = LoanAdjustment::whereIn('id', $firstExtensionIds->values())
            ->get(['id', 'loan_id', 'old_values'])
            ->mapWithKeys(fn (LoanAdjustment $adjustment) => [
                $adjustment->loan_id => (int) $adjustment->old_values['term'],
            ]);

        // Only loans whose current `term` no longer matches what it was
        // agreed at — re-running this after it has already fixed a loan, or
        // against a loan extended after the fix, must leave it alone.
        $drifted = Loan::whereIn('id', $trueTerms->keys())
            ->get(['id', 'term'])
            ->filter(fn (Loan $loan) => $loan->term !== $trueTerms[$loan->id]);

        if ($drifted->isEmpty()) {
            $this->info('No loans have a drifted term.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($drifted as $loan) {
                $this->line("  loan {$loan->id}: term {$loan->term} -> {$trueTerms[$loan->id]}");
            }
            $this->info("Would reset {$drifted->count()} loan(s).");

            return self::SUCCESS;
        }

        // Written through the query builder rather than Eloquent: this is a
        // historical correction, not a borrower-facing change, so it should
        // neither bump `updated_at` (Eloquent\Builder::update would) nor fire
        // model events — the Auditable trait would otherwise write an audit
        // row per loan claiming the term changed just now.
        foreach ($drifted as $loan) {
            DB::table('loans')
                ->where('id', $loan->id)
                ->update(['term' => $trueTerms[$loan->id]]);
        }

        $this->info("Reset {$drifted->count()} loan(s).");

        return self::SUCCESS;
    }
}
