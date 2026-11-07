<?php

namespace App\Services;

use App\Models\Loan;

class PromissoryNoteService
{
    public function __construct(private LoanService $loanService) {}

    public function generatePromissoryNote(Loan $loan): array
    {
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers', 'amortizationSchedules', 'approvedByUser');

        $schedule = $loan->amortizationSchedules->isNotEmpty()
            ? $loan->amortizationSchedules
            : collect($this->loanService->buildAmortizationPreview($loan));

        $totalInterest = $schedule->sum(fn ($s) => (float) (is_array($s) ? $s['interest_due'] : $s->interest_due));
        $totalPrincipal = (float) $loan->principal_amount;
        $firstSchedule = $schedule->first();
        $lastSchedule = $schedule->last();

        return [
            'document_title' => 'PROMISSORY NOTE',
            'reference_number' => 'PN-'.substr($loan->application_number, 3),
            'generated_at' => now()->toDateTimeString(),

            // What the note prints, and nothing else: this answers to
            // `loans:view`, not `borrowers:view`.
            'borrower' => [
                'borrower_code' => $loan->borrower->borrower_code,
                'full_name' => $loan->borrower->full_name,
                'address' => $loan->borrower->address ?: $loan->borrower->street_address,
            ],

            'co_makers' => $loan->coMakers->map(fn ($cm) => [
                'co_maker_code' => $cm->co_maker_code,
                'full_name' => $cm->full_name,
                'address' => $cm->address,
                'relationship_to_borrower' => $cm->relationship_to_borrower,
            ])->values()->toArray(),

            'loan_terms' => [
                'application_number' => $loan->application_number,
                'loan_account_number' => $loan->loan_account_number,
                'principal_amount' => (float) $loan->principal_amount,
                'interest_rate' => (float) $loan->interest_rate,
                'interest_rate_frequency' => $loan->interest_rate_frequency->value,
                'interest_method' => $loan->interest_method,
                'term' => $loan->term,
                'term_unit' => $loan->term_unit->value,
                'frequency' => $loan->frequency,
                'start_date' => $loan->start_date->toDateString(),
                'maturity_date' => $loan->maturity_date->toDateString(),
                'total_interest' => round($totalInterest, 2),
                'total_obligation' => round($totalPrincipal + $totalInterest, 2),
                'penalty_rate' => (float) $loan->penalty_rate,
                'grace_period_days' => $loan->grace_period_days,
            ],

            'payment_schedule_summary' => [
                'number_of_installments' => $schedule->count(),
                'installment_amount' => (float) (is_array($firstSchedule) ? $firstSchedule['total_due'] : $firstSchedule->total_due),
                'first_due_date' => is_array($firstSchedule) ? $firstSchedule['due_date'] : $firstSchedule->due_date->toDateString(),
                'last_due_date' => is_array($lastSchedule) ? $lastSchedule['due_date'] : $lastSchedule->due_date->toDateString(),
            ],

            'branch' => [
                'name' => $loan->branch->name,
                'address' => $loan->branch->address,
                'contact_number' => $loan->branch->contact_number,
            ],

            'signatures' => [
                'borrower_name' => $loan->borrower->full_name,
                'co_maker_names' => $loan->coMakers->pluck('full_name')->values()->toArray(),
                'approved_by' => $loan->approvedByUser?->full_name,
            ],
        ];
    }
}
