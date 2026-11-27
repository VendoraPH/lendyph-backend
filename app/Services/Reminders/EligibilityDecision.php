<?php

namespace App\Services\Reminders;

use App\Models\AmortizationSchedule;
use App\Models\BorrowerNotificationPreference;
use Illuminate\Support\Carbon;

/**
 * The answer to "may this reminder go now?".
 *
 * - eligible: send it, to `recipient`, quoting `amountDue`.
 * - cancel: no longer needed (checks 1 to 3, or the rule no longer covers the loan).
 * - skip: still needed but not sendable (checks 4 to 8).
 * - wait: not yet; try again at `waitUntil`.
 *
 * `stage` is the last check passed, 0 to 8, so a caller can tell "the member
 * still owes and nothing pauses the loan" (stage 5 or more) from the rest.
 */
final readonly class EligibilityDecision
{
    public const ELIGIBLE = 'eligible';

    public const CANCEL = 'cancel';

    public const SKIP = 'skip';

    public const WAIT = 'wait';

    private function __construct(
        public string $outcome,
        public int $stage,
        public ?string $code = null,
        public ?string $reason = null,
        public ?float $amountDue = null,
        public ?AmortizationSchedule $period = null,
        public ?string $recipient = null,
        public ?string $maskedRecipient = null,
        public ?BorrowerNotificationPreference $preference = null,
        public ?Carbon $waitUntil = null,
    ) {}

    public static function cancel(int $stage, string $code, string $reason, ?float $amountDue = null): self
    {
        return new self(self::CANCEL, $stage, $code, $reason, $amountDue);
    }

    public static function skip(int $stage, string $code, string $reason, ?float $amountDue = null, ?string $masked = null): self
    {
        return new self(self::SKIP, $stage, $code, $reason, $amountDue, maskedRecipient: $masked);
    }

    public static function wait(Carbon $until, float $amountDue): self
    {
        return new self(self::WAIT, 7, amountDue: $amountDue, waitUntil: $until);
    }

    public static function eligible(float $amountDue, AmortizationSchedule $period, string $recipient, string $masked, ?BorrowerNotificationPreference $preference): self
    {
        return new self(self::ELIGIBLE, 8, amountDue: $amountDue, period: $period, recipient: $recipient, maskedRecipient: $masked, preference: $preference);
    }

    public function stillOwedAndNotPaused(): bool
    {
        return $this->stage >= 5;
    }
}
