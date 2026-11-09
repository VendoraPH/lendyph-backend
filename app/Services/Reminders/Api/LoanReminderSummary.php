<?php

namespace App\Services\Reminders\Api;

use App\Http\Resources\Reminders\ReminderPauseResource;
use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\ReminderEvent;
use App\Models\ReminderPause;
use App\Models\ReminderQueueItem;
use App\Models\ReminderRule;
use App\Models\ReminderTemplate;
use App\Services\ContactVisibility;
use App\Services\Reminders\FireDate;
use App\Services\Reminders\ReminderClock;
use App\Services\Reminders\ReminderEligibility;
use Illuminate\Http\Request;

/**
 * One loan's reminders card (frontend `LoanReminderSummary`): its next open
 * period and what the loan engine says is due by then, the next reminder,
 * the pauses that stop it, the template today's rules would pick, the
 * templates a manual send may use (TemplateFit), and the reminder timeline,
 * oldest first. Contact details follow ContactVisibility.
 */
final class LoanReminderSummary
{
    private const EVENT_LIMIT = 200;

    public function __construct(
        private readonly ReminderEligibility $eligibility,
        private readonly ContactVisibility $visibility,
        private readonly TemplateFit $fit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Loan $loan, Request $request): array
    {
        $loan->loadMissing(['borrower', 'branch', 'amortizationSchedules']);
        $period = self::nextOpenPeriod($loan);
        $now = now();

        $nextReminder = ReminderQueueItem::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', ReminderQueueItem::OPEN_STATUSES)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->first(['id', 'scheduled_at']);

        $pause = ReminderPause::query()->inForce()->where('scope', 'loan')->where('loan_id', $loan->id)
            ->with(ReminderPauseResource::RELATIONS)->latest('paused_at')->first();
        $inherited = ReminderPause::query()->inForce()
            ->where(fn ($scope) => $scope->where(fn ($member) => $member->where('scope', 'borrower')->where('borrower_id', $loan->borrower_id))->orWhere('scope', 'global'))
            ->with(ReminderPauseResource::RELATIONS)->get()
            ->sortBy(fn (ReminderPause $p) => [$p->scope === 'borrower' ? 0 : 1, -$p->paused_at->getTimestamp()])
            ->first();

        return [
            'loan_id' => $loan->id,
            'next_due_date' => ReminderFormat::date($period?->due_date),
            'next_amount_due' => $period === null ? null : $this->eligibility->amountDue($loan, $period, $now),
            'next_reminder_at' => ReminderFormat::time($nextReminder?->scheduled_at),
            'pause' => $pause === null ? null : (new ReminderPauseResource($pause))->resolve($request),
            'inherited_pause' => $inherited === null ? null : (new ReminderPauseResource($inherited))->resolve($request),
            'suggested_template_type' => $period === null ? null : $this->suggestedTemplate($loan, $period),
            'allowed_template_types' => $this->fit->allowed($loan, $now),
            'events' => $this->events($loan, $this->visibility->allows($request->user(), $loan->borrower?->branch_id)),
        ];
    }

    /**
     * The earliest unpaid period of a collectible loan, or null.
     */
    public static function nextOpenPeriod(Loan $loan): ?AmortizationSchedule
    {
        if (! in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true)) {
            return null;
        }

        return $loan->amortizationSchedules
            ->filter(fn (AmortizationSchedule $s) => in_array($s->status, AmortizationSchedule::UNPAID_STATUSES, true))
            ->sortBy(fn (AmortizationSchedule $s) => [$s->due_date->toDateString(), (int) $s->period_number])
            ->first();
    }

    /**
     * The template of the active rule covering this loan whose fire date for
     * the period is the latest one on or before today (so the 15-day rule wins
     * over the 3-day one once it is reached), or null before any has fired.
     */
    private function suggestedTemplate(Loan $loan, AmortizationSchedule $period): ?string
    {
        $today = ReminderClock::today();

        $latest = ReminderRule::query()->active()->get()
            ->filter(fn (ReminderRule $rule) => $rule->appliesTo($loan->branch_id === null ? null : (int) $loan->branch_id, (int) $loan->loan_product_id, $loan->borrower?->status))
            ->map(fn (ReminderRule $rule) => ['rule' => $rule, 'fires' => FireDate::for($rule, $period->due_date, $loan->grace_period_days)])
            ->filter(fn (array $candidate) => $candidate['fires']->lte($today))
            ->sortBy(fn (array $candidate) => [$candidate['fires']->toDateString(), $candidate['rule']->offsetDays()])
            ->last();

        return $latest === null ? null : $latest['rule']->template_type;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(Loan $loan, bool $contact): array
    {
        return ReminderEvent::query()
            ->where(fn ($scope) => $scope->where('loan_id', $loan->id)
                ->orWhere(fn ($member) => $member->whereNull('loan_id')->where('borrower_id', $loan->borrower_id)->whereIn('kind', ['paused', 'resumed'])))
            ->with(['queueItem:id,source,template_type', 'message:id,test_mode'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::EVENT_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (ReminderEvent $event) => $this->event($event, $contact))
            ->values()
            ->all();
    }

    /**
     * The event's detail as the caller may read it. Without the member's
     * details, a hand-over's detail (it names the masked recipient) and a
     * member pause's text read null, and a skip for it is cut to its words.
     */
    private function detail(ReminderEvent $event, string $kind, bool $contact): ?string
    {
        if ($contact) {
            return $event->detail;
        }

        if (in_array($kind, ['sent', 'manual_sent', 'delivered'], true)
            || (in_array($kind, ['paused', 'resumed'], true) && $event->loan_id === null)) {
            return null;
        }

        return $this->visibility->reason($event->detail, false, $kind === 'failed' ? (string) $event->reason_code : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(ReminderEvent $event, bool $contact): array
    {
        $manual = $event->queueItem?->source === 'manual';
        $kind = match (true) {
            $event->kind === 'sent' && $manual => 'manual_sent',
            $event->kind === 'cancelled' && $event->reason_code === 'paid' => 'payment_received',
            default => $event->kind,
        };
        $testMode = in_array($kind, ['sent', 'manual_sent', 'delivered'], true) ? $event->message?->test_mode : null;
        $what = $manual ? 'Manual reminder' : ReminderTemplate::typeLabel($event->queueItem?->template_type).' reminder';
        $channel = $event->channel === null ? '' : ' — '.($event->channel === 'email' ? 'Email' : 'SMS');

        $title = match ($kind) {
            'scheduled' => "{$what} scheduled",
            'sent', 'manual_sent' => $testMode === false ? "{$what} sent" : "{$what} logged in test mode",
            'delivered' => "{$what} delivered",
            'failed' => "{$what} failed",
            'payment_received' => "Payment received; {$what} cancelled",
            'cancelled' => "{$what} cancelled",
            'skipped' => "{$what} skipped",
            'paused' => 'Reminders paused',
            'resumed' => 'Reminders resumed',
            default => ucfirst(str_replace('_', ' ', (string) $kind)),
        };

        return [
            'id' => 'event-'.$event->id,
            'kind' => $kind,
            'at' => ReminderFormat::time($event->created_at),
            'title' => $title.$channel,
            'detail' => $this->detail($event, $kind, $contact),
            'channel' => $event->channel,
            'installment_number' => $event->period_number === null ? null : (int) $event->period_number,
            'test_mode' => $testMode === null ? null : (bool) $testMode,
        ];
    }
}
