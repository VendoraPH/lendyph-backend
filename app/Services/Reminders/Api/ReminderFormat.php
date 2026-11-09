<?php

namespace App\Services\Reminders\Api;

use App\Models\Borrower;
use App\Models\BorrowerNotificationPreference;
use App\Models\Loan;
use App\Services\Reminders\MemberContact;
use App\Support\PhilippineMobile;
use Carbon\CarbonInterface;

/**
 * The reminders API's shared formatting: times as ISO 8601 with their offset,
 * money as the engine's figure, and contacts only ever masked.
 */
final class ReminderFormat
{
    public static function time(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }

    public static function date(?CarbonInterface $day): ?string
    {
        return $day?->toDateString();
    }

    public static function amount(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * `HH:mm` from a stored `HH:mm:ss` time.
     */
    public static function clock(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    public static function loanNumber(?Loan $loan): string
    {
        return (string) ($loan?->loan_account_number ?: $loan?->application_number);
    }

    /**
     * The member's current mobile number or email, masked, for a row that
     * hasn't been handed over yet (so has no stored masked recipient).
     */
    public static function maskedContact(?Borrower $member, string $channel): ?string
    {
        if ($member === null) {
            return null;
        }

        if ($channel === 'email') {
            $email = BorrowerNotificationPreference::normaliseEmail($member->email);

            return $email === null ? null : MemberContact::maskEmail($email);
        }

        $mobile = trim((string) $member->contact_number);

        return $mobile === '' ? null : PhilippineMobile::mask($mobile);
    }
}
