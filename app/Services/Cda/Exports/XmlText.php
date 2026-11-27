<?php

namespace App\Services\Cda\Exports;

/**
 * Text as XML 1.0 can hold it, for both Excel writers: invalid UTF-8 is
 * scrubbed and the control characters XML forbids (everything below a space
 * but tab, newline and carriage return, plus U+FFFE and U+FFFF) are dropped,
 * so a stray character in a report value can never make a workbook unreadable.
 */
final class XmlText
{
    public static function clean(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($text, 'UTF-8'));
    }
}
