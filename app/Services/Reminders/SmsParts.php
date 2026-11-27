<?php

namespace App\Services\Reminders;

/**
 * How many SMS parts a text costs: GSM-7 fits 160 characters in one part and
 * 153 per part when split (extension characters count twice); one character
 * outside it, "₱" included, makes the whole text Unicode at 70 and 67. The
 * same counting as the frontend's smsLength().
 */
final class SmsParts
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    public static function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $units = 0;

        foreach (mb_str_split($text) as $character) {
            if (mb_strpos(self::GSM_BASIC, $character) !== false) {
                $units++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $character) !== false) {
                $units += 2;
            } else {
                $length = intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);

                return $length <= 70 ? 1 : (int) ceil($length / 67);
            }
        }

        return $units <= 160 ? 1 : (int) ceil($units / 153);
    }
}
