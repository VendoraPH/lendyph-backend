<?php

namespace App\Services\Cda;

/**
 * The regulatory profile's chosen values in words (the CDA browser check):
 * the category, asset size, its source and the CTE status are stored as
 * keys ("primary", "pending") and read as the settings screen shows them
 * ("Primary", "Application pending") in report values and exports. The
 * cooperative type is typed by hand; a value typed as a key ("multi_purpose")
 * reads in words, anything else stays as typed. The stored value never
 * changes.
 */
final class ProfileWords
{
    /** @var array<string, array<string, string>> field => key => words */
    public const WORDS = [
        'cooperative_category' => [
            'primary' => 'Primary',
            'secondary' => 'Secondary',
            'tertiary' => 'Tertiary',
            'federation' => 'Federation',
            'union' => 'Union',
        ],
        'asset_class' => [
            'micro' => 'Micro',
            'small' => 'Small',
            'medium' => 'Medium',
            'large' => 'Large',
        ],
        'asset_class_source' => [
            'computed' => 'Computed',
            'manual' => 'Set by hand',
        ],
        'cte_status' => [
            'none' => 'None',
            'valid' => 'Valid',
            'pending' => 'Application pending',
            'expired' => 'Expired',
        ],
    ];

    /** A profile field's value in words; any other field's value as it is. */
    public static function of(string $field, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (isset(self::WORDS[$field])) {
            return self::WORDS[$field][$value] ?? $value;
        }

        if ($field === 'cooperative_type' && preg_match('/^[a-z0-9]+(_[a-z0-9]+)*$/', $value) === 1) {
            return ucfirst(str_replace('_', ' ', $value));
        }

        return $value;
    }
}
