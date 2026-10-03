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

        // Every total in whole centavos, so the printed statement's figures
        // add up exactly and the browser adds nothing.
        $centavos = fn (mixed $pesos): int => (int) round((float) $pesos * 100);
        $sumOf = fn (string $column): int => array_sum(array_map($centavos, array_column($schedule, $column)));

        $totalPrincipal = $sumOf('principal_due');
        $totalInterest = $sumOf('interest_due');
        $totalDeductions = $centavos($loan->total_deductions);

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
                'interest_rate_frequency' => $loan->interest_rate_frequency->value,
                'interest_method' => $loan->interest_method,
                'term' => $loan->term,
                'term_unit' => $loan->term_unit->value,
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
                'total_principal' => $totalPrincipal / 100,
                'total_interest' => $totalInterest / 100,
                'total_obligation' => ($totalPrincipal + $totalInterest) / 100,
                // The schedule's Total Amortization column added up.
                'total_amortization' => $sumOf('total_due') / 100,
                'total_deductions' => (float) $loan->total_deductions,
                'net_proceeds' => (float) $loan->net_proceeds,
                // Section 2 of the statement: what was withheld plus the
                // interest, the finance charges R.A. 3765 has disclosed.
                'total_finance_charges' => ($totalDeductions + $totalInterest) / 100,
                // The total less the itemised deductions, signed, printed as a
                // charge (or an adjustment) of its own so the lettered lines
                // add up to the total.
                'unitemised_deductions' => $loan->unitemisedDeductions(),
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
