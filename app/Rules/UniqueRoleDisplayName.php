<?php

namespace App\Rules;

use App\Models\Role;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * A role's display_name or key may not read as any OTHER role's key or
 * display name.
 *
 * Users are given roles by picking a label, so two roles that read alike are
 * a privilege-escalation path: label `super_admin` as "Admin" and a platform
 * super_admin picking "Admin" for a client user hands out the Gate::before
 * bypass. The same goes for keys: relabel `cashier` as "Teller", then create
 * a role keyed `teller`, and both read "Teller". Both sides are normalised
 * first (Unicode NFKC, lowercase, `_` and whitespace runs collapsed to one
 * space, trimmed), so "Super_Admin", "super  admin" and a fullwidth
 * "Ｓｕｐｅｒ Ａｄｍｉｎ" all collide with the key `super_admin`. A role may
 * keep a display name or key equal to its own.
 *
 * Normalizer comes from ext-intl, or from symfony/polyfill-intl-normalizer
 * (pulled in by laravel/framework through symfony/string) when it is absent.
 */
class UniqueRoleDisplayName implements ValidationRule
{
    /**
     * Characters a display name may not contain: control and format
     * characters (zero-width, bidi), combining marks, and the Hangul fillers,
     * all of which let two labels look identical in the role picker.
     */
    public const FORBIDDEN_CHARACTERS = '/[\p{Cc}\p{Cf}\p{Mn}\x{115F}\x{1160}\x{3164}\x{FFA0}]/u';

    public function __construct(private readonly ?int $ignoreRoleId = null) {}

    public static function normalise(string $value): string
    {
        $folded = Normalizer::normalize($value, Normalizer::FORM_KC);

        return trim((string) preg_replace('/[\s_]+/u', ' ', mb_strtolower($folded === false ? $value : $folded)));
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
