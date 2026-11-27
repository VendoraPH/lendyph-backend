<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaProfile;
use App\Services\Amla\Cdd\CddState;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Validation\Rule;

/**
 * GET /amla/cdd/subjects: the CDD worklist. Needs `amla:cdd`; members are
 * limited to the caller's branches and walk-ins to head-office users.
 */
class AmlaCddIndexRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::CDD];
    }

    protected function headOffice(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in([AmlaProfile::MEMBER, AmlaProfile::WALK_IN])],
            'status' => ['sometimes', 'string', Rule::in(array_keys(CddState::STATUSES))],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
