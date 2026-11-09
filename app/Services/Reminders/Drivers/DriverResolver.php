<?php

namespace App\Services\Reminders\Drivers;

use Illuminate\Contracts\Container\Container;

/**
 * The driver for a channel, from `reminders.drivers.<channel>`: `log`, or
 * `fake` in tests. Any other name gets UnavailableDriver.
 */
final class DriverResolver
{
    public function __construct(private readonly Container $container) {}

    public function for(string $channel): ReminderDriver
    {
        $name = (string) config("reminders.drivers.{$channel}", 'log');

        return match ($name) {
            'log' => new LogDriver,
            'fake' => $this->container->make(FakeDriver::class),
            default => new UnavailableDriver($name),
        };
    }
}
