<?php

namespace App\Services\Reminders\Drivers;

/**
 * Hands one reminder to whatever delivers its channel. The reminders engine
 * talks only to this interface, so a provider added later (after the owner
 * signs) changes nothing else. None exists today: the drivers are LogDriver,
 * FakeDriver (tests) and UnavailableDriver.
 */
interface ReminderDriver
{
    /**
     * The name stored on the message as its provider.
     */
    public function name(): string;

    /**
     * True for a driver that reaches nobody, so its messages are test messages.
     */
    public function reachesNobody(): bool;

    public function send(OutgoingReminder $reminder): DriverResult;

    /**
     * SMS credits left, or null when the driver does not know.
     */
    public function creditsRemaining(): ?int;
}
