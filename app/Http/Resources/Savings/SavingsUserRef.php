<?php

namespace App\Http\Resources\Savings;

use App\Models\User;

/**
 * How every savings response names a user (the frontend's `SavingsUserRef`):
 * `{id, name}`, the user's full name (their username when it has none), or
 * null when the system did it (the screen shows "System").
 * Load the relation first (`creator`, `updater`, …) so a list costs one query
 * per relation, not one per row.
 */
final class SavingsUserRef
{
    /** @return array{id: int, name: string}|null */
    public static function from(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = trim((string) $user->full_name);

        return ['id' => (int) $user->id, 'name' => $name !== '' ? $name : (string) $user->username];
    }
}
