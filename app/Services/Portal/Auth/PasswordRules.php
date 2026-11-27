<?php

namespace App\Services\Portal\Auth;

use App\Models\Borrower;
use App\Services\Portal\PortalSettings;
use Closure;

/**
 * The member's password rule (portal design §1): 10–128 characters (the
 * minimum is a setting, never below 10), with a letter and a number, not
 * containing the member number. Hashed with bcrypt by the model cast. No
 * breach-list lookup: the app makes no network calls.
 */
final class PasswordRules
{
    public const ABSOLUTE_MIN = 10;

    public function __construct(private readonly PortalSettings $settings) {}

    public function minLength(): int
    {
        return max(self::ABSOLUTE_MIN, $this->settings->current()->password_min_length);
    }

    /** @return list<mixed> */
    public function rules(Borrower $member): array
    {
        $numbers = array_values(array_filter(array_unique([
            MemberNumber::normalise($member->membership_number),
            MemberNumber::normalise($member->borrower_code),
        ])));

        return [
            'required', 'string', 'confirmed',
            'min:'.$this->minLength(), 'max:'.PortalSettings::PASSWORD_MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail) use ($numbers): void {
                if (! is_string($value)) {
                    return;
                }

                if (! preg_match('/\pL/u', $value) || ! preg_match('/\pN/u', $value)) {
                    $fail('The password needs at least one letter and one number.');

                    return;
                }

                $squashed = MemberNumber::normalise($value);

                foreach ($numbers as $number) {
                    if (str_contains($squashed, $number)) {
                        $fail('The password can\'t contain your member number.');

                        return;
                    }
                }
            },
        ];
    }

    /** @return array{min_length: int, max_length: int, needs_letter: bool, needs_number: bool, no_member_number: bool} */
    public function describe(): array
    {
        return [
            'min_length' => $this->minLength(),
            'max_length' => PortalSettings::PASSWORD_MAX_LENGTH,
            'needs_letter' => true,
            'needs_number' => true,
            'no_member_number' => true,
        ];
    }
}
