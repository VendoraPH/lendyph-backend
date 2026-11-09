<?php

namespace App\Services\Reminders\Api;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use App\Models\Repayment;
use App\Services\Reminders\FireDate;
use App\Services\Reminders\ReminderClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Which templates a manual reminder may use on a loan today, so a member is
 * never sent words that don't fit their loan (Arvin, 2026-11-09). By the
 * loan's next open period, Manila days:
 *
 * - `upcoming` before its due date, `due_today` on it;
 * - `past_due` once grace has ended, and `long_past_due` instead once the
 *   long-past-due rule's day is reached (the earliest active one covering the
 *   loan, else 15 days past due);
 * - `partial_payment` while that period is part paid;
 * - `payment_confirmation` within 7 days of a posted payment.
 *
 * Inside grace, nothing overdue fits. The list keeps ReminderTemplate::TYPES'
 * order.
 */
final class TemplateFit
{
    private const LONG_PAST_DUE_DAYS = 15;

    private const CONFIRMATION_DAYS = 7;

    /**
     * @return list<string>
     */
    public function allowed(Loan $loan, CarbonInterface $now): array
    {
        $loan->loadMissing(['borrower', 'amortizationSchedules']);
        $today = ReminderClock::today($now);
        $period = LoanReminderSummary::nextOpenPeriod($loan);
        $fits = [];

        if ($period !== null) {
            $due = Carbon::parse($period->due_date->toDateString(), ReminderSetting::TIMEZONE)->startOfDay();
            $daysPastDue = (int) $due->diffInDays($today, false);
            $grace = max(0, (int) $loan->grace_period_days);

            if ($daysPastDue < 0) {
                $fits[] = 'upcoming';
            } elseif ($daysPastDue === 0) {
                $fits[] = 'due_today';
            } elseif ($daysPastDue > $grace) {
                $fits[] = $today->gte($this->longPastDueFrom($loan, $period)) ? 'long_past_due' : 'past_due';
            }

            if ($period->status === 'partial' || (float) $period->principal_paid > 0 || (float) $period->interest_paid > 0) {
                $fits[] = 'partial_payment';
            }
        }

        $recentPayment = Repayment::query()
            ->where('loan_id', $loan->id)
            ->where('status', 'posted')
            ->whereBetween('payment_date', [$today->copy()->subDays(self::CONFIRMATION_DAYS)->toDateString(), $today->toDateString()])
            ->exists();

        if ($recentPayment) {
            $fits[] = 'payment_confirmation';
        }

        return array_values(array_filter(ReminderTemplate::TYPES, fn (string $type) => in_array($type, $fits, true)));
    }

    private function longPastDueFrom(Loan $loan, AmortizationSchedule $period): Carbon
    {
        $fireDates = ReminderRule::query()->active()
            ->where('trigger', 'after_due')
            ->where('template_type', 'long_past_due')
            ->get()
            ->filter(fn (ReminderRule $rule) => $rule->appliesTo($loan->branch_id === null ? null : (int) $loan->branch_id, (int) $loan->loan_product_id, $loan->borrower?->status))
            ->map(fn (ReminderRule $rule) => FireDate::for($rule, $period->due_date, $loan->grace_period_days));

        return $fireDates->isEmpty()
            ? Carbon::parse($period->due_date->toDateString(), ReminderSetting::TIMEZONE)->startOfDay()->addDays(self::LONG_PAST_DUE_DAYS)
            : $fireDates->sortBy(fn (Carbon $date) => $date->getTimestamp())->first();
    }
}
