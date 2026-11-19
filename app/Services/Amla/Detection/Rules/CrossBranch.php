<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * J. Cross branch transaction patterns: one subject's customer transactions
 * in at least `min_branches` branches in one calendar week. The alert spans
 * branches, so it is head office's (no branch).
 */
final class CrossBranch extends DetectionRule
{
    public function code(): string
    {
        return 'J';
    }

    public function words(): string
    {
        return 'Cross branch transaction patterns';
    }

    public function description(): string
    {
        return 'One subject transacting in several branches in the same week. Seen by head office only.';
    }

    public function parameters(): array
    {
        return ['min_branches' => 'count'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        if (! $this->counts($trigger) || $trigger->branch_id === null) {
            return null;
        }

        $start = self::weekStart($trigger);
        $end = $start->addDays(6);
        $events = $this->subjectEvents($trigger)->whereNotNull('branch_id')
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get();
        $branches = $events->pluck('branch_id')->unique()->count();

        if ($branches < $context->int('min_branches')) {
            return null;
        }

        return new Finding(
            key: $this->subjectKey($trigger).'|'.$start->toDateString(),
            windowStart: $start->toDateString(),
            windowEnd: $end->toDateString(),
            eventIds: self::ids($events),
            reason: sprintf('Transactions in %d branches in the week of %s.', $branches, self::dateWords($start)),
            indicators: [
                self::indicator('Branches', (string) $branches),
                self::indicator('Transactions', (string) $events->count()),
            ],
            headOffice: true,
        );
    }
}
