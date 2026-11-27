<?php

namespace App\Services\Cda\Reports;

use JsonException;

/**
 * The canonical JSON a CDA snapshot is stored as and its checksum taken of
 * (design §5): object keys sorted at every level (lists keep their order),
 * Unicode and slashes unescaped, no whitespace. The same data always gives
 * the same bytes, so SHA-256 of the payload can be recomputed and compared
 * at any time. Money is written as strings, so no float formatting can creep
 * in.
 */
final class CanonicalJson
{
    /** @throws JsonException */
    public static function encode(mixed $data): string
    {
        return json_encode(self::sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function checksum(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    private static function sort(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        if (array_is_list($data)) {
            return array_map(self::sort(...), $data);
        }

        ksort($data, SORT_STRING);

        return array_map(self::sort(...), $data);
    }
}
