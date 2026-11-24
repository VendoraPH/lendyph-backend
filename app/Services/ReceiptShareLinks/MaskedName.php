<?php

namespace App\Services\ReceiptShareLinks;

/**
 * A member's name as a shared receipt shows it: initials, then the surname
 * (finish run round 2, decision 6). Each word of the first name gives one
 * initial and the middle name gives one, as a middle initial is written, so
 * "Maria Clara", "dela Cruz", "Santos", "Jr." reads "M. C. D. Santos Jr.".
 *
 * An initial is the word's first letter, uppercased ("Ma." gives "M.",
 * "ñora" gives "Ñ."). A name with nothing to show reads "Member".
 */
final class MaskedName
{
    public const NOTHING_TO_SHOW = 'Member';

    public static function of(?string $firstName, ?string $middleName, ?string $lastName, ?string $suffix = null): string
    {
        $initials = array_filter([
            ...array_map(self::initial(...), self::words($firstName)),
            self::initial(self::words($middleName)[0] ?? ''),
        ]);

        $parts = [
            ...array_map(fn (string $letter): string => $letter.'.', $initials),
            ...self::words($lastName),
            ...self::words($suffix),
        ];

        return $parts === [] ? self::NOTHING_TO_SHOW : implode(' ', $parts);
    }

    /** @return list<string> */
    private static function words(?string $text): array
    {
        return preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function initial(string $word): ?string
    {
        return preg_match('/\p{L}/u', $word, $letter) === 1 ? mb_strtoupper($letter[0], 'UTF-8') : null;
    }
}
