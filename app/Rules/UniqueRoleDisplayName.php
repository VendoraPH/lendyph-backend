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
     * characters (zero-width, bidi), unassigned and private-use code points
     * (browsers draw them as nothing or a box), combining marks, the Hangul
     * fillers and the Braille blank, all of which let two labels look
     * identical in the role picker. Unassigned means unassigned in the PCRE
     * build's Unicode tables.
     */
    public const FORBIDDEN_CHARACTERS = '/[\p{Cc}\p{Cf}\p{Cn}\p{Co}\p{Mn}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}]/u';

    public function __construct(private readonly ?int $ignoreRoleId = null) {}

    /**
     * The comparison form of a role key or label, or null when the value is
     * not valid UTF-8 (refused, never compared as raw bytes).
     */
    public static function normalise(string $value): ?string
    {
        $folded = mb_check_encoding($value, 'UTF-8') ? Normalizer::normalize($value, Normalizer::FORM_KC) : false;

        if ($folded === false) {
            return null;
        }

        return trim((string) preg_replace('/[\s_]+/u', ' ', mb_strtolower($folded)));
    }

    /**
     * A submitted display name as it is validated and stored: trimmed and
     * NFC-composed, so a decomposed accent (e + U+0301) becomes the
     * precomposed character. Null when the value is not valid UTF-8.
     */
    public static function compose(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $composed = Normalizer::normalize(trim($value), Normalizer::FORM_C);

        return $composed === false ? null : $composed;
    }

    /**
     * Whether `$value` reads as the key or display name of any of `$roles`
     * other than `$ignoreRoleId`. A value that cannot be normalised counts as
     * taken.
     *
     * @param  iterable<Role>  $roles
     */
    public static function readsAsAnotherRole(iterable $roles, string $value, ?int $ignoreRoleId = null): bool
    {
        $wanted = self::normalise($value);

        if ($wanted === null) {
            return true;
        }

        foreach ($roles as $role) {
            if ($ignoreRoleId !== null && $role->id === $ignoreRoleId) {
                continue;
            }

            if (self::normalise($role->name) === $wanted
                || ($role->display_name !== null && self::normalise($role->display_name) === $wanted)) {
                return true;
            }
        }

        return false;
    }

    public static function message(string $attribute): string
    {
        return 'The '.str_replace('_', ' ', $attribute).' is already used by another role.';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $fail('The :attribute must be valid UTF-8 text.');

            return;
        }

        $roles = Role::query()
            ->when($this->ignoreRoleId !== null, fn ($query) => $query->whereKeyNot($this->ignoreRoleId))
            ->get(['id', 'name', 'display_name']);

        if (self::readsAsAnotherRole($roles, $value)) {
            $fail('The :attribute is already used by another role.');
        }
    }
}
