<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The Content-Disposition a signed file link sends (FileController), whatever
 * the saved name holds.
 *
 * - Names saved before uploads were cleaned can hold control characters, and
 *   any name can hold a backslash or nothing an ASCII fallback can spell. Each
 *   of those made Symfony throw, so the link answered 500. The name is cleaned
 *   the way new uploads are (Document::cleanOriginalFilename()), and path
 *   separators become `_`, in the name and in its ASCII fallback.
 * - The name ends in the stored file's own extension, which comes from its
 *   bytes when it was stored. A name saved before extensions were checked
 *   ("id.bat" over PDF bytes) is never offered as a file the browser would run.
 * - A name left empty, or a fallback with nothing to spell, takes the stored
 *   file's own name, which is always plain ASCII.
 */
final class DownloadFilename
{
    public static function inlineDisposition(?string $name, string $storedPath): string
    {
        $storedName = basename($storedPath);
        $name = self::withStoredExtension(self::separatorsReplaced(Document::cleanOriginalFilename((string) $name)), $storedName);

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, self::fallback($name, $storedName));
    }

    /**
     * The name in printable ASCII, with no `%`, `/` or `\`; the stored file's
     * name when nothing but its extension would be left.
     */
    public static function fallback(string $name, string $storedName): string
    {
        $fallback = trim(self::separatorsReplaced((string) preg_replace('/[^\x20-\x7E]|%/', '', Str::ascii($name))));

        return trim(pathinfo($fallback, PATHINFO_FILENAME), '._- ') === '' ? $storedName : $fallback;
    }

    /**
     * The name with the stored file's extension in place of its own, unless
     * the two already match in any letter case. A name with nothing before
     * its extension takes the stored file's name.
     */
    private static function withStoredExtension(string $name, string $storedName): string
    {
        $stored = pathinfo($storedName, PATHINFO_EXTENSION);
        $base = rtrim($name, '. ');

        if ($stored !== '' && strcasecmp(pathinfo($base, PATHINFO_EXTENSION), $stored) === 0 && $base === $name) {
            return $name;
        }

        $base = str_contains($base, '.') ? pathinfo($base, PATHINFO_FILENAME) : $base;

        if (trim($base, '._- ') === '') {
            return $storedName;
        }

        return $stored === '' ? $base : "{$base}.{$stored}";
    }

    private static function separatorsReplaced(string $value): string
    {
        return str_replace(['/', '\\'], '_', $value);
    }
}
