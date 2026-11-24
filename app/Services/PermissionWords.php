<?php

namespace App\Services;

/**
 * Permission keys in words, for refusals: users never see a permission key.
 *
 * A key reads as its module and action, the way the roles screen names them
 * ("borrowers:view" → "Members: View", "branches:view_all" → "Branches: See all
 * branches"). Module names follow the screen's (`permission-module-names.ts`):
 * the keys that read differently are listed, every other one is the key in
 * title case.
 */
final class PermissionWords
{
    /**
     * Permissions only a super admin hands out, since no admin holds them, with
     * what they let a role do: the Chairman/Chairwoman's edit during approval,
     * held by the Chair's role alone (RoleAndPermissionSeeder::CHAIR_ONLY_PERMISSIONS).
     *
     * @var array<string, string>
     */
    public const SUPER_ADMIN_ONLY = [
        LoanApprovalEditService::PERMISSION => 'edit loans during approval',
    ];

    /** Module keys whose name on screen isn't the key in words. */
    private const MODULES = [
        'borrowers' => 'Members',
        'gcash' => 'GCash',
        'cda' => 'CDA Compliance',
        'amla' => 'AMLA Compliance',
    ];

    /** Actions whose words aren't the key's. */
    private const ACTIONS = [
        'view_all' => 'See all branches',
        'edit_in_approval' => 'Edit during approval',
        'write_off' => 'Write off restructure shortfall',
    ];

    /** Short words that stay lower case in a title, unless they come first. */
    private const SMALL_WORDS = ['of', 'and', 'the', 'to', 'for', 'in', 'on'];

    /** "borrowers:view" → "Members: View". */
    public static function of(string $permission): string
    {
        [$module, $action] = array_pad(explode(':', $permission, 2), 2, '');
        $name = self::MODULES[$module] ?? self::titleCase($module);

        return $action === '' ? $name : $name.': '.(self::ACTIONS[$action] ?? ucfirst(str_replace('_', ' ', $action)));
    }

    /**
     * The first `$limit` permissions in words, joined with commas, and "…"
     * when there are more.
     *
     * @param  iterable<string>  $permissions
     */
    public static function list(iterable $permissions, int $limit = 3): string
    {
        $words = array_map(self::of(...), is_array($permissions) ? array_values($permissions) : iterator_to_array($permissions, false));

        return implode(', ', array_slice($words, 0, $limit)).(count($words) > $limit ? ', …' : '');
    }

    /** "chart_of_accounts" → "Chart of Accounts". */
    private static function titleCase(string $key): string
    {
        $words = array_values(array_filter(explode('_', $key), static fn (string $word): bool => $word !== ''));

        return implode(' ', array_map(
            static fn (string $word, int $index): string => $index > 0 && in_array($word, self::SMALL_WORDS, true) ? $word : ucfirst($word),
            $words,
            array_keys($words),
        ));
    }
}
