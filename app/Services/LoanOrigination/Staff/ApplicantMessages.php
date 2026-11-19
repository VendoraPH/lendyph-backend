<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationApplicantMessage;
use App\Models\OriginationApplication;
use App\Models\User;

/**
 * Messages meant for an applicant, recorded and never sent (LO-B3). No
 * driver can reach an applicant until the owner signs (origination §6), and
 * testing.md forbids contacting real people, so this class holds no sending
 * code at all (OriginationApplicantMessagesArchTest): it writes the log row,
 * which the screens show as "Logged, not sent".
 *
 * The channel is the one a real send would use: SMS while the application
 * has a mobile, else email, else none.
 */
final class ApplicantMessages
{
    /**
     * @param  list<string>|null  $documentTypes
     */
    public function log(OriginationApplication $application, string $kind, string $body, ?User $user, ?array $documentTypes = null): OriginationApplicantMessage
    {
        return OriginationApplicantMessage::query()->create([
            'application_id' => $application->id,
            'kind' => $kind,
            'channel' => $this->channelFor($application),
            'body' => $body,
            'document_types' => $documentTypes,
            'delivery' => OriginationApplicantMessage::DELIVERY_LOGGED,
            'user_id' => $user?->id,
        ]);
    }

    public function channelFor(OriginationApplication $application): ?string
    {
        return match (true) {
            filled($application->mobile) => 'sms',
            filled($application->email) => 'email',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(OriginationApplicantMessage $message): array
    {
        return [
            'id' => $message->id,
            'kind' => $message->kind,
            'kind_label' => OriginationApplicantMessage::KIND_LABELS[$message->kind] ?? $message->kind,
            'channel' => $message->channel,
            'channel_label' => match ($message->channel) {
                'sms' => 'Text message',
                'email' => 'Email',
                default => 'No contact on file',
            },
            'body' => $message->body,
            'document_types' => $message->document_types ?? [],
            'delivery' => $message->delivery,
            'delivery_label' => OriginationApplicantMessage::DELIVERY_LABELS[$message->delivery] ?? 'Logged, not sent',
            'delivered' => $message->delivery === 'sent',
            'sent_at' => null,
            'author_name' => $message->author?->full_name,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
