<?php

namespace App\Services\Reminders;

use App\Models\Borrower;
use App\Models\BorrowerNotificationPreference;
use App\Support\PhilippineMobile;

/**
 * Where a reminder may go: the member's own registered mobile number or email
 * address, and nothing else. Never a spouse, co-maker or reference, and never
 * a number that only looks like one (PhilippineMobile turns a landline into
 * null). A contact a send proved bad is not used again until it changes.
 */
final class MemberContact
{
    public function for(Borrower $member, string $channel, ?BorrowerNotificationPreference $preference): ContactCheck
    {
        return $channel === 'email' ? $this->email($member, $preference) : $this->mobile($member, $preference);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }

    private function mobile(Borrower $member, ?BorrowerNotificationPreference $preference): ContactCheck
    {
        $raw = trim((string) $member->contact_number);

        if ($raw === '') {
            return ContactCheck::unusable('no_contact', 'Member has no mobile number');
        }

        $number = PhilippineMobile::normalise($raw);

        if ($number === null) {
            return ContactCheck::unusable('invalid_contact', 'Mobile number is not a valid mobile number', PhilippineMobile::mask($raw));
        }

        $masked = PhilippineMobile::mask($number);

        if ($preference?->mobileFlaggedFor($number)) {
            return ContactCheck::unusable('contact_flagged', 'Mobile number was marked invalid: '.$preference->mobile_invalid_reason, $masked);
        }

        return ContactCheck::usable($number, $masked);
    }

    private function email(Borrower $member, ?BorrowerNotificationPreference $preference): ContactCheck
    {
        $email = BorrowerNotificationPreference::normaliseEmail($member->email);

        if ($email === null) {
            return ContactCheck::unusable('no_contact', 'Member has no email address');
        }

        $masked = self::maskEmail($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ContactCheck::unusable('invalid_contact', 'Email address is not valid', $masked);
        }

        if ($preference?->emailFlaggedFor($email)) {
            return ContactCheck::unusable('contact_flagged', 'Email address was marked invalid: '.$preference->email_invalid_reason, $masked);
        }

        return ContactCheck::usable($email, $masked);
    }
}
