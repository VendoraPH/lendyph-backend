<?php

namespace App\Services\Linda;

use App\Models\LindaQuestion;
use App\Services\Linda\Model\LindaModelUnavailable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Linda's monthly cap (Linda v1 plan, "Limits and cost"): the model cost of
 * this Manila calendar month, summed from the question log, against
 * `linda.monthly_cap` (₱ per deployment). At the cap, model questions answer
 * `monthly_limit`; matcher answers cost nothing and carry on.
 *
 * Costs are exact decimals (6 places in the log); the figures shown are ₱
 * with two decimals, half up. The cap is checked before each call, so calls
 * already in flight when it is reached can pass it by up to one call's cost
 * each. A cap that isn't a plain amount fails closed (`no_prices`).
 */
final class LindaBudget
{
    /** The month-to-date model cost, exact (6 decimal places). */
    public function monthToDate(): BigDecimal
    {
        [$from, $to] = $this->month();

        $sum = LindaQuestion::query()->where('asked_at', '>=', $from)->where('asked_at', '<', $to)->sum('cost');

        return BigDecimal::of((string) ($sum ?: '0'))->toScale(6, RoundingMode::HalfUp);
    }

    /**
     * @throws LindaModelUnavailable no_prices, for a cap that isn't a plain amount
     */
    public function cap(): BigDecimal
    {
        $cap = trim((string) config('linda.monthly_cap'));

        if (preg_match('/^\d+(\.\d+)?$/', $cap) !== 1) {
            throw new LindaModelUnavailable('no_prices');
        }

        return BigDecimal::of($cap)->toScale(2, RoundingMode::HalfUp);
    }

    /** The cap, or null when it isn't a plain amount. */
    public function capOrNull(): ?BigDecimal
    {
        try {
            return $this->cap();
        } catch (LindaModelUnavailable) {
            return null;
        }
    }

    /**
     * Whether this month's model cost has reached the cap.
     *
     * @throws LindaModelUnavailable no_prices, for a cap that isn't a plain amount
     */
    public function reached(): bool
    {
        return $this->monthToDate()->isGreaterThanOrEqualTo($this->cap());
    }

    /**
     * Whether the cap is reached once $pending (₱, a cost this question has
     * run up but not saved yet, such as its classifier call) is counted: the
     * check before an open question's second call.
     */
    public function reachedWith(string $pending): bool
    {
        return $this->monthToDate()->plus(BigDecimal::of($pending))->isGreaterThanOrEqualTo($this->cap());
    }

    /**
     * This month's figures for Settings → Linda, every one from the log.
     *
     * @return array{label: string, cost: string, cap: ?string, questions: int, model_questions: int}
     */
    public function summary(): array
    {
        [$from, $to] = $this->month();
        $questions = LindaQuestion::query()->where('asked_at', '>=', $from)->where('asked_at', '<', $to);

        return [
            'label' => $from->format('F Y'),
            'cost' => (string) $this->monthToDate()->toScale(2, RoundingMode::HalfUp),
            'cap' => ($cap = $this->capOrNull()) === null ? null : (string) $cap,
            'questions' => (clone $questions)->count(),
            'model_questions' => (clone $questions)->where('source', LindaQuestion::SOURCE_MODEL)->count(),
        ];
    }

    /**
     * The current Manila calendar month, as [first day 00:00, next month's
     * first day 00:00). `asked_at` is stored in Manila time.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function month(): array
    {
        $from = LindaPeriod::today()->startOfMonth();

        return [$from, $from->addMonthNoOverflow()];
    }
}
