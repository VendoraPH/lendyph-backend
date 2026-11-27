<?php

namespace App\Services\Reminders\Drivers;

/**
 * One rendered reminder, as a driver receives it. The body is plain text on
 * both channels: an email driver sends it as `text/plain`, never as HTML, so
 * the member's name and the branch's text are never read as markup.
 */
final readonly class OutgoingReminder
{
    public const CONTENT_TYPE = 'text/plain; charset=UTF-8';

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

    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }
}
