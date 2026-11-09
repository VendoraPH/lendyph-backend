<?php

namespace App\Services\Reminders\Drivers;

use Illuminate\Support\Facades\Log;

/**
 * Where any driver name other than `log` or `fake` lands: nothing is handed
 * over, the reminder is marked "Sending is not set up", and a critical error
 * is logged so whoever configured the name finds out.
 */
final class UnavailableDriver implements ReminderDriver
{
    public function __construct(private readonly string $configuredName) {}

    public function name(): string
    {
        return mb_substr($this->configuredName, 0, 32);
    }

    public function reachesNobody(): bool
    {
        return true;
    }

    public function send(OutgoingReminder $reminder): DriverResult
    {
        Log::critical('A reminder driver is configured that is not set up; nothing was sent.', [
            'driver' => $this->configuredName,
            'channel' => $reminder->channel,
            'queue_item_id' => $reminder->queueItemId,
        ]);

        return DriverResult::notSetUp();
    }

    public function creditsRemaining(): ?int
    {
        return null;
    }
}
