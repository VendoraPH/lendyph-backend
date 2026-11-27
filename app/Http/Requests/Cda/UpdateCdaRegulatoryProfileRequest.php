<?php

namespace App\Http\Requests\Cda;

use App\Models\CdaRegulatoryProfile;
use App\Services\Cda\CdaAccess;
use Illuminate\Validation\Rule;

/**
 * PUT /cda/regulatory-profile: CDA's own fields, partial. The name,
 * address and contact are edited in Settings › Branding instead.
 */
class UpdateCdaRegulatoryProfileRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::SETTINGS;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $text = fn (int $max) => ['sometimes', 'nullable', 'string', "max:{$max}"];

        return [
            'previous_name' => $text(255),
            'cda_registration_no' => $text(60),
            'cda_registration_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'cda_region' => $text(80),
            'cda_extension_office' => $text(120),
            'cooperative_type' => $text(60),
            'cooperative_category' => ['sometimes', 'nullable', Rule::in(CdaRegulatoryProfile::CATEGORIES)],
            'asset_class' => ['sometimes', 'nullable', Rule::in(CdaRegulatoryProfile::ASSET_CLASSES)],
            'tin' => $text(20),
            'cte_status' => ['sometimes', Rule::in(CdaRegulatoryProfile::CTE_STATUSES)],
            'cte_number' => $text(60),
            'cte_issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'cte_expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:cte_issued_on'],
            'address_line' => $text(255),
            'address_barangay' => $text(120),
            'address_city' => $text(120),
            'address_province' => $text(120),
            'address_region' => $text(120),
            'phone' => $text(40),
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'primary_activity' => $text(120),
            'sector' => $text(120),
            'subsector' => $text(120),
            'satellite_offices' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'authorized_rep_officer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cda_officers', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cooperative_category.in' => 'The category is primary, secondary, tertiary, federation or union.',
            'asset_class.in' => 'The asset size is micro, small, medium or large.',
            'cte_status.in' => 'The CTE status is none, valid, pending or expired.',
            'cte_expires_on.after_or_equal' => 'The CTE can\'t expire before it was issued.',
        ];
    }
}
