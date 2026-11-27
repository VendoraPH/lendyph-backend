<?php

namespace App\Services\Cda\Sources;

use App\Models\CdaRegulatoryProfile;
use App\Services\Cda\ProfileWords;

/**
 * A field of the regulatory profile (CDA design §2), `profile.field {name}`:
 * CDA's own registration, type, TIN, CTE and contact fields. Dates read
 * YYYY-MM-DD; a chosen value (category, asset size, CTE status) reads in
 * words (ProfileWords), with its stored key in `meta.key`. Current values;
 * a finalized report freezes them.
 */
final class ProfileSource implements CdaSource
{
    public const LABEL = 'CDA › Regulatory profile';

    /** The profile fields a template may read. */
    public const FIELDS = [
        'previous_name', 'cda_registration_no', 'cda_registration_date', 'cda_region', 'cda_extension_office',
        'cooperative_type', 'cooperative_category', 'asset_class', 'tin', 'cte_status', 'cte_number',
        'cte_issued_on', 'cte_expires_on', 'address_line', 'address_barangay', 'address_city', 'address_province',
        'address_region', 'phone', 'email', 'website', 'primary_activity', 'sector', 'subsector', 'satellite_offices',
    ];

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        if ($key !== 'profile.field') {
            throw SourceUnavailable::unknownKey($key, 'profile');
        }

        $name = (string) $params['name'];
        $profile = CdaRegulatoryProfile::query()->orderBy('id')->first();
        $value = $profile?->{$name};

        if (in_array($name, CdaRegulatoryProfile::DATE_FIELDS, true)) {
            return new SourceResult($value?->toDateString(), self::LABEL, 'date');
        }

        $words = ProfileWords::of($name, $value);

        return new SourceResult($words, self::LABEL, $name === 'satellite_offices' ? 'count' : 'text', meta: $words === $value ? [] : ['key' => $value]);
    }
}
