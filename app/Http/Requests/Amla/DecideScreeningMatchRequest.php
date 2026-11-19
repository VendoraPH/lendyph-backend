<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;

/**
 * Confirm a potential match, or clear it as a false positive: the reason is
 * required (task §11: false-positive documentation). Needs `amla:screening`
 * and head-office access.
 */
class DecideScreeningMatchRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::SCREENING];
    }

    protected function headOffice(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why: what shows this is, or isn\'t, the listed person.'];
    }
}
