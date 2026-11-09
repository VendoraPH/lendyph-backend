<?php

namespace App\Services\Reminders\Drivers;

/**
 * What a driver says happened to one hand-over.
 *
 * - sent: accepted.
 * - temporary failure: try again after the retry delay, up to the attempt limit.
 * - permanent failure: never retried; the contact is flagged (invalid number,
 *   bounced or blocked address).
 * - not set up: no driver can send this channel; never retried, contact untouched.
 */
final readonly class DriverResult
{
    public const SENT = 'sent';

    public const TEMPORARY = 'temporary';

    public const PERMANENT = 'permanent';

    public const NOT_SET_UP = 'not_set_up';

    private function __construct(
        public string $outcome,
        public ?string $reference = null,
        public ?string $reasonCode = null,
        public ?string $reason = null,
        public ?float $cost = null,
        public string $deliveryStatus = 'sent',
    ) {}

    public static function sent(?string $reference = null, ?float $cost = null): self
    {
        return new self(self::SENT, $reference, cost: $cost);
    }

    public static function temporaryFailure(string $reasonCode, string $reason): self
    {
        return new self(self::TEMPORARY, reasonCode: $reasonCode, reason: $reason, deliveryStatus: 'failed');
    }

    /**
     * @param  'failed'|'rejected'|'bounced'  $deliveryStatus
     */
    public static function permanentFailure(string $reasonCode, string $reason, string $deliveryStatus = 'failed'): self
    {
        return new self(self::PERMANENT, reasonCode: $reasonCode, reason: $reason, deliveryStatus: $deliveryStatus);
    }

    public static function notSetUp(): self
    {
        return new self(self::NOT_SET_UP, reasonCode: 'not_set_up', reason: 'Sending is not set up', deliveryStatus: 'failed');
    }
}
