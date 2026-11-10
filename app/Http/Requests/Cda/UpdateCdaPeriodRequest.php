<?php

namespace App\Http\Requests\Cda;

use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;

/**
 * PATCH /cda/periods/{year}: the deadline extension CDA granted for the
 * year's annual reports, with its reference (a memorandum circular or
 * letter). A null deadline removes the extension.
 */
class UpdateCdaPeriodRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::SETTINGS;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $default = CdaReportingPeriod::query()->where('year', (int) $this->route('year'))->value('default_deadline');
        $default = $default === null ? null : substr((string) $default, 0, 10);

        return [
            'extended_deadline' => ['present', 'nullable', 'date_format:Y-m-d', ...($default === null ? [] : ['after:'.$default])],
            'extension_reference' => ['nullable', 'string', 'max:120', 'required_with:extended_deadline'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'extended_deadline.after' => 'An extension moves the deadline later than the default.',
            'extension_reference.required_with' => 'Give the reference of CDA\'s extension (memorandum circular or letter).',
        ];
    }
}
