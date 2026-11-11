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

    /**
     * Printable ASCII only (review M2): a full-width or invisible character
     * would otherwise reach the same member under another spelling, and
     * another limiter key.
     */
    public const MESSAGES = [
        'member_number.regex' => 'Enter your member number using the letters, numbers and dashes printed on your slip.',
    ];

    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'max:'.self::MAX_LENGTH, 'regex:/^[\x20-\x7E]+$/'];
    }

    /**
     * The per-member limiter key: the member found when the number reaches
     * one, so every spelling of it shares one bucket, else the normalised
     * input's keyed hash.
     */
    public static function limiterKey(mixed $input): string
    {
        $member = self::find($input);

        return $member === null ? 'input:'.self::hash($input) : 'member:'.$member->getKey();
    }

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
