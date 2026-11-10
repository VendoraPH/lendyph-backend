<?php

namespace App\Http\Requests\Cda;

use App\Models\CdaRegulatoryProfile;
use App\Services\Cda\ReportTypes;
use Illuminate\Validation\Rule;

/**
 * POST /cda/templates: a new draft template (super_admin only). The
 * definition is checked in full when it is activated, or on the dry-run.
 */
class StoreCdaTemplateRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'report_type' => ['required', Rule::in(ReportTypes::ALL)],
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z.\-]*$/',
                Rule::unique('cda_report_templates', 'version')->where('report_type', $this->input('report_type'))],
            'name' => ['required', 'string', 'max:160'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'cooperative_types' => ['nullable', 'array'],
            'cooperative_types.*' => ['string', 'max:60', 'distinct'],
            'asset_classes' => ['nullable', 'array'],
            'asset_classes.*' => [Rule::in(CdaRegulatoryProfile::ASSET_CLASSES), 'distinct'],
            'definition' => ['required', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['version.unique' => 'This report type already has a template with that version.'];
    }
}
