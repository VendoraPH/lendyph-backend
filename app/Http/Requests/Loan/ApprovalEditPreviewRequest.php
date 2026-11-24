<?php

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use App\Services\LoanApprovalEditService;

/**
 * POST /loans/{loan}/approval-edit/preview: the loan form's figures while the
 * Chairman/Chairwoman edits a loan at their approval step.
 *
 * The edit page's preview (PreviewLoanRequest with `loan_id`), under its own
 * rules, for the loan in the route: its collateral and share capital checks
 * included. Needs `loans:edit_in_approval` instead of `loans:update`; whether
 * the loan waits at the caller's step is the service's to answer. A
 * restructure's preview (`source_loan_id`) isn't part of this edit.
 */
class ApprovalEditPreviewRequest extends PreviewLoanRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(LoanApprovalEditService::PERMISSION);
    }

    /**
     * The loan is the route's, whatever the body names.
     */
    protected function prepareForValidation(): void
    {
        $loan = $this->route('loan');

        if ($loan instanceof Loan) {
            $this->merge(['loan_id' => $loan->getKey()]);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'source_loan_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'source_loan_id.prohibited' => 'A restructure\'s preview isn\'t part of this edit.',
        ];
    }
}
