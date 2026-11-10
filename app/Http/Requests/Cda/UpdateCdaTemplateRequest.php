<?php

namespace App\Http\Requests\Cda;

use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReportTemplate;
use Illuminate\Validation\Rule;

/**
 * PUT /cda/templates/{template}: edits a draft (super_admin only), partial.
 * The report type is fixed once created.
 */
class UpdateCdaTemplateRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var CdaReportTemplate $template */
        $template = $this->route('template');

        return [
            'version' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z.\-]*$/',
                Rule::unique('cda_report_templates', 'version')->where('report_type', $template->report_type)->ignore($template->id)],
            'name' => ['sometimes', 'string', 'max:160'],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'cooperative_types' => ['sometimes', 'nullable', 'array'],
            'cooperative_types.*' => ['string', 'max:60', 'distinct'],
            'asset_classes' => ['sometimes', 'nullable', 'array'],
            'asset_classes.*' => [Rule::in(CdaRegulatoryProfile::ASSET_CLASSES), 'distinct'],
            'definition' => ['sometimes', 'array'],
        ];
    }
}
