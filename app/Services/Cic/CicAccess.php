<?php

namespace App\Services\Cic;

use App\Models\User;
use App\Services\BranchAccess;

/**
 * Who may do what in CIC reporting (the CIC build plan, decision 8).
 *
 * - The file covers the whole lender, so every `/cic` route needs
 *   `branches:view_all` as well as its own `cic:*` permission.
 * - Member personal data (names, birthdate, IDs, addresses, contacts, employer,
 *   income) also needs `borrowers:view`; without it a row shows only its record
 *   type, subject or contract number and issue counts (the round fz rule).
 *
 * A refusal is a 403 with a message in words that never names a permission key.
 */
final class CicAccess
{
    public const VIEW = 'cic:view';

    public const PREPARE = 'cic:prepare';

    public const SUBMIT = 'cic:submit';

    public const REVIEW = 'cic:review';

    public const SETTINGS = 'cic:settings';

    /** @var list<string> the five, as seeded */
    public const PERMISSIONS = [self::VIEW, self::PREPARE, self::SUBMIT, self::REVIEW, self::SETTINGS];

    /** Member personal data on the record screens. */
    public const PERSONAL_DATA = 'borrowers:view';

    /** What each permission lets a user do, for refusals. */
    private const WORDS = [
        self::VIEW => 'view CIC reporting',
        self::PREPARE => 'prepare CIC files',
        self::SUBMIT => 'submit CIC files',
        self::REVIEW => 'record CIC\'s answer to a file',
        self::SETTINGS => 'manage CIC settings',
    ];

    /** The refusal for a caller without `branches:view_all`. */
    public const VIEW_ALL_REFUSAL = 'CIC files cover the whole lender, so this needs head-office access to all branches.';

    /**
     * The refusal for a caller missing the permission(s): "You don't have access
     * to prepare CIC files." A key with no words reads as "use this part of CIC
     * reporting", never as the key.
     *
     * @param  string|list<string>  $permissions
     */
    public static function refusal(string|array $permissions): string
    {
        $words = array_values(array_unique(array_map(
            fn (string $permission) => self::WORDS[$permission] ?? 'use this part of CIC reporting',
            (array) $permissions,
        )));

        return 'You don\'t have access to '.implode(' or ', $words).'.';
    }

    /** Whether the user holds `branches:view_all` and at least one of the permissions. */
    public function allows(User $user, string|array $permissions): bool
    {
        return $user->can(BranchAccess::VIEW_ALL) && $user->canAny((array) $permissions);
    }

    /**
     * 403 unless the user holds `branches:view_all` and at least one of the
     * permissions.
     *
     * @param  string|list<string>  $permissions
     */
    public function authorize(User $user, string|array $permissions): void
    {
        abort_unless($user->can(BranchAccess::VIEW_ALL), 403, self::VIEW_ALL_REFUSAL);
        abort_unless($user->canAny((array) $permissions), 403, self::refusal($permissions));
    }

    /** Whether the user may see members' personal data on CIC rows. */
    public function seesPersonalData(User $user): bool
    {
        return $user->can(self::PERSONAL_DATA);
    }
}
