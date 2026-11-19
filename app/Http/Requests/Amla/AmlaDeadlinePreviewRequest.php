<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\Deadlines;
use Illuminate\Validation\Rule;

/**
 * GET /amla/deadlines/preview: when a CTR or STR started on a date would be
 * due. Needs `amla:view`.
 */
class AmlaDeadlinePreviewRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::VIEW];
    }

    protected function headOffice(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(array_keys(Deadlines::KINDS))],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['kind.in' => 'Pick a CTR or an STR.', 'from.date_format' => 'Enter a real date as YYYY-MM-DD.'];
    }
}
