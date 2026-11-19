<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * H. GCash anomalies: on one day, at least `min_transactions` GCash counter
 * transactions for one subject, or at least `min_total` across them. Only
 * what Lendy records about GCash is read; no wallet history is assumed.
 */
final class GCashAnomalies extends DetectionRule
{
    public function code(): string
    {
        return 'H';
    }

    public function words(): string
    {
        return 'GCash and digital payment anomalies';
    }

    public function description(): string
    {
        return 'Many GCash transactions, or a large GCash total, for one subject in one day.';
    }

    public function parameters(): array
    {
        return ['min_transactions' => 'count', 'min_total' => 'peso'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        if (! $this->counts($trigger) || $trigger->source !== 'gcash') {
            return null;
        }

        $day = self::day($trigger);
        $events = $this->subjectEvents($trigger)->where('source', 'gcash')->whereDate('occurred_on', $day->toDateString())->orderBy('id')->get();
        $total = self::total($events);

        if ($events->count() < $context->int('min_transactions') && $total < $context->centavos('min_total')) {
            return null;
        }

        return new Finding(
            key: $this->subjectKey($trigger).'|'.$day->toDateString(),
            windowStart: $day->toDateString(),
            windowEnd: $day->toDateString(),
            eventIds: self::ids($events),
            reason: sprintf('%d GCash transactions on %s, totalling %s.', $events->count(), self::dateWords($day), self::peso($total)),
            indicators: [
                self::indicator('GCash transactions', (string) $events->count()),
                self::indicator('Total', self::peso($total)),
                self::indicator('Wallet reference', null),
            ],
        );
    }
}
