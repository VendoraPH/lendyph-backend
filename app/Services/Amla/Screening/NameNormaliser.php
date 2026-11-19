<?php

namespace App\Services\Amla\Screening;

use App\Models\GCashNonMember;

/**
 * Names as screening compares them: the walk-in normalisation (case and
 * spacing ignored, GCashNonMember::comparableName), then accents taken off
 * and anything but letters read as a space, so "JOSÉ  dela Peña." and
 * "Jose Dela Pena" are one name. Surname particles join the next word
 * ("de la cruz" is "delacruz"). Words are compared in any order.
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

    /**
     * Surname particles that are joined to the next word, so a name written
     * with or without the spaces compares the same.
     *
     * @var list<string>
     */
    public const PARTICLES = ['de', 'del', 'dela', 'della', 'delas', 'delos', 'la', 'las', 'los', 'le', 'da', 'das', 'do', 'dos', 'di', 'du', 'van', 'von', 'der', 'den', 'ter', 'ten', 'san', 'sta', 'sto'];

    public static function normalise(string $name): string
    {
        $plain = strtr(GCashNonMember::comparableName($name), self::ACCENTS);
        $letters = (string) preg_replace('/[^a-z]+/', ' ', $plain);

        return trim((string) preg_replace('/\s+/', ' ', $letters));
    }

    /**
     * The name's words in their order, with surname particles joined to the
     * word after them (AMLA-B5 review): "de la cruz", "dela cruz" and
     * "delacruz" are all "delacruz"; "de los santos" is "delossantos". A
     * particle at the end stays a word of its own.
     *
     * @return list<string>
     */
    public static function words(string $name): array
    {
        $normalised = self::normalise($name);

        if ($normalised === '') {
            return [];
        }

        $words = [];
        $pending = '';

        foreach (explode(' ', $normalised) as $word) {
            if (in_array($word, self::PARTICLES, true)) {
                $pending .= $word;

                continue;
            }

            $words[] = $pending.$word;
            $pending = '';
        }

        if ($pending !== '') {
            $words[] = $pending;
        }

        return $words;
    }

    /**
     * The name's distinct words (particles joined), sorted.
     *
     * @return list<string>
     */
    public static function tokens(string $name): array
    {
        $tokens = array_values(array_unique(self::words($name)));
        sort($tokens);

        return $tokens;
    }
}
