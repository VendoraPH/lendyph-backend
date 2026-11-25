<?php

namespace App\Http\Requests\Savings\Transaction;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use App\Models\SavingsTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /savings/payouts/missing-reference` (savings:view, finish run round
 * 6): the non-cash payouts still without a reference. A `branch_id` outside
 * the caller's branches answers 403 in the controller.
 */
class ListPayoutsMissingReferenceRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'method' => ['nullable', 'string', Rule::in(SavingsTransaction::PAYOUT_REFERENCE_METHODS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_id.integer' => 'Choose the branch from the list.',
            'branch_id.min' => 'Choose the branch from the list.',
            'date_from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'date_to.date_format' => 'The end date must be written as YYYY-MM-DD.',
            'date_to.after_or_equal' => 'The end date can\'t be before the start date.',
            'method.string' => 'Choose bank transfer, GCash or Maya.',
            'method.in' => 'Choose bank transfer, GCash or Maya.',
            'page.integer' => 'The page must be a whole number from 1.',
            'page.min' => 'The page must be a whole number from 1.',
        ];
    }
}
