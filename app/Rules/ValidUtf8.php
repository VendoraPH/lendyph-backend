<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a string that is not valid UTF-8. Form-encoded requests can carry
 * any bytes (`Admin%FF`); `/u` patterns silently fail to match them, so this
 * runs first, under `bail`, ahead of every pattern check.
 */
class ValidUtf8 implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $fail('The :attribute must be valid UTF-8 text.');
        }
    }
}
