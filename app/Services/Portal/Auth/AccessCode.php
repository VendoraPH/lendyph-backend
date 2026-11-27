<?php

namespace App\Services\Portal\Auth;

/**
 * Activation and reset codes (portal design §1): 12 characters of Crockford
 * Base32 (no I, L, O or U; 60 bits), shown as XXXX-XXXX-XXXX. Input is
 * normalised before it is checked: case, spaces and dashes are ignored, O
 * reads as 0 and I or L as 1. Only `Hash::make` of the normalised code is ever
 * stored.
 */
final class AccessCode
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 12;

    /** A new code, normalised (no dashes). */
    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public static function format(string $normalised): string
    {
        return implode('-', str_split($normalised, 4));
    }

    public static function normalise(mixed $input): string
    {
        if (! is_string($input)) {
            return '';
        }

        $code = strtoupper((string) preg_replace('/[\s\-]+/', '', $input));

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }
}
