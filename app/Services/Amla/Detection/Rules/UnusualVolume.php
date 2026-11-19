<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * C. Unusual transaction volume: one transaction of at least `min_amount` and
 * at least `multiplier` times the subject's own average transaction over the
 * `baseline_days` before its day. A subject with fewer than
 * `min_baseline_transactions` earlier transactions has no pattern to compare
 * with, so no alert is raised on volume alone.
 */
final class UnusualVolume extends DetectionRule
{
    public function code(): string
    {
        return 'C';
    }

    public function words(): string
    {
        return 'Unusual transaction volume';
    }

    public function description(): string
    {
        return 'One transaction several times the subject\'s own average transaction.';
    }

    public function parameters(): array
    {
        return ['min_amount' => 'peso', 'multiplier' => 'multiplier', 'baseline_days' => 'days', 'min_baseline_transactions' => 'count'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        $amount = self::cents($trigger);

        if (! $this->counts($trigger) || $amount < $context->centavos('min_amount')) {
            return null;
        }

        $day = self::day($trigger);
        $days = $context->int('baseline_days');
        $before = $this->subjectEvents($trigger)->where('occurred_on', '>=', $day->subDays($days)->toDateString())->where('occurred_on', '<', $day->toDateString())->get();
        $sum = self::total($before);

        if ($before->count() < $context->int('min_baseline_transactions') || $amount * $before->count() < $context->int('multiplier') * $sum) {
            return null;
        }

        $average = intdiv($sum, max(1, $before->count()));

        return new Finding(
            key: 'event:'.$trigger->id,
            windowStart: $day->toDateString(),
            windowEnd: $day->toDateString(),
            eventIds: [(int) $trigger->id],
            reason: sprintf('A transaction of %s on %s, against an average of %s over the %d transactions in the %d days before.',
                self::peso($amount), self::dateWords($day), self::peso($average), $before->count(), $days),
            indicators: [
                self::indicator('Transaction', self::peso($amount)),
                self::indicator('Earlier average', self::peso($average)),
                self::indicator('Earlier transactions', (string) $before->count()),
            ],
        );
    }
}
