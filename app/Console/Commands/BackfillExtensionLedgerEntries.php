<?php

namespace App\Console\Commands;

use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:backfill-extension-ledger {--dry-run : Show what would be created without writing}')]
#[Description('Create ledger entries for extensions that were applied before the loan ledger existed')]
class BackfillExtensionLedgerEntries extends Command
{
    public function handle(): int
    {
        // Extensions applied before loan_ledger_entries existed left no ledger
        // record, so those loans show a ledger with no interest debits at all.
        // Everything needed to reconstruct them was already captured on the
        // adjustment: fresh_interest is the debit, interest_paid the credit.
        $adjustments = LoanAdjustment::where('adjustment_type', 'extension')
            ->whereDoesntHave('ledgerEntries')
            ->orderBy('id')
            ->get();

        if ($adjustments->isEmpty()) {
            $this->info('Every extension already has ledger entries.');

            return self::SUCCESS;
        }

        $debits = 0;
        $credits = 0;
        $skipped = 0;

        foreach ($adjustments as $adjustment) {
            $freshInterest = (float) ($adjustment->new_values['fresh_interest'] ?? 0);
            $interestPaid = (float) ($adjustment->new_values['interest_paid'] ?? 0);

            // Extensions predating the Interest Due Option have no
            // fresh_interest recorded; there is nothing to reconstruct from
            // and inventing a figure would be worse than leaving the gap.
            if ($freshInterest <= 0) {
                $skipped++;

                continue;
            }

            $entryDate = ($adjustment->applied_at ?? $adjustment->created_at)->toDateString();

            if ($this->option('dry-run')) {
                $this->line("  adjustment {$adjustment->id}: debit {$freshInterest}".
                    ($interestPaid > 0 ? ", credit {$interestPaid}" : ''));
                $debits++;
                $credits += $interestPaid > 0 ? 1 : 0;

                continue;
            }

            if ($interestPaid > 0) {
                LoanLedgerEntry::create([
                    'loan_id' => $adjustment->loan_id,
                    'loan_adjustment_id' => $adjustment->id,
                    'repayment_id' => $this->matchInterestRepayment($adjustment, $interestPaid)?->id,
                    'type' => 'credit',
                    'category' => 'interest',
                    'amount' => round($interestPaid, 2),
                    'entry_date' => $entryDate,
                    'description' => 'Payment of outstanding interest upon loan extension',
                ]);
                $credits++;
            }

            LoanLedgerEntry::create([
                'loan_id' => $adjustment->loan_id,
                'loan_adjustment_id' => $adjustment->id,
                'type' => 'debit',
                'category' => 'interest',
                'amount' => round($freshInterest, 2),
                'entry_date' => $entryDate,
                'description' => 'Interest charged for the extended loan term',
            ]);
            $debits++;
        }

        $verb = $this->option('dry-run') ? 'Would create' : 'Created';
        $this->info("{$verb} {$debits} debit(s) and {$credits} credit(s); {$skipped} extension(s) had no recorded interest.");

        return self::SUCCESS;
    }

    /**
     * Find the repayment an extension raised when it collected the interest.
     *
     * A credit that cannot name its repayment leaves the client unable to tell
     * the two apart, so the collection renders — and counts — twice: once as
     * the payment and once as the ledger credit. Live extensions link them
     * directly; for historical ones the pairing has to be recovered.
     *
     * Matched on the loan, the exact amount, and proximity to when the
     * extension was applied. A repayment already claimed by another entry is
     * skipped so two extensions of the same amount cannot both point at it.
     */
    private function matchInterestRepayment(LoanAdjustment $adjustment, float $interestPaid): ?Repayment
    {
        $appliedAt = $adjustment->applied_at ?? $adjustment->created_at;

        return Repayment::where('loan_id', $adjustment->loan_id)
            ->whereRaw('ABS(amount_paid - ?) < 0.01', [$interestPaid])
            ->whereBetween('created_at', [
                $appliedAt->copy()->subMinutes(5),
                $appliedAt->copy()->addMinutes(5),
            ])
            ->whereNotIn('id', LoanLedgerEntry::whereNotNull('repayment_id')->pluck('repayment_id'))
            ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, created_at, ?))', [$appliedAt])
            ->first();
    }
}
