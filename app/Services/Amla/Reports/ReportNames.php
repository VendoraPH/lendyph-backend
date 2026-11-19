<?php

namespace App\Services\Amla\Reports;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\Amla\Detection\Finding;

/**
 * How the AMLA reports name things: a subject by name only (never contacts),
 * a branch by name ("Organisation-wide" for none), a staff user by name.
 */
final class ReportNames
{
    public const ORGANISATION_WIDE = 'Organisation-wide';

    public static function subject(?Borrower $member, ?GCashNonMember $walkIn): string
    {
        if ($member !== null) {
            return trim((string) $member->full_name);
        }

        return $walkIn === null ? Finding::NOT_AVAILABLE : trim((string) $walkIn->full_name).' (walk-in)';
    }

    public static function branch(?Branch $branch): string
    {
        return $branch?->name ?? self::ORGANISATION_WIDE;
    }

    public static function user(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim((string) $user->full_name);

        return $name !== '' ? $name : (string) $user->username;
    }
}
