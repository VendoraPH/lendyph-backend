<?php

namespace App\Rules;

use App\Models\Role;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A role's display_name may not read as any OTHER role's key or display name.
 *
 * Users are given roles by picking a label, so two roles that read alike are
 * a privilege-escalation path: label `super_admin` as "Admin" and a platform
 * super_admin picking "Admin" for a client user hands out the Gate::before
 * bypass. Both sides are normalised first (lowercase, `_` and whitespace runs
 * collapsed to one space, trimmed), so "Super_Admin" and "super  admin" both
 * collide with the key `super_admin`. A role may keep a display name equal to
 * its own key.
 */
class UniqueRoleDisplayName implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreRoleId = null) {}

    public static function normalise(string $value): string
    {
        return trim((string) preg_replace('/[\s_]+/u', ' ', mb_strtolower($value)));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $wanted = self::normalise($value);

        $taken = Role::query()
            ->when($this->ignoreRoleId !== null, fn ($query) => $query->whereKeyNot($this->ignoreRoleId))
            ->get(['id', 'name', 'display_name'])
            ->contains(fn (Role $role) => self::normalise($role->name) === $wanted
                || ($role->display_name !== null && self::normalise($role->display_name) === $wanted));

        if ($taken) {
            $fail('The :attribute is already used by another role.');
        }
    }
}
