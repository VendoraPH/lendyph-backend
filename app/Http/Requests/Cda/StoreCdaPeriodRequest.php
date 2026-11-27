<?php

namespace App\Http\Requests\Cda;

use App\Services\Cda\CdaAccess;

/**
 * POST /cda/periods {year}: a calendar year from 2000 to next year.
 */
class StoreCdaPeriodRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::PREPARE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'year.min' => 'A reporting year is 2000 or later.',
            'year.max' => 'A reporting year can be at most next year.',
        ];
    }
}
