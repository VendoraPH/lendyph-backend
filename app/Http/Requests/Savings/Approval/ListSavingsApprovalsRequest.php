<?php

namespace App\Http\Requests\Savings\Approval;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use App\Models\SavingsApproval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /savings/approvals` (savings:view): the queue, by status, kind and
 * branch. It stays on `savings:view`, not `savings:approve`, because makers
 * need to see their own requests (and cancel them) too.
 */
class ListSavingsApprovalsRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(SavingsApproval::STATUSES)],
            'kind' => ['nullable', Rule::in(SavingsApproval::KINDS)],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'A request is pending, approved, rejected, cancelled or expired.',
            'kind.in' => 'Choose a kind of request from the list.',
            'branch_id.integer' => 'Choose the branch from the list.',
            'branch_id.min' => 'Choose the branch from the list.',
            'page.integer' => 'The page must be a whole number from 1.',
            'page.min' => 'The page must be a whole number from 1.',
            'per_page.integer' => 'Rows per page must be a whole number from 1.',
            'per_page.min' => 'Rows per page must be a whole number from 1.',
        ];
    }
}
