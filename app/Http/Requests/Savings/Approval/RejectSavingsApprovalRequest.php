<?php

namespace App\Http\Requests\Savings\Approval;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /savings/approvals/{approval}/reject` (savings:approve), with a reason. */
class RejectSavingsApprovalRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:approve', 'approval', 'approval');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the request is rejected.',
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
