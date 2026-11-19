<?php

namespace App\Http\Requests\Branch;

/**
 * Approve (note optional) or reject (note required) a request to close a
 * branch.
 */
class DecideBranchClosureRequest extends BranchStatusRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => [$this->routeIs('branches.closure.reject') ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
