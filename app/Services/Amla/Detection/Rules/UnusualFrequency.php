<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * B. Unusual transaction frequency: in one calendar week, at least
 * `min_transactions` customer transactions, and at least `multiplier` times
 * the subject's own weekly average over the `baseline_days` before it. With no
 * earlier activity the pattern reads "Data not available" and the count alone
 * decides.
 */
final class UnusualFrequency extends DetectionRule
{
    public function code(): string
    {
        return 'B';
    }

    public function words(): string
    {
        return 'Unusual transaction frequency';
    }

    public function description(): string
    {
        return 'Many transactions in one week, several times the subject\'s own weekly average before it.';
    }

    public function parameters(): array
    {
        return ['min_transactions' => 'count', 'multiplier' => 'multiplier', 'baseline_days' => 'days'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        if (! $this->counts($trigger)) {
            return null;
        }

        $start = self::weekStart($trigger);
        $end = $start->addDays(6);
        $events = $this->subjectEvents($trigger)->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get();
        $days = $context->int('baseline_days');
        $before = $this->subjectEvents($trigger)->where('occurred_on', '>=', $start->subDays($days)->toDateString())->where('occurred_on', '<', $start->toDateString())->count();

        // count ≥ multiplier × (before × 7 ÷ days), in whole numbers.
        if ($events->count() < $context->int('min_transactions') || $events->count() * $days < $context->int('multiplier') * $before * 7) {
            return null;
        }

        $average = $before === 0 ? null : number_format($before * 7 / $days, 1).' a week';

        return new Finding(
            key: $this->subjectKey($trigger).'|'.$start->toDateString(),
            windowStart: $start->toDateString(),
            windowEnd: $end->toDateString(),
            eventIds: self::ids($events),
            reason: sprintf('%d transactions in the week of %s, against an earlier average of %s.', $events->count(), self::dateWords($start), $average ?? 'none on record'),
            indicators: [
                self::indicator('Transactions this week', (string) $events->count()),
                self::indicator('Earlier weekly average ('.$days.' days)', $average),
            ],
        );
    }
}
