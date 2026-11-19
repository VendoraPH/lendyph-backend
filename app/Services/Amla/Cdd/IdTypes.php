<?php

namespace App\Services\Amla\Cdd;

/**
 * The ID types an AMLA verification records (AMLA-B4 review): a fixed key,
 * never the free text a member document's label or a walk-in's ID type may
 * hold, which could carry a whole ID number.
 *
 * - A member's valid ID stores its type key in `label` (the keys below); any
 *   other label reads `others`.
 * - "Others" keeps the document's `custom_type_name` only when it holds no
 *   digit, so no number is ever copied; otherwise it stays `others`.
 * - A walk-in's ID type is free text, matched to a key by its letters
 *   ("Driver's License", "drivers-license" → drivers_license); anything else
 *   reads `others`.
 *
 * The same keys and words as the member valid ID types.
 */
final class IdTypes
{
    public const OTHERS = 'others';

    /** @var array<string, string> */
    public const TYPES = [
        'philippine_id' => 'Philippine National ID (PhilSys)',
        'drivers_license' => 'Driver\'s License',
        'passport' => 'Passport',
        'sss' => 'SSS ID',
        'umid' => 'UMID',
        'voters_id' => 'Voter\'s ID',
        'postal_id' => 'Postal ID',
        'prc_id' => 'PRC ID',
        'tin_id' => 'TIN ID',
        self::OTHERS => 'Others',
    ];

    /** @var array<string, string> a walk-in's ID type, letters only and lower case, to its key */
    private const WALK_IN_ALIASES = [
        'philippineid' => 'philippine_id', 'philippinenationalid' => 'philippine_id', 'philsys' => 'philippine_id',
        'philsysid' => 'philippine_id', 'nationalid' => 'philippine_id',
        'driverslicense' => 'drivers_license', 'driverlicense' => 'drivers_license',
        'passport' => 'passport',
        'sss' => 'sss', 'sssid' => 'sss',
        'umid' => 'umid', 'umidid' => 'umid',
        'votersid' => 'voters_id', 'voterid' => 'voters_id',
        'postalid' => 'postal_id',
        'prcid' => 'prc_id', 'prc' => 'prc_id',
        'tinid' => 'tin_id', 'tin' => 'tin_id',
    ];

    private const CUSTOM_NAME_MAX = 100;

    public static function fromDocument(?string $label, ?string $customTypeName): string
    {
        $key = (string) $label;

        if ($key !== self::OTHERS) {
            return array_key_exists($key, self::TYPES) ? $key : self::OTHERS;
        }

        $custom = trim((string) $customTypeName);

        return $custom !== '' && preg_match('/\d/', $custom) !== 1
            ? mb_substr($custom, 0, self::CUSTOM_NAME_MAX)
            : self::OTHERS;
    }

    public static function fromWalkIn(string $idType): string
    {
        $letters = (string) preg_replace('/[^a-z]/', '', mb_strtolower($idType));

        return self::WALK_IN_ALIASES[$letters] ?? self::OTHERS;
    }

    /** A stored type in words: the list's words, else the custom name kept as is. */
    public static function words(string $stored): string
    {
        return self::TYPES[$stored] ?? $stored;
    }
}
