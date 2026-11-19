<?php

namespace App\Http\Requests\Branch;

/**
 * Make a branch the main office, with a reason that goes into both
 * branches' audit rows.
 */
class MoveMainOfficeRequest extends BranchStatusRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
