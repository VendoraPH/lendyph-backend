<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * I. Loan related anomalies: a loan payment in the rule's channels of at
 * least `min_amount` and at least `min_share_pct` percent of a release to the
 * same member within the `within_days` before it. An early repayment is not
 * suspicious by itself; this is a low-priority review alert.
 */
final class LoanAnomalies extends DetectionRule
{
    public function code(): string
    {
        return 'I';
    }

    public function words(): string
    {
        return 'Loan related anomalies';
    }

    public function description(): string
    {
        return 'A large cash payment soon after the member\'s own loan release. An early repayment alone is not suspicious.';
    }

    public function parameters(): array
    {
        return ['min_amount' => 'peso', 'min_share_pct' => 'percent', 'within_days' => 'days', 'channels' => 'channels'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        return $this->evaluateAll($trigger, $context)[0] ?? null;
    }

    /** One finding per release in reach that the payment still matches. */
    public function evaluateAll(AmlaEvent $trigger, DetectionContext $context): array
    {
        if (! $this->counts($trigger) || $trigger->kind !== 'loan_payment' || ! in_array($trigger->channel, $context->channels(), true)) {
            return [];
        }

        $findings = [];

        $within = $context->int('within_days');
        $day = self::day($trigger);
        $releases = $this->subjectEvents($trigger)->where('kind', 'loan_release')
            ->whereBetween('occurred_on', [$day->subDays($within)->toDateString(), $day->toDateString()])
            ->orderByDesc('occurred_on')->orderByDesc('id')->get();

        foreach ($releases as $release) {
            $qualifies = fn (AmlaEvent $payment) => self::cents($payment) >= $context->centavos('min_amount')
                && self::cents($payment) * 100 >= $context->int('min_share_pct') * self::cents($release);

            if (! $qualifies($trigger)) {
                continue;
            }

            $from = self::day($release);
            $payments = $this->subjectEvents($trigger)->where('kind', 'loan_payment')->whereIn('channel', $context->channels())
                ->whereBetween('occurred_on', [$from->toDateString(), $from->addDays($within)->toDateString()])->orderBy('id')->get()
                ->filter($qualifies)->values();

            $findings[] = new Finding(
                key: 'release:'.$release->id,
                windowStart: $from->toDateString(),
                windowEnd: $from->addDays($within)->toDateString(),
                eventIds: [(int) $release->id, ...self::ids($payments)],
                reason: sprintf('A %s payment of %s on %s, %d%% or more of the %s released on %s. An early repayment alone is not suspicious.',
                    strtolower(self::channelWords([(string) $trigger->channel])), self::peso(self::cents($trigger)), self::dateWords($day),
                    $context->int('min_share_pct'), self::peso(self::cents($release)), self::dateWords($from)),
                indicators: [
                    self::indicator('Released', self::peso(self::cents($release))),
                    self::indicator('Paid', self::peso(self::cents($trigger))),
                    self::indicator('Days after release', (string) $from->diffInDays($day)),
                    self::indicator('Payer', null),
                ],
            );
        }

        return $findings;
    }
}
