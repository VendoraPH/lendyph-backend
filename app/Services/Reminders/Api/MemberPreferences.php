<?php

namespace App\Services\Reminders\Api;

use App\Http\Resources\Reminders\ReminderPauseResource;
use App\Models\Borrower;
use App\Models\BorrowerNotificationPreference;
use App\Models\ReminderPause;
use Illuminate\Http\Request;

/**
 * A member's reminder preferences (frontend `BorrowerNotificationPreferences`).
 * The mobile number and email are masked; whether each is verified, or was
 * proved bad by a send, is read against the member's current value. A caller
 * who may not read the member's details (ContactVisibility) gets none of
 * them: the switches only.
 */
final class MemberPreferences
{
    public function __construct(private readonly ContactVisibility $visibility) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Borrower $member, Request $request): array
    {
        $preference = BorrowerNotificationPreference::query()->where('borrower_id', $member->id)->first()
            ?? new BorrowerNotificationPreference(['borrower_id' => $member->id]);
        $pause = ReminderPause::query()->inForce()->where('scope', 'borrower')->where('borrower_id', $member->id)
            ->with(ReminderPauseResource::RELATIONS)->latest('paused_at')->first();

        $contact = $this->visibility->allows($request->user(), $member->branch_id === null ? null : (int) $member->branch_id);
        $invalid = match (true) {
            $preference->mobileFlaggedFor($member->contact_number) => $preference->mobile_invalid_reason,
            $preference->emailFlaggedFor($member->email) => $preference->email_invalid_reason,
            default => null,
        };

        return [
            'borrower_id' => $member->id,
            'sms_enabled' => (bool) $preference->sms_enabled,
            'email_enabled' => (bool) $preference->email_enabled,
            'preferred_channel' => $preference->preferred_channel,
            'language' => $preference->language,
            'mobile_number' => $contact ? ReminderFormat::maskedContact($member, 'sms') : null,
            'mobile_verified' => $contact && $preference->mobileVerifiedFor($member->contact_number),
            'email' => $contact ? ReminderFormat::maskedContact($member, 'email') : null,
            'email_verified' => $contact && $preference->emailVerifiedFor($member->email),
            'invalid_contact_reason' => $contact ? $invalid : null,
            'pause' => $pause === null ? null : (new ReminderPauseResource($pause))->resolve($request),
        ];
    }

    /**
     * @param  array{sms_enabled?: bool, email_enabled?: bool, preferred_channel?: string, language?: string}  $data
     */
    public function update(Borrower $member, array $data): void
    {
        BorrowerNotificationPreference::query()->firstOrNew(['borrower_id' => $member->id])->fill($data)->save();
    }
}
