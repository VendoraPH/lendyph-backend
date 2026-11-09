<?php

namespace App\Services\Reminders\Drivers;

use Illuminate\Support\Facades\Log;

/**
 * The default driver: writes one log line and sends nothing to anyone.
 *
 * The line holds the ids, the channel, the masked recipient and the SMS parts,
 * never the text or the full number or address.
 */
final class LogDriver implements ReminderDriver
{
    public function name(): string
    {
        return 'log';
    }

    public function reachesNobody(): bool
    {
        return true;
    }

    public function send(OutgoingReminder $reminder): DriverResult
    {
        Log::info('Reminder handed to the log driver; nothing was sent.', [
            'queue_item_id' => $reminder->queueItemId,
            'message_id' => $reminder->messageId,
            'channel' => $reminder->channel,
            'recipient' => $reminder->maskedRecipient,
            'sms_parts' => $reminder->smsParts,
        ]);

        return DriverResult::sent('log-'.$reminder->messageId);
    }

    public function creditsRemaining(): ?int
    {
        return null;
    }
}
