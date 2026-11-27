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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'effective_from.before_or_equal' => 'The template can\'t start after it ends.',
            'effective_until.after_or_equal' => 'The template can\'t end before it starts.',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $template = CdaReportTemplate::query()->find((int) $this->route('template'));

        if ($template === null) {
            return [];
        }

        $storedFrom = $template->effective_from->toDateString();
        $storedUntil = $template->effective_until?->toDateString();
        $from = $this->has('effective_from') ? $this->input('effective_from') : $storedFrom;
        $until = $this->has('effective_until') ? $this->input('effective_until') : $storedUntil;

        return [
            'version' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z.\-]*$/',
                Rule::unique('cda_report_templates', 'version')->where('report_type', $template->report_type)->ignore($template->id)],
            'name' => ['sometimes', 'string', 'max:160'],
            // Each end checked against the other as it will be saved: the one
            // sent, else the stored one (C1 review, L2).
            'effective_from' => ['sometimes', 'date_format:Y-m-d', ...(is_string($until) && $until !== '' ? ['before_or_equal:'.$until] : [])],
            'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...(is_string($from) && $from !== '' ? ['after_or_equal:'.$from] : [])],
            'cooperative_types' => ['sometimes', 'nullable', 'array'],
            'cooperative_types.*' => ['string', 'max:60', 'distinct'],
            'asset_classes' => ['sometimes', 'nullable', 'array'],
            'asset_classes.*' => [Rule::in(CdaRegulatoryProfile::ASSET_CLASSES), 'distinct'],
            'definition' => ['sometimes', 'array'],
        ];
    }
}
