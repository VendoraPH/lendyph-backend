<?php

namespace App\Services\Reminders\Drivers;

use Illuminate\Contracts\Foundation\Application;

/**
 * The driver for a channel, from `reminders.drivers.<channel>`: `log`, or
 * `fake` in the testing environment only. Any other name, `fake` outside
 * tests included, gets UnavailableDriver.
 */
final class DriverResolver
{
    public function __construct(private readonly Application $container) {}

    public function for(string $channel): ReminderDriver
    {
        $name = (string) config("reminders.drivers.{$channel}", 'log');

        return match ($name) {
            'log' => new LogDriver,
            'fake' => $this->container->environment('testing') ? $this->container->make(FakeDriver::class) : new UnavailableDriver($name),
            default => new UnavailableDriver($name),
        };
    }
}
