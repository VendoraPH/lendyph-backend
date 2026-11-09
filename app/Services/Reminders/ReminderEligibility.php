<?php

namespace App\Services\Reminders;

use App\Models\AmortizationSchedule;
use App\Models\BorrowerNotificationPreference;
use App\Models\Loan;
use App\Models\ReminderPause;
use App\Models\ReminderQueueItem;
use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use App\Services\RepaymentService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Whether one reminder may go now, in the task's §11 order, read from the live
 * loan every time (the dispatcher asks right before the driver call):
 *
 * 1. the loan is collectible;
 * 2. the period with the same number and due date is still open;
 * 3. the loan engine says something is due (RepaymentService::amountDueNow(),
 *    read only);
 * 4. automated reminders, the rule and the channel are on, and Auto-Pay does
 *    not collect this one;
 * 5. no pause applies;
 * 6. the member has not turned the channel off;
 * 7. the member's own mobile number or email is valid, and on the allow-list;
 * 8. it is the fire date, inside contact hours.
 *
 * 1 to 3 failing cancel the reminder (no longer needed); 4 to 8 skip it
 * (still needed, not sendable), except a rule that no longer covers the loan,
 * which cancels.
 */
final class ReminderEligibility
{
    public function __construct(
        private readonly RepaymentService $repayments,
        private readonly MemberContact $contacts,
        private readonly AllowList $allowList,
    ) {}

    public function check(ReminderQueueItem $item, ?Loan $loan, ?ReminderRule $rule, ReminderSetting $settings, ContactHours $hours, CarbonInterface $now): EligibilityDecision
    {
        if ($loan === null || ! in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true)) {
            return EligibilityDecision::cancel(0, 'loan_closed', self::closedReason($loan?->status));
        }

        $schedules = $loan->amortizationSchedules;
        $period = $schedules->first(fn (AmortizationSchedule $s) => (int) $s->period_number === (int) $item->period_number
            && $s->due_date->toDateString() === $item->due_date->toDateString());

        if ($period === null || ! in_array($period->status, AmortizationSchedule::UNPAID_STATUSES, true)) {
            return $period !== null && $period->status === 'paid' && ! $period->isClosedEarly()
                ? EligibilityDecision::cancel(1, 'paid', 'Payment received before send time')
                : EligibilityDecision::cancel(1, 'schedule_changed', 'Schedule changed: the due date moved or the period closed');
        }

        $amount = $this->amountDue($loan, $period, $now);

        if ($amount <= 0) {
            return EligibilityDecision::cancel(2, 'nothing_due', 'Nothing is due on this loan');
        }

        $isSystem = $item->source === 'system';

        if ($isSystem) {
            if ($rule === null || ! $rule->is_active) {
                return EligibilityDecision::skip(3, 'rule_inactive', 'Reminder rule is switched off', $amount);
            }

            if (! $rule->appliesTo($loan->branch_id === null ? null : (int) $loan->branch_id, (int) $loan->loan_product_id, $loan->borrower?->status)) {
                return EligibilityDecision::cancel(3, 'rule_not_applicable', 'The rule no longer covers this loan', $amount);
            }

            if (! $settings->enabled) {
                return EligibilityDecision::skip(3, 'reminders_off', 'Automated reminders are switched off', $amount);
            }

            if (! in_array($item->channel, (array) $rule->channels, true)) {
                return EligibilityDecision::skip(3, 'channel_off', 'The rule no longer sends '.self::channelLabel($item->channel), $amount);
            }
        }

        if (! $settings->channelEnabled($item->channel)) {
            return EligibilityDecision::skip(3, 'channel_off', self::channelLabel($item->channel).' reminders are switched off', $amount);
        }

        if ($isSystem && $loan->auto_pay && $rule->isBeforeOrOnDue()) {
            return EligibilityDecision::skip(3, 'auto_pay', 'Collected by Auto-Pay', $amount);
        }

        $pause = ReminderPause::applyingTo((int) $loan->id, (int) $loan->borrower_id, Carbon::instance($now));

        if ($pause !== null) {
            return EligibilityDecision::skip(4, 'paused', self::pauseReason($pause), $amount);
        }

        $preference = BorrowerNotificationPreference::query()->where('borrower_id', $loan->borrower_id)->first();

        if ($preference !== null && ! $preference->channelEnabled($item->channel)) {
            return EligibilityDecision::skip(5, 'opted_out', 'Member turned off '.self::channelLabel($item->channel).' reminders', $amount);
        }

        $contact = $loan->borrower === null
            ? ContactCheck::unusable('no_contact', 'Member not found')
            : $this->contacts->for($loan->borrower, $item->channel, $preference);

        if (! $contact->isUsable()) {
            return EligibilityDecision::skip(6, (string) $contact->failureCode, (string) $contact->failureReason, $amount, $contact->masked);
        }

        if (! $this->allowList->permits($item->channel, (string) $contact->recipient)) {
            return EligibilityDecision::skip(6, 'not_allowed', 'Recipient is not on the allow-list', $amount, $contact->masked);
        }

        $today = ReminderClock::today($now);
        $fireDate = ReminderClock::manila($item->fire_date)->startOfDay();

        if ($today->lt($fireDate)) {
            return EligibilityDecision::wait(ReminderClock::toApp($hours->openingOn($fireDate)), $amount);
        }

        if ($today->gt($fireDate)) {
            return EligibilityDecision::skip(7, 'fire_date_passed', 'Its day has passed', $amount, $contact->masked);
        }

        if ($hours->isBeforeOpening($now)) {
            return EligibilityDecision::wait(ReminderClock::toApp($hours->openingOn($today)), $amount);
        }

        if (! $hours->contains($now)) {
            return EligibilityDecision::skip(7, 'outside_contact_hours', 'Outside contact hours', $amount, $contact->masked);
        }

        return EligibilityDecision::eligible($amount, $period, (string) $contact->recipient, (string) $contact->masked, $preference);
    }

    /**
     * What the loan engine says is due by this period's due date, or today if
     * that is later: every open period due by then, with penalty, premium and
     * build-up as a payment would take them (RepaymentService::amountDueNow()).
     */
    public function amountDue(Loan $loan, AmortizationSchedule $period, CarbonInterface $now): float
    {
        $today = ReminderClock::today($now);
        $due = Carbon::parse($period->due_date->toDateString(), $today->getTimezone());
        $asOf = ReminderClock::toApp($due->gt($today) ? $due : $today);

        return (float) $this->repayments->amountDueNow($loan, $loan->amortizationSchedules, $asOf)['amount'];
    }

    public static function closedReason(?string $status): string
    {
        return match ($status) {
            'completed' => 'Loan is fully paid',
            'restructured' => 'Loan was restructured',
            null => 'Loan no longer exists',
            default => 'Loan is no longer collectible',
        };
    }

    public static function channelLabel(string $channel): string
    {
        return $channel === 'email' ? 'email' : 'SMS';
    }

    private static function pauseReason(ReminderPause $pause): string
    {
        $where = match ($pause->scope) {
            'loan' => 'Reminders are paused for this loan',
            'borrower' => 'Reminders are paused for this member',
            default => 'All reminders are paused',
        };

        return mb_substr($where.': '.$pause->reason, 0, 255);
    }
}
