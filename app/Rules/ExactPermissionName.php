<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Permission;

/**
 * An existing permission name, matched with its exact case.
 *
 * `exists:permissions,name` compares under the column's case-insensitive
 * collation, so "LOANS:VIEW" passed it and then reached Spatie, which looks
 * names up case-sensitively and threw PermissionDoesNotExist: a 500. Matching
 * the stored spelling exactly refuses it with a 422 instead. The names that
 * pass are the same exact-case names as before.
 */
class ExactPermissionName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $stored = Permission::query()->where('name', $value)->pluck('name');

        if (! $stored->contains(fn (string $name) => $name === $value)) {
            $fail('validation.exists')->translate();
        }
    }
}
