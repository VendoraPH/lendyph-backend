<?php

namespace App\Services\Linda;

use App\Models\LindaQuestion;
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
 * with two decimals, half up. Calls already in flight when the cap is reached
 * can pass it by a few centavos (flagged in the plan).
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

    public function cap(): BigDecimal
    {
        return BigDecimal::of((string) config('linda.monthly_cap'))->toScale(2, RoundingMode::HalfUp);
    }

    /** Whether this month's model cost has reached the cap. */
    public function reached(): bool
    {
        return $this->monthToDate()->isGreaterThanOrEqualTo($this->cap());
    }

    /**
     * This month's figures for Settings → Linda, every one from the log.
     *
     * @return array{label: string, cost: string, cap: string, questions: int, model_questions: int}
     */
    public function summary(): array
    {
        [$from, $to] = $this->month();
        $questions = LindaQuestion::query()->where('asked_at', '>=', $from)->where('asked_at', '<', $to);

        return [
            'label' => $from->format('F Y'),
            'cost' => (string) $this->monthToDate()->toScale(2, RoundingMode::HalfUp),
            'cap' => (string) $this->cap(),
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
