<?php

namespace App\Support;

/**
 * A record id as validation rules must read it.
 *
 * The `integer` rule and MySQL's `exists` lookup both accept more than a bare
 * digit string: " 5", 5.0 and "5 " all resolve to row 5. A rule that reads
 * only `is_int` or `ctype_digit` skips those, so a crafted id passed `exists`
 * and walked round the rule's refusal. Reading the id here gives every rule
 * the same whole number `exists` matched, or null when it isn't one.
 */
final class WholeNumberId
{
    public static function from(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }
}
