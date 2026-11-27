<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use Illuminate\Validation\Rule;

/**
 * GET /amla/log: filters by action, user, subject and Manila dates. Needs
 * `amla:audit` and head-office access.
 */
class AmlaLogIndexRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::AUDIT];
    }

    protected function headOffice(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'string', Rule::in(array_keys(AmlaLog::ACTIONS))],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['sometimes', 'string', Rule::in(array_keys(AmlaLog::SUBJECTS))],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
