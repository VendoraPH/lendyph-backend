<?php

namespace App\Services\Amla\Screening;

use App\Models\GCashNonMember;

/**
 * Names as screening compares them: the walk-in normalisation (case and
 * spacing ignored, GCashNonMember::comparableName), then accents taken off
 * and anything but letters read as a space, so "JOSÉ  dela Peña." and
 * "Jose Dela Pena" are one name. Words are compared in any order.
 */
final class NameNormaliser
{
    /** The accented letters Filipino and common foreign names use, to their plain letter. */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
    ];

    public static function normalise(string $name): string
    {
        $plain = strtr(GCashNonMember::comparableName($name), self::ACCENTS);
        $letters = (string) preg_replace('/[^a-z]+/', ' ', $plain);

        return trim((string) preg_replace('/\s+/', ' ', $letters));
    }

    /**
     * The name's distinct words, sorted.
     *
     * @return list<string>
     */
    public static function tokens(string $name): array
    {
        $normalised = self::normalise($name);

        if ($normalised === '') {
            return [];
        }

        $tokens = array_values(array_unique(explode(' ', $normalised)));
        sort($tokens);

        return $tokens;
    }
}
