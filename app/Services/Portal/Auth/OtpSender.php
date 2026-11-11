<?php

namespace App\Services\Portal\Auth;

use App\Models\PortalCode;
use App\Models\PortalOtpChallenge;
use App\Models\PortalUser;
use App\Services\Reminders\AllowList;
use App\Services\Reminders\Drivers\DriverResolver;
use App\Services\Reminders\Drivers\OutgoingReminder;
use App\Support\PhilippineMobile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The one-time password step (portal design §1), used only when
 * `otp_required` is on, which can't be switched on while SMS is log-only.
 *
 * A 6-digit code, valid 5 minutes, 5 tries, stored hashed, sent to the
 * member's registered mobile through the reminder SMS driver. It reaches only
 * a number the allow-list permits (on staging, staff numbers only); the log
 * driver logs the masked number and never the text.
 */
final class OtpSender
{
    public const NO_MOBILE = 'We couldn\'t send a one-time password to this member\'s mobile number. Please contact your branch.';

    public function __construct(
        private readonly DriverResolver $drivers,
        private readonly AllowList $allowList,
    ) {}

    /** Start a challenge for this code and send it. Returns the masked number. */
    public function challenge(PortalUser $portalUser, PortalCode $code): string
    {
        $mobile = PhilippineMobile::normalise($portalUser->borrower?->contact_number);

        if ($mobile === null) {
            throw ValidationException::withMessages(['otp' => [self::NO_MOBILE]]);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $masked = PhilippineMobile::mask($mobile);

        $challenge = PortalOtpChallenge::query()->create([
            'portal_user_id' => $portalUser->getKey(),
            'portal_code_id' => $code->getKey(),
            'purpose' => $code->kind,
            'code_hash' => Hash::make($otp),
            'masked_recipient' => $masked,
            'expires_at' => now()->addMinutes(PortalOtpChallenge::MINUTES),
        ]);

        if ($this->allowList->permits('sms', $mobile)) {
            $this->drivers->for('sms')->send(new OutgoingReminder(
                messageId: (int) $challenge->getKey(),
                queueItemId: 0,
                channel: 'sms',
                recipient: $mobile,
                maskedRecipient: $masked,
                subject: null,
                body: "Your member portal one-time password is {$otp}. It expires in 5 minutes. Never share it with anyone, including staff.",
                smsParts: 1,
            ));
        }

        return $masked;
    }
}
