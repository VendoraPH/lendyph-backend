<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * F. Repeated deposits and withdrawals: in one calendar week, at least
 * `min_deposits` savings deposits and `min_withdrawals` savings withdrawals
 * or closing payouts, together at least `min_total`.
 */
final class RepeatedDepositsWithdrawals extends DetectionRule
{
    public const DEPOSITS = ['savings_deposit'];

    public const WITHDRAWALS = ['savings_withdrawal', 'savings_closing'];

    public function code(): string
    {
        return 'F';
    }

    public function words(): string
    {
        return 'Repeated deposits and withdrawals';
    }

    public function description(): string
    {
        return 'Several savings deposits and withdrawals in the same week.';
    }

    public function parameters(): array
    {
        return ['min_deposits' => 'count', 'min_withdrawals' => 'count', 'min_total' => 'peso'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        if (! $this->counts($trigger) || ! in_array($trigger->kind, [...self::DEPOSITS, ...self::WITHDRAWALS], true)) {
            return null;
        }

        $start = self::weekStart($trigger);
        $end = $start->addDays(6);
        $events = $this->subjectEvents($trigger)->whereIn('kind', [...self::DEPOSITS, ...self::WITHDRAWALS])
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get();
        $deposits = $events->whereIn('kind', self::DEPOSITS)->count();
        $withdrawals = $events->whereIn('kind', self::WITHDRAWALS)->count();
        $total = self::total($events);

        if ($deposits < $context->int('min_deposits') || $withdrawals < $context->int('min_withdrawals') || $total < $context->centavos('min_total')) {
            return null;
        }

        return new Finding(
            key: $this->subjectKey($trigger).'|'.$start->toDateString(),
            windowStart: $start->toDateString(),
            windowEnd: $end->toDateString(),
            eventIds: self::ids($events),
            reason: sprintf('%d savings deposits and %d withdrawals in the week of %s, totalling %s.', $deposits, $withdrawals, self::dateWords($start), self::peso($total)),
            indicators: [
                self::indicator('Deposits', (string) $deposits),
                self::indicator('Withdrawals', (string) $withdrawals),
                self::indicator('Total', self::peso($total)),
            ],
            countedCentavos: $total,
            countedTransactions: $events->count(),
        );
    }
}
