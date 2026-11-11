<?php

namespace App\Services\Portal\Auth;

use App\Models\Borrower;

/**
 * The sign-in identifier (portal design §1): the member number
 * (`membership_number`, else `borrower_code`), with case and spaces ignored.
 */
final class MemberNumber
{
    public const MAX_LENGTH = 40;

    public static function normalise(mixed $input): string
    {
        if (! is_string($input)) {
            return '';
        }

        return mb_strtoupper((string) preg_replace('/\s+/u', '', $input));
    }

    /**
     * A keyed hash of the normalised number, for the rate-limit keys and the
     * login history. Keyed with the app key, so a short member number can't
     * be read back from a stored hash by trying every number.
     */
    public static function hash(mixed $input): string
    {
        return hash_hmac('sha256', 'portal-member-number|'.self::normalise($input), (string) config('app.key'));
    }

    /** The member this number signs in as, or null. */
    public static function find(mixed $input): ?Borrower
    {
        $number = self::normalise($input);

        if ($number === '' || mb_strlen($number) > self::MAX_LENGTH) {
            return null;
        }

        return Borrower::query()
            ->whereRaw("REPLACE(UPPER(membership_number), ' ', '') = ?", [$number])
            ->first()
            ?? Borrower::query()
                ->where(fn ($unnumbered) => $unnumbered->whereNull('membership_number')->orWhere('membership_number', ''))
                ->whereRaw("REPLACE(UPPER(borrower_code), ' ', '') = ?", [$number])
                ->first();
    }

    /** The number a member signs in with, as shown to them and to staff. */
    public static function of(Borrower $member): string
    {
        return filled($member->membership_number) ? (string) $member->membership_number : (string) $member->borrower_code;
    }
}
