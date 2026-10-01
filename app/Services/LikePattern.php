<?php

namespace App\Services;

/**
 * The one place a search term becomes a LIKE pattern.
 *
 * Interpolating the raw term (`"%{$term}%"`) let it carry LIKE's own
 * wildcards, so a search of `%` or `_` listed every row and `50%` also found
 * `500`. `%`, `_` and `\` are escaped with MySQL's default LIKE escape
 * character, `\`, so each matches only itself.
 */
final class LikePattern
{
    /**
     * A LIKE pattern matching `$term` anywhere, with `%`, `_` and `\` matched
     * literally.
     */
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
