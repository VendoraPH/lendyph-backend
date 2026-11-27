<?php

namespace App\Http\Requests\Savings\Approval;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/approvals/{approval}/approve` (savings:approve). Nothing in
 * the body; who may approve (never the maker, never twice) is ApprovalDesk's.
 */
class DecideSavingsApprovalRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:approve', 'approval', 'approval');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [];
    }
}
