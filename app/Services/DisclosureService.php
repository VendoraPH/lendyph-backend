<?php

namespace App\Services;

use App\Models\Loan;

class DisclosureService
{
    public function __construct(private LoanService $loanService) {}

    public function generateDisclosure(Loan $loan): array
    {
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers', 'amortizationSchedules');

        $schedule = $loan->amortizationSchedules->isNotEmpty()
            ? $loan->amortizationSchedules->map(fn ($s) => [
                'period_number' => $s->period_number,
                'due_date' => $s->due_date->toDateString(),
                'principal_due' => (float) $s->principal_due,
                'interest_due' => (float) $s->interest_due,
                'total_due' => (float) $s->total_due,
                'remaining_balance' => (float) $s->remaining_balance,
            ])->values()->toArray()
            : $this->loanService->buildAmortizationPreview($loan);

        $totalPrincipal = array_sum(array_column($schedule, 'principal_due'));
        $totalInterest = array_sum(array_column($schedule, 'interest_due'));

        return [
            'document_title' => 'DISCLOSURE STATEMENT',
            'reference_number' => $loan->application_number,
            'generated_at' => now()->toDateTimeString(),

            'borrower' => [
                'borrower_code' => $loan->borrower->borrower_code,
                'full_name' => $loan->borrower->full_name,
                'address' => $loan->borrower->address,
                'contact_number' => $loan->borrower->contact_number,
                'email' => $loan->borrower->email,
                'employer_or_business' => $loan->borrower->employer_or_business,
                'monthly_income' => (float) $loan->borrower->monthly_income,
            ],

            'loan_terms' => [
                'application_number' => $loan->application_number,
                'loan_account_number' => $loan->loan_account_number,
                'loan_product_name' => $loan->loanProduct->name,
                'principal_amount' => (float) $loan->principal_amount,
                'interest_rate' => (float) $loan->interest_rate,
                'interest_method' => $loan->interest_method,
                'term' => $loan->term,
                'frequency' => $loan->frequency,
                'penalty_rate' => (float) $loan->penalty_rate,
                'grace_period_days' => $loan->grace_period_days,
                'start_date' => $loan->start_date->toDateString(),
                'maturity_date' => $loan->maturity_date->toDateString(),
            ],

            'deductions' => [
                'items' => $loan->deductions ?? [],
                'total_deductions' => (float) $loan->total_deductions,
                'net_proceeds' => (float) $loan->net_proceeds,
            ],

            'totals' => [
                'total_principal' => round($totalPrincipal, 2),
                'total_interest' => round($totalInterest, 2),
                'total_obligation' => round($totalPrincipal + $totalInterest, 2),
                'total_deductions' => (float) $loan->total_deductions,
                'net_proceeds' => (float) $loan->net_proceeds,
            ],

            'amortization_schedule' => $schedule,

            'co_makers' => $loan->coMakers->map(fn ($cm) => [
                'full_name' => $cm->full_name,
                'address' => $cm->address,
                'contact_number' => $cm->contact_number,
            ])->values()->toArray(),
        ];
    }
}
