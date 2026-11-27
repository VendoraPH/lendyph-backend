<?php

namespace App\Services\Reminders\Api;

use App\Models\ReminderRule;
use App\Services\Reminders\Drivers\DriverResolver;

/**
 * Whether this deployment sends nothing (`test_mode`), by the dispatcher's own
 * rule for each message (ReminderDispatcher::stageMessage): live sending is
 * off, or a channel's driver reaches nobody. Never read from the provider
 * saved in the settings: saving a provider changes no driver.
 */
final class DeploymentMode
{
    public function __construct(private readonly DriverResolver $drivers) {}

    public function testMode(): bool
    {
        if (config('reminders.live_sending') !== true) {
            return true;
        }

        foreach (ReminderRule::CHANNELS as $channel) {
            if ($this->drivers->for($channel)->reachesNobody()) {
                return true;
            }
        }

        return false;
    }
}
