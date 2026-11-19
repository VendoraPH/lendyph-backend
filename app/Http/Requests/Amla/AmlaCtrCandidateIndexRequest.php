<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;

/** Filters on the CTR candidates list: `amla:ctr`, branch-scoped (AMLA-B3). */
class AmlaCtrCandidateIndexRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::CTR];
    }

    protected function headOffice(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end date can\'t be before the start date.'];
    }
}
