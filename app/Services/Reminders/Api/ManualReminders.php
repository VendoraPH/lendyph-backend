<?php

namespace App\Services\Reminders\Api;

use App\Models\AmortizationSchedule;
use App\Models\BorrowerNotificationPreference;
use App\Models\Loan;
use App\Models\ReminderEvent;
use App\Models\ReminderMessage;
use App\Models\ReminderQueueItem;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ContactVisibility;
use App\Services\LoanWriteTransaction;
use App\Services\Reminders\ContactHours;
use App\Services\Reminders\EligibilityDecision;
use App\Services\Reminders\MemberContact;
use App\Services\Reminders\ReminderClock;
use App\Services\Reminders\ReminderDispatcher;
use App\Services\Reminders\ReminderEligibility;
use App\Services\Reminders\ReminderRenderer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A reminder staff send by hand. It only queues rows: `reminders:dispatch`
 * hands them to the driver (today, the log driver) like any other, after its
 * own final check. Nothing here calls a driver or the network.
 *
 * The preview builds an unsaved queue row per channel (source manual, the
 * loan's earliest open period, today's fire date) and runs the engine's
 * final check on it (ReminderEligibility::check). A cancel or skip reason
 * blocks the send; a wait for contact hours to open only says when it goes.
 * The day's slot (one message per loan, channel and day) and an open manual
 * row for the channel today also block it, and so does a template that
 * doesn't fit the loan today (TemplateFit; 422 on `template_type`). The send
 * repeats all of it under the loan row's lock. Recipients and a member
 * pause's text follow ContactVisibility.
 */
final class ManualReminders
{
    public function __construct(
        private readonly ReminderEligibility $eligibility,
        private readonly ReminderRenderer $renderer,
        private readonly MemberContact $contacts,
        private readonly DeploymentMode $mode,
        private readonly TemplateFit $fit,
        private readonly ContactVisibility $visibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Loan $loan, string $channel, string $templateType, User $user): array
    {
        $assessment = $this->assess($loan, $channel, $templateType, now());
        $contact = $this->visibility->allows($user, $loan->borrower?->branch_id === null ? null : (int) $loan->borrower->branch_id);

        return [
            'borrower_name' => (string) $loan->borrower?->full_name,
            'loan_account_number' => ReminderFormat::loanNumber($loan),
            'installment_number' => $assessment['period']?->period_number === null ? null : (int) $assessment['period']->period_number,
            'due_date' => ReminderFormat::date($assessment['period']?->due_date),
            'amount_due' => $assessment['amount'],
            'messages' => array_map(fn (array $one) => [
                'channel' => $one['channel'],
                'recipient' => $contact ? $one['recipient'] : null,
                'subject' => $one['subject'],
                'body' => $one['body'],
            ], $assessment['channels']),
            'blocked_reasons' => array_values(array_unique(array_map(fn (string $reason) => (string) $this->visibility->reason($reason, $contact), [...$assessment['unfit'], ...$assessment['blocked']]))),
            'notes' => $assessment['notes'],
            'allowed_template_types' => $assessment['allowed'],
            'test_mode' => $this->mode->testMode(),
        ];
    }

    /**
     * Queues the reminder, or refuses it (422 on `channel`) while anything
     * blocks it. Returns the queued rows.
     *
     * @return Collection<int, ReminderQueueItem>
     */
    public function send(int $loanId, string $channel, string $templateType, User $user): Collection
    {
        return LoanWriteTransaction::run(function () use ($loanId, $channel, $templateType, $user): Collection {
            Loan::query()->whereKey($loanId)->lockForUpdate()->firstOrFail(['id']);
            $loan = Loan::query()->with(['borrower', 'branch', 'amortizationSchedules'])->findOrFail($loanId);
            $now = now();
            $assessment = $this->assess($loan, $channel, $templateType, $now);

            if ($assessment['unfit'] !== [] || $assessment['blocked'] !== []) {
                $contact = $this->visibility->allows($user, $loan->borrower?->branch_id === null ? null : (int) $loan->borrower->branch_id);

                throw ValidationException::withMessages(array_filter([
                    'template_type' => $assessment['unfit'],
                    'channel' => array_values(array_unique(array_map(fn (string $reason) => (string) $this->visibility->reason($reason, $contact), $assessment['blocked']))),
                ]));
            }

            $period = $assessment['period'];
            $queued = new Collection;

            foreach ($assessment['channels'] as $one) {
                $item = ReminderQueueItem::query()->create([
                    'loan_id' => $loan->id,
                    'borrower_id' => $loan->borrower_id,
                    'branch_id' => $loan->branch_id,
                    'amortization_schedule_id' => $period->id,
                    'period_number' => (int) $period->period_number,
                    'due_date' => $period->due_date->toDateString(),
                    'rule_id' => null,
                    'channel' => $one['channel'],
                    'template_type' => $templateType,
                    'amount_due' => $assessment['amount'],
                    'fire_date' => ReminderClock::today($now)->toDateString(),
                    'scheduled_at' => $now,
                    'status' => 'queued',
                    'recipient_masked' => $one['recipient'],
                    'source' => 'manual',
                    'created_by' => $user->id,
                ]);
                ReminderEvent::forItem('scheduled', $item, 'Sent by hand by '.$user->full_name.' ('.ReminderEligibility::channelLabel($item->channel).')');
                $queued->push($item);
            }

            AuditLogService::log(
                action: 'reminder_manual_send',
                auditable: $loan,
                newValues: [
                    'channels' => $queued->pluck('channel')->all(),
                    'template_type' => $templateType,
                    'period_number' => (int) $period->period_number,
                    'queue_item_ids' => $queued->pluck('id')->all(),
                ],
                description: sprintf('Manual %s reminder queued for loan %s', ReminderTemplate::typeLabel($templateType), ReminderFormat::loanNumber($loan)),
            );

            return $queued;
        }, conflictMessage: 'Another change to this loan was saved at the same time. Reload and try again.');
    }

    /**
     * @return array{period: ?AmortizationSchedule, amount: ?float, channels: list<array{channel: string, recipient: ?string, subject: ?string, body: string}>, blocked: list<string>, unfit: list<string>, notes: list<string>, allowed: list<string>}
     */
    private function assess(Loan $loan, string $channelChoice, string $templateType, Carbon $now): array
    {
        $loan->loadMissing(['borrower', 'branch', 'amortizationSchedules']);
        $period = LoanReminderSummary::nextOpenPeriod($loan);
        $allowed = $this->fit->allowed($loan, $now);
        $result = ['period' => $period, 'amount' => null, 'channels' => [], 'blocked' => [], 'unfit' => [], 'notes' => [], 'allowed' => $allowed];

        if (! in_array($templateType, $allowed, true)) {
            $result['unfit'][] = sprintf("A %s reminder doesn't fit this loan today", ReminderTemplate::typeLabel($templateType));
        }

        if ($period === null) {
            $result['blocked'][] = in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true)
                ? 'Nothing is due on this loan'
                : ReminderEligibility::closedReason($loan->status);

            return $result;
        }

        $settings = ReminderSetting::current();
        $hours = ContactHours::from($settings);
        $today = ReminderClock::today($now);
        $preference = BorrowerNotificationPreference::query()->where('borrower_id', $loan->borrower_id)->first();

        foreach ($channelChoice === 'both' ? ['sms', 'email'] : [$channelChoice] as $channel) {
            $item = new ReminderQueueItem([
                'loan_id' => $loan->id,
                'borrower_id' => $loan->borrower_id,
                'branch_id' => $loan->branch_id,
                'amortization_schedule_id' => $period->id,
                'period_number' => (int) $period->period_number,
                'due_date' => $period->due_date->toDateString(),
                'channel' => $channel,
                'template_type' => $templateType,
                'fire_date' => $today->toDateString(),
                'scheduled_at' => $now,
                'status' => 'queued',
                'attempts' => 0,
                'source' => 'manual',
            ]);
            $decision = $this->eligibility->check($item, $loan, null, $settings, $hours, $now);
            $amount = $decision->amountDue ?? $this->eligibility->amountDue($loan, $period, $now);
            $result['amount'] ??= $amount;

            if (in_array($decision->outcome, [EligibilityDecision::CANCEL, EligibilityDecision::SKIP], true)) {
                $result['blocked'][] = (string) $decision->reason;
            } elseif ($decision->outcome === EligibilityDecision::WAIT && $decision->waitUntil !== null) {
                $result['notes'][] = 'Will go at '.ReminderClock::manila($decision->waitUntil)->format('g:i A');
            }

            foreach ($this->slotProblems($loan, $channel, $today) as $problem) {
                $result['blocked'][] = $problem;
            }

            $contact = $loan->borrower === null ? null : $this->contacts->for($loan->borrower, $channel, $preference);
            $template = ReminderTemplate::resolve($templateType, $channel, $preference?->language);
            $rendered = null;

            if ($template === null) {
                $result['blocked'][] = sprintf('No active %s template for %s', ReminderTemplate::typeLabel($templateType), ReminderEligibility::channelLabel($channel));
            } else {
                $values = $this->renderer->variables($loan, (int) $period->period_number, $period->due_date, (float) $amount, $channel, $settings, $now);
                $rendered = $this->renderer->render($template->subject, $template->body, $values, $channel);

                if ($rendered->unknownVariables !== []) {
                    $result['blocked'][] = mb_substr("The {$template->name} template uses unknown variables: ".implode(', ', $rendered->unknownVariables), 0, 255);
                }
            }

            $result['channels'][] = [
                'channel' => $channel,
                'recipient' => $contact?->masked,
                'subject' => $rendered?->subject,
                'body' => $rendered?->body ?? '',
            ];
        }

        $result['blocked'] = array_values(array_unique($result['blocked']));
        $result['notes'] = array_values(array_unique($result['notes']));

        return $result;
    }

    /**
     * @return list<string>
     */
    private function slotProblems(Loan $loan, string $channel, Carbon $today): array
    {
        $problems = [];
        $holder = ReminderMessage::query()
            ->where('loan_id', $loan->id)
            ->where('channel', $channel)
            ->where('send_date', $today->toDateString())
            ->value('status');

        if ($holder !== null) {
            $problems[] = ReminderDispatcher::slotTakenBy($holder);
        }

        $openManual = ReminderQueueItem::query()
            ->where('loan_id', $loan->id)
            ->where('channel', $channel)
            ->where('source', 'manual')
            ->where('fire_date', $today->toDateString())
            ->whereIn('status', [...ReminderQueueItem::OPEN_STATUSES, 'processing'])
            ->exists();

        if ($openManual) {
            $problems[] = sprintf('A manual %s reminder for this loan is already queued today', ReminderEligibility::channelLabel($channel));
        }

        return $problems;
    }
}
