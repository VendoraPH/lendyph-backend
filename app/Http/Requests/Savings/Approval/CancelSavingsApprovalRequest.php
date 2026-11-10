<?php

namespace App\Http\Requests\Savings\Approval;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/approvals/{approval}/cancel`: the maker withdraws their own
 * request, with an optional reason. Only the maker may (ApprovalDesk, 403);
 * another branch's request reads as not found.
 */
class CancelSavingsApprovalRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord(null, 'approval', 'approval');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
