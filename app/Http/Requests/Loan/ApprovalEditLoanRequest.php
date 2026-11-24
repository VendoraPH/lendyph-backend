<?php

namespace App\Http\Requests\Loan;

use App\Enums\LoanFrequency;
use App\Models\Loan;
use App\Services\LoanApprovalEditService;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * PATCH /loans/{loan}/approval-edit: the Chairman/Chairwoman's edit of a loan
 * waiting at their approval step (LoanApprovalEditService).
 *
 * The loan form's fields under PUT /loans/{id}'s own rules (UpdateLoanRequest),
 * plus the term and the payment frequency, which only this edit may change,
 * and the `version` the edit was opened at (GET /loans/{loan}/approval-edit).
 * The member, the product and the policy exception stay as they are: the form
 * sends them back with every save, so the same value passes and a different
 * one is refused in words. A restructure's `remarks` belong to its own edit.
 *
 * Needs `loans:edit_in_approval`; whether the loan waits at the caller's step
 * is the service's to answer. Collateral changes need `collaterals:update`,
 * as on PUT /loans/{id}, and co-maker changes `borrowers:view`, the picker's
 * own permission.
 */
class ApprovalEditLoanRequest extends UpdateLoanRequest
{
    /** The wording #359 uses for a date field. */
    public const START_DATE_FORMAT = 'The start date must be a date written as year-month-day, such as 2026-11-24.';

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user->can(LoanApprovalEditService::PERMISSION)) {
            return false;
        }

        if ($this->exists('collaterals') && ! $user->can('collaterals:update')) {
            return false;
        }

        return ! $this->exists('co_maker_ids') || $user->can('borrowers:view');
    }

    public function rules(): array
    {
        return [
            ...Arr::except(parent::rules(), ['remarks', 'policy_exception', 'policy_exception_details', 'principal_amount', 'start_date', 'scb_amount']),
            'version' => ['required', 'string', 'max:100'],
            'principal_amount' => ['sometimes', 'numeric', 'min:1', 'decimal:0,2'],
            'term' => ['sometimes', 'integer', 'min:1'],
            'frequency' => ['sometimes', LoanFrequency::rule()],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'scb_amount' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            // Fixed on this path: the same value passes, another is refused (after()).
            'borrower_id' => ['sometimes', 'nullable', 'integer'],
            'loan_product_id' => ['sometimes', 'nullable', 'integer'],
            'policy_exception' => ['sometimes', 'nullable', 'boolean'],
            'policy_exception_details' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'version.required' => 'Reload the loan and open the edit again: this save doesn\'t say which version of the loan it was made from.',
            'version.string' => 'Reload the loan and open the edit again: this save doesn\'t say which version of the loan it was made from.',
            'principal_amount.decimal' => 'The principal can have at most two decimals.',
            'scb_amount.decimal' => 'The share capital build-up can have at most two decimals.',
            'start_date.date_format' => self::START_DATE_FORMAT,
            'frequency.in' => 'Choose a payment frequency from the list.',
        ];
    }

    /**
     * UpdateLoanRequest's own checks, then the fields this edit keeps as they are.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $loan = $this->route('loan');

                if (! $loan instanceof Loan) {
                    return;
                }

                foreach ($this->fixedFieldChanges($loan) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * The fixed fields sent with a value other than the loan's, each with its
     * refusal in words.
     *
     * @return array<string, string>
     */
    private function fixedFieldChanges(Loan $loan): array
    {
        $refused = [];

        if ($this->filled('borrower_id') && (int) $this->input('borrower_id') !== (int) $loan->borrower_id) {
            $refused['borrower_id'] = 'The member can\'t be changed.';
        }

        if ($this->filled('loan_product_id') && (int) $this->input('loan_product_id') !== (int) $loan->loan_product_id) {
            $refused['loan_product_id'] = 'The loan product can\'t be changed during approval.';
        }

        if ($this->has('policy_exception') && $this->input('policy_exception') !== null
            && filter_var($this->input('policy_exception'), FILTER_VALIDATE_BOOL) !== (bool) $loan->policy_exception) {
            $refused['policy_exception'] = 'The policy exception can\'t be changed during approval: it decides which approval chain the loan follows. Send the loan back to the Submit step to change it.';
        }

        if ($this->has('policy_exception_details')
            && trim((string) $this->input('policy_exception_details')) !== trim((string) $loan->policy_exception_details)) {
            $refused['policy_exception_details'] = 'The policy exception details can\'t be changed during approval. Send the loan back to the Submit step to change them.';
        }

        return $refused;
    }
}
