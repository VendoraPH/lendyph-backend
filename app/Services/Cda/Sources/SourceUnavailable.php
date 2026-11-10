<?php

namespace App\Services\Cda\Sources;

use RuntimeException;

/**
 * A source that can't be resolved: its family's resolver isn't built yet, or
 * the family doesn't know the key. The message is in words, so a report can
 * show it as an issue.
 */
final class SourceUnavailable extends RuntimeException
{
    public static function noResolver(string $key, string $class): self
    {
        return new self("The source \"{$key}\" isn't available yet ({$class} is not built).");
    }

    public static function unknownKey(string $key, string $family): self
    {
        return new self("The {$family} sources don't know \"{$key}\".");
    }
}
