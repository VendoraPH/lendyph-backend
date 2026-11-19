<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * A. Structuring (task §7–8): on one day, one subject's transactions in the
 * rule's channels, each at or below the CTR threshold, that together exceed
 * it. A review alert, never a CTR: a same-day sum is never a covered
 * transaction. Money in and money out are counted apart.
 */
final class Structuring extends DetectionRule
{
    public function code(): string
    {
        return 'A';
    }

    public function words(): string
    {
        return 'Transaction structuring';
    }

    public function description(): string
    {
        return 'On one day, several transactions in the listed channels, each at or below the CTR threshold, that together exceed it.';
    }

    public function parameters(): array
    {
        return ['min_transactions' => 'count', 'channels' => 'channels'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        $threshold = $context->thresholdCentavos();
        $channels = $context->channels();

        if (! $this->counts($trigger) || ! in_array($trigger->channel, $channels, true) || self::cents($trigger) > $threshold) {
            return null;
        }

        $day = self::day($trigger);
        $events = $this->subjectEvents($trigger)
            ->whereDate('occurred_on', $day->toDateString())
            ->where('direction', $trigger->direction)
            ->whereIn('channel', $channels)
            ->orderBy('id')
            ->get()
            ->filter(fn (AmlaEvent $event) => self::cents($event) <= $threshold)
            ->values();
        $total = self::total($events);

        if ($events->count() < $context->int('min_transactions') || $total <= $threshold) {
            return null;
        }

        $way = $trigger->direction === 'in' ? 'money in' : 'money out';

        return new Finding(
            key: $this->subjectKey($trigger).'|'.$day->toDateString().'|'.$trigger->direction,
            windowStart: $day->toDateString(),
            windowEnd: $day->toDateString(),
            eventIds: self::ids($events),
            reason: sprintf('%d %s transactions (%s) on %s total %s, above the CTR threshold of %s, while each is at or below it.',
                $events->count(), strtolower(self::channelWords($channels)), $way, self::dateWords($day), self::peso($total), self::peso($threshold)),
            indicators: [
                self::indicator('Transactions', (string) $events->count()),
                self::indicator('Total', self::peso($total)),
                self::indicator('Largest', self::peso((int) $events->max(fn (AmlaEvent $event) => self::cents($event)))),
                self::indicator('CTR threshold', self::peso($threshold).' (AMLA settings version '.$context->settingsVersion.')'),
            ],
        );
    }
}
