<?php

namespace App\Services\Reminders\Drivers;

/**
 * One rendered reminder, as a driver receives it.
 */
final readonly class OutgoingReminder
{
    public function __construct(
        public int $messageId,
        public int $queueItemId,
        public string $channel,
        public string $recipient,
        public string $maskedRecipient,
        public ?string $subject,
        public string $body,
        public ?int $smsParts,
    ) {}
}
