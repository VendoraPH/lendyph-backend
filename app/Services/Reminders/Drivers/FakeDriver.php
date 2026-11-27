<?php

namespace App\Services\Reminders\Drivers;

use Illuminate\Support\Collection;

/**
 * The tests' driver: records every hand-over and answers with scripted
 * results per channel, then "sent" once the script runs out. Reaches nobody.
 * Bound as an instance by the tests; DriverResolver picks it for the name
 * `fake`.
 */
final class FakeDriver implements ReminderDriver
{
    /**
     * @var list<OutgoingReminder>
     */
    public array $calls = [];

    /**
     * @var array<string, list<DriverResult>>
     */
    private array $script = [];

    public function name(): string
    {
        return 'fake';
    }

    public function reachesNobody(): bool
    {
        return true;
    }

    public function willReturn(string $channel, DriverResult ...$results): void
    {
        $this->script[$channel] = [...($this->script[$channel] ?? []), ...$results];
    }

    public function send(OutgoingReminder $reminder): DriverResult
    {
        $this->calls[] = $reminder;
        $this->script[$reminder->channel] ??= [];

        return array_shift($this->script[$reminder->channel]) ?? DriverResult::sent('fake-'.$reminder->messageId.'-'.count($this->calls));
    }

    /**
     * @return Collection<int, OutgoingReminder>
     */
    public function sentTo(string $channel): Collection
    {
        return collect($this->calls)->where('channel', $channel)->values();
    }

    public function creditsRemaining(): ?int
    {
        return null;
    }
}
