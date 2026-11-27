<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * E. Rapid movement of funds: money in of at least `min_in_amount`, of which
 * at least `out_share_pct` percent leaves again as money out within
 * `within_days`. Triggered by the money out.
 */
final class RapidMovement extends DetectionRule
{
    public function code(): string
    {
        return 'E';
    }

    public function words(): string
    {
        return 'Rapid movement of funds';
    }

    public function description(): string
    {
        return 'A large receipt that mostly leaves again within a few days.';
    }

    public function parameters(): array
    {
        return ['min_in_amount' => 'peso', 'out_share_pct' => 'percent', 'within_days' => 'days'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        return $this->evaluateAll($trigger, $context)[0] ?? null;
    }

    /** One finding per receipt in reach that the money out still matches. */
    public function evaluateAll(AmlaEvent $trigger, DetectionContext $context): array
    {
        if (! $this->counts($trigger) || $trigger->direction !== 'out') {
            return [];
        }

        $findings = [];

        $within = $context->int('within_days');
        $day = self::day($trigger);
        $receipts = $this->subjectEvents($trigger)->where('direction', 'in')
            ->whereBetween('occurred_on', [$day->subDays($within)->toDateString(), $day->toDateString()])
            ->orderBy('occurred_on')->orderBy('id')->get()
            ->filter(fn (AmlaEvent $event) => self::cents($event) >= $context->centavos('min_in_amount'));

        foreach ($receipts as $receipt) {
            $from = self::day($receipt);
            $outs = $this->subjectEvents($trigger)->where('direction', 'out')
                ->whereBetween('occurred_on', [$from->toDateString(), $from->addDays($within)->toDateString()])->orderBy('id')->get();
            $out = self::total($outs);

            if ($out * 100 < $context->int('out_share_pct') * self::cents($receipt)) {
                continue;
            }

            $findings[] = new Finding(
                key: 'receipt:'.$receipt->id,
                windowStart: $from->toDateString(),
                windowEnd: $from->addDays($within)->toDateString(),
                eventIds: [(int) $receipt->id, ...self::ids($outs)],
                reason: sprintf('%s came in on %s and %s (%d%% or more) went out within %d days.',
                    self::peso(self::cents($receipt)), self::dateWords($from), self::peso($out), $context->int('out_share_pct'), $within),
                indicators: [
                    self::indicator('Money in', self::peso(self::cents($receipt))),
                    self::indicator('Money out within '.$within.' days', self::peso($out)),
                ],
                countedCentavos: self::cents($receipt),
                countedTransactions: 1,
            );
        }

        return $findings;
    }
}
