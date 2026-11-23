<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use App\Models\OriginationOtpChallenge;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\Drivers\OutgoingReminder;
use App\Support\PhilippineMobile;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * An applicant's one-time codes (origination decisions 2, 6 and 7), built
 * like the portal's OTP: 6 digits, valid 5 minutes, 5 tries, kept hashed.
 *
 * - Channels: SMS only, and only while something can deliver it: a reminder
 *   driver that reaches someone, or the staging test-code mode on a test
 *   link. Email waits
 *   for a mail driver. With no channel, asking for a code answers 409.
 * - Sending: an allow-listed staff number in test mode sees its code on
 *   screen; otherwise a delivering driver texts it to a number the allow-list
 *   permits. Any other number gets nothing, with the same answer. The code is
 *   never logged and never stored in plain text.
 * - A deployment hands out at most `daily_cap` codes a Manila day that reach
 *   someone (sent, or shown in test mode), against SMS pumping, and one
 *   mobile asks for at most `daily_cap_per_mobile` (429 either way). The
 *   per-minute and per-hour limits are route limiters.
 * - Asking again closes the earlier open code for the same link, purpose and
 *   mobile, so only the newest code works.
 */
final class ApplicantOtp
{
    public const NO_CHANNEL = 'Online applications open once text messages can be sent.';

    public const WRONG_CODE = 'The code is wrong or has expired. Ask for a new code.';

    public const BUSY = 'Online applications are busy right now. Please try again tomorrow or contact the office.';

    public function __construct(
        private readonly ApplicantSms $sms,
        private readonly TestCodes $testCodes,
    ) {}

    public const MOBILE_BUSY = 'This number has asked for too many codes today. Please try again tomorrow.';

    /**
     * The channels a code can reach an applicant on $link by.
     *
     * @return list<string>
     */
    public function channels(OriginationLink $link): array
    {
        return $this->testCodes->enabledOn($link) || $this->sms->reachesSomeone() ? ['sms'] : [];
    }

    /**
     * Hand out a code for $mobile (already a valid Philippine mobile).
     *
     * @return array{resend_after: int, expires_in: int, channel: string, destination: string, delivered: bool, test_mode?: array{code: string, notice: string}}
     */
    public function request(OriginationLink $link, string $purpose, string $mobile, ?string $reference, ?string $ip): array
    {
        if ($this->channels($link) === []) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => self::NO_CHANNEL, 'code' => 'no_channel'], 409));
        }

        $normalised = (string) PhilippineMobile::normalise($mobile);
        $hash = OriginationApplication::mobileHash($normalised);
        $minutes = (int) config('loan_origination_intake.otp.minutes', 5);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $shown = $this->testCodes->serves($link, $normalised);
        $willSend = ! $shown && $this->sms->reachesSomeone() && $this->sms->permits($normalised);

        $challenge = DB::transaction(function () use ($link, $purpose, $hash, $reference, $code, $shown, $willSend, $minutes, $ip): OriginationOtpChallenge {
            // Read under a lock on the day's rows, so two requests at a cap
            // can't both pass. The deployment's cap counts only codes that
            // reached someone (sent, or shown in test mode), so requests for
            // numbers nothing is sent to can't use it up for everyone.
            $reached = OriginationOtpChallenge::query()
                ->where('created_at', '>=', now()->startOfDay())
                ->where(fn ($query) => $query->where('delivered', true)->orWhere('test_mode', true))
                ->lockForUpdate()->count();

            if ($reached >= (int) config('loan_origination_intake.otp.daily_cap', 300)) {
                throw new HttpResponseException(response()->json(['success' => false, 'message' => self::BUSY, 'code' => 'daily_cap'], 429));
            }

            $forMobile = OriginationOtpChallenge::query()
                ->where('mobile_hash', $hash)->where('created_at', '>=', now()->startOfDay())
                ->lockForUpdate()->count();

            if ($forMobile >= (int) config('loan_origination_intake.otp.daily_cap_per_mobile', 10)) {
                throw new HttpResponseException(response()->json(['success' => false, 'message' => self::MOBILE_BUSY, 'code' => 'mobile_daily_cap'], 429));
            }

            OriginationOtpChallenge::query()
                ->where('link_id', $link->id)->where('purpose', $purpose)->where('mobile_hash', $hash)
                ->whereNull('verified_at')->whereNull('closed_at')
                ->update(['closed_at' => now()]);

            return OriginationOtpChallenge::query()->create([
                'link_id' => $link->id,
                'purpose' => $purpose,
                'channel' => 'sms',
                'mobile_hash' => $hash,
                'reference_number' => $reference,
                'code_hash' => Hash::make($code),
                'test_mode' => $shown,
                // Counted at once under the cap's lock, so codes asked for at
                // the same moment can't pass it; set back if the send fails.
                'delivered' => $willSend,
                'expires_at' => now()->addMinutes($minutes),
                'ip_address' => $ip,
            ]);
        });

        $delivered = $willSend && $this->send($challenge, $normalised, $code, $minutes);

        if ($willSend && ! $delivered) {
            $challenge->forceFill(['delivered' => false])->save();
        }

        return [
            'resend_after' => (int) config('loan_origination_intake.otp.resend_seconds', 60),
            'expires_in' => $minutes * 60,
            'channel' => 'sms',
            'destination' => PhilippineMobile::mask($normalised),
            'delivered' => $delivered,
            ...($shown ? ['test_mode' => ['code' => $code, 'notice' => TestCodes::NOTICE]] : []),
        ];
    }

    /**
     * Check $code against the newest open code for the target and use it up.
     *
     * @throws ValidationException 422 on `code`, the same words whatever was wrong
     */
    public function verify(OriginationLink $link, string $purpose, string $mobile, string $code, ?string $reference): void
    {
        $hash = OriginationApplication::mobileHash($mobile);
        $max = (int) config('loan_origination_intake.otp.max_attempts', 5);

        $passed = DB::transaction(function () use ($link, $purpose, $hash, $code, $reference, $max): bool {
            /** @var ?OriginationOtpChallenge $challenge */
            $challenge = OriginationOtpChallenge::query()
                ->where('link_id', $link->id)->where('purpose', $purpose)->where('mobile_hash', $hash)
                ->orderByDesc('id')->lockForUpdate()->first();

            if ($challenge === null || ! $challenge->isOpen() || $challenge->attempts >= $max || $challenge->reference_number !== $reference) {
                return false;
            }

            $challenge->attempts++;

            if (! Hash::check($code, $challenge->code_hash)) {
                if ($challenge->attempts >= $max) {
                    $challenge->closed_at = now();
                }

                $challenge->save();

                return false;
            }

            $challenge->verified_at = now();
            $challenge->save();

            return true;
        });

        if (! $passed) {
            throw ValidationException::withMessages(['code' => self::WRONG_CODE]);
        }
    }

    private function send(OriginationOtpChallenge $challenge, string $mobile, string $code, int $minutes): bool
    {
        $result = $this->sms->driver()->send(new OutgoingReminder(
            messageId: (int) $challenge->getKey(),
            queueItemId: 0,
            channel: 'sms',
            recipient: $mobile,
            maskedRecipient: PhilippineMobile::mask($mobile),
            subject: null,
            body: "Your loan application code is {$code}. It expires in {$minutes} minutes. Never share it with anyone, including staff.",
            smsParts: 1,
        ));

        return $result->outcome === DriverResult::SENT;
    }
}
