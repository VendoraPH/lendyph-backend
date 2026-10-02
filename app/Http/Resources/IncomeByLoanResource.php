<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One loan's row on the Income by Loan Account report: a Loan from
 * ReportService::incomeByLoan(), carrying the sums of its posted repayments in
 * the period.
 *
 * Every figure is the database's sum; nothing here adds anything up.
 */
#[OA\Schema(
    schema: 'IncomeByLoanRow',
    properties: [
        new OA\Property(property: 'loan_id', type: 'integer'),
        new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
        new OA\Property(property: 'borrower_id', type: 'integer'),
        new OA\Property(property: 'borrower_name', type: 'string', nullable: true, description: 'The same `borrower_name` LoanResource and RepaymentResource send.'),
        new OA\Property(property: 'payments', type: 'integer', description: 'Posted repayments on this loan in the period.'),
        new OA\Property(property: 'interest_income', type: 'number', description: 'SUM(interest_applied), rounded to 2 decimals.'),
        new OA\Property(property: 'penalty_income', type: 'number', description: 'SUM(penalty_applied), rounded to 2 decimals.'),
        new OA\Property(property: 'total_income', type: 'number', description: 'Interest plus penalty, summed by the database, rounded to 2 decimals. Processing fees are not included.'),
    ],
)]
class IncomeByLoanResource extends JsonResource
{
    /**
     * @return array{
     *     loan_id: int,
     *     loan_account_number: string|null,
     *     borrower_id: int,
     *     borrower_name: string|null,
     *     payments: int,
     *     interest_income: float,
     *     penalty_income: float,
     *     total_income: float,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'loan_id' => (int) $this->id,
            'loan_account_number' => $this->loan_account_number,
            'borrower_id' => (int) $this->borrower_id,
            'borrower_name' => $this->borrower?->full_name,
            'payments' => (int) $this->payments,
            'interest_income' => round((float) $this->interest_income, 2),
            'penalty_income' => round((float) $this->penalty_income, 2),
            'total_income' => round((float) $this->total_income, 2),
        ];
    }
}
