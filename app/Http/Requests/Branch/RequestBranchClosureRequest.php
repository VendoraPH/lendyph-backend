<?php

namespace App\Http\Requests\Branch;

/**
 * Ask to close a branch: the date it stops operating (today or earlier, in
 * Manila) and a reason. A different user approves it.
 */
class RequestBranchClosureRequest extends BranchStatusRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'effective_on.before_or_equal' => 'The closing date cannot be after today.',
        ];
    }
}
