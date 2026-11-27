<?php

namespace App\Services\Reminders;

/**
 * The member's own contact for one channel, or why there is none to use.
 */
final readonly class ContactCheck
{
    private function __construct(
        public ?string $recipient,
        public ?string $masked,
        public ?string $failureCode,
        public ?string $failureReason,
    ) {}

    public static function usable(string $recipient, string $masked): self
    {
        return new self($recipient, $masked, null, null);
    }

    public static function unusable(string $code, string $reason, ?string $masked = null): self
    {
        return new self(null, $masked, $code, $reason);
    }

    public function isUsable(): bool
    {
        return $this->recipient !== null;
    }
}
