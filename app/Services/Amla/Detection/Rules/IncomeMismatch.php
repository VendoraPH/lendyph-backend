<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;
use App\Services\Amla\Events\PesoAmount;
use Illuminate\Support\Facades\DB;

/**
 * D. Income and transaction mismatch: a member's money in over one calendar
 * month above `multiplier` times their recorded monthly income, and at least
 * `min_amount`. A member with no recorded income has no profile to compare
 * with, so nothing is raised (the CDD worklist shows the gap). Walk-ins have
 * no income on record.
 */
final class IncomeMismatch extends DetectionRule
{
    public function code(): string
    {
        return 'D';
    }

    public function words(): string
    {
        return 'Income and transaction mismatch';
    }

    public function description(): string
    {
        return 'A member\'s money in over a month, several times their recorded monthly income.';
    }

    public function parameters(): array
    {
        return ['min_amount' => 'peso', 'multiplier' => 'multiplier'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        if (! $this->counts($trigger) || $trigger->borrower_id === null || $trigger->direction !== 'in') {
            return null;
        }

        $income = DB::table('borrowers')->where('id', $trigger->borrower_id)->value('monthly_income');
        $incomeCentavos = $income === null ? 0 : PesoAmount::centavos((string) $income);

        if ($incomeCentavos <= 0) {
            return null;
        }

        $start = self::day($trigger)->startOfMonth();
        $end = $start->endOfMonth();
        $events = $this->subjectEvents($trigger)->where('direction', 'in')
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get();
        $total = self::total($events);

        if ($total < $context->centavos('min_amount') || $total <= $context->int('multiplier') * $incomeCentavos) {
            return null;
        }

        return new Finding(
            key: $this->subjectKey($trigger).'|'.$start->format('Y-m'),
            windowStart: $start->toDateString(),
            windowEnd: $end->toDateString(),
            eventIds: self::ids($events),
            reason: sprintf('Money in of %s in %s, more than %d times the recorded monthly income of %s.',
                self::peso($total), $start->format('F Y'), $context->int('multiplier'), self::peso($incomeCentavos)),
            indicators: [
                self::indicator('Money in this month', self::peso($total)),
                self::indicator('Recorded monthly income', self::peso($incomeCentavos)),
            ],
            countedCentavos: $total,
            countedTransactions: $events->count(),
        );
    }
}
