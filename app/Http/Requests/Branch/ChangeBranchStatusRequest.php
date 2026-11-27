<?php

namespace App\Http\Requests\Branch;

use App\Models\Branch;
use Illuminate\Validation\Rule;

/**
 * Suspend or reactivate a branch, with a reason. Closing goes through the
 * closure (BranchClosureController), never here.
 */
class ChangeBranchStatusRequest extends BranchStatusRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([Branch::STATUS_ACTIVE, Branch::STATUS_SUSPENDED])],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'Choose active or suspended. A branch is closed through a closure request.',
        ];
    }
}
