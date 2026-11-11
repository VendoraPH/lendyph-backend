<?php

namespace App\Support;

/**
 * Staff-typed text placed inside a sentence that ends with its own full stop.
 *
 * A reason typed with an ending of its own ("…future.") would otherwise read
 * "…future.." in an audit description, so its trailing `.`, `!` or `?` (and any
 * space after them) is dropped first. Display text only: the reason stored in
 * a row's values stays as typed.
 */
final class Sentence
{
    public static function withoutEnding(string $text): string
    {
        return rtrim($text, " \t\n\r\0\x0B.!?");
    }
}
