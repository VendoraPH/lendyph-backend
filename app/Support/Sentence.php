<?php

namespace App\Support;

/**
 * Staff-typed text placed at the end of a sentence.
 *
 * A reason typed with its own full stop ("…future.") would otherwise read
 * "…future.." in an audit description. Trailing full stops (and any space
 * around them) are dropped; a "?" or "!" is kept, and the sentence then adds
 * no full stop after it. Display text only: the reason stored in a row's
 * values stays as typed.
 */
final class Sentence
{
    /** A reason needs at least one letter or digit, so "." or "?" alone is refused. */
    public const HAS_WORDS = '/[\pL\pN]/u';

    public const NO_WORDS = 'A reason needs at least one letter or number.';

    /** The text without its trailing full stops and spaces ("Done. ." → "Done"). */
    public static function withoutEnding(string $text): string
    {
        return (string) preg_replace('/[\s.]+$/u', '', $text);
    }

    /** The text ending the sentence once: its own "?" or "!", else one full stop. Empty stays empty. */
    public static function ended(string $text): string
    {
        $text = self::withoutEnding($text);

        if ($text === '') {
            return '';
        }

        return preg_match('/[?!]$/u', $text) === 1 ? $text : $text.'.';
    }
}
