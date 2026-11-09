<?php

namespace App\Services\Reminders;

use App\Models\BorrowerNotificationPreference;
use App\Models\Loan;
use App\Models\ReminderEvent;
use App\Models\ReminderMessage;
use App\Models\ReminderQueueItem;
use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use App\Services\Reminders\Drivers\DriverResolver;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\Drivers\OutgoingReminder;
use App\Services\Reminders\Drivers\ReminderDriver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * `reminders:dispatch`: hands due reminders to their driver, one at a time.
 *
 * - **Claim.** Rows are claimed with one UPDATE ... ORDER BY ... LIMIT that
 *   stamps a fresh token, so two runs at once never hold the same row.
 * - **Final check.** ReminderEligibility runs on the live loan right before
 *   the driver call; a payment since planning cancels the reminder.
 * - **Once.** The message row (unique per queue item, and per loan, channel
 *   and day) and the row's `sending_started_at` are committed before the
 *   driver is called. A run killed after that leaves a row that is marked
 *   failed and never resent; a run killed before it leaves a row that is
 *   simply released and sent by the next run.
 * - **Retries.** A temporary failure retries after the retry delay, up to 3
 *   attempts in all; a permanent one never retries and flags the contact.
 * - **Budget.** A run stops after `reminders.dispatch.max_seconds` or
 *   `max_messages`, whichever comes first, and releases what it still holds.
 */
final class ReminderDispatcher
{
    private const BATCH = 25;

    private ?ReminderSetting $settings = null;

    /**
     * @var array<int, ?ReminderRule>
     */
    private array $rules = [];

    public function __construct(
        private readonly ReminderEligibility $eligibility,
        private readonly ReminderRenderer $renderer,
        private readonly DriverResolver $drivers,
        private readonly StaffNotices $notices,
    ) {}

    /**
     * @return array{recovered: int, processed: int, sent: int, retrying: int, failed: int, cancelled: int, skipped: int}
     */
    public function run(): array
    {
        $deadline = microtime(true) + max(1, (int) config('reminders.dispatch.max_seconds', 50));
        $limit = max(1, (int) config('reminders.dispatch.max_messages', 200));
        $totals = ['recovered' => $this->recoverStale(), 'processed' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'cancelled' => 0, 'skipped' => 0];

        while ($totals['processed'] < $limit && microtime(true) < $deadline) {
            $batch = $this->claim(min(self::BATCH, $limit - $totals['processed']));

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $item) {
                if (microtime(true) >= $deadline) {
                    $this->release($item);

                    continue;
                }

                $outcome = $this->process($item);
                $totals['processed']++;

                if (isset($totals[$outcome])) {
                    $totals[$outcome]++;
                }
            }
        }

        return $totals;
    }

    /**
     * Claims up to `$limit` due rows for this run, atomically.
     *
     * @return Collection<int, ReminderQueueItem>
     */
    public function claim(int $limit): Collection
    {
        $token = (string) Str::uuid();
        $now = now();

        $claimed = ReminderQueueItem::query()
            ->whereIn('status', ReminderQueueItem::OPEN_STATUSES)
            ->where('scheduled_at', '<=', $now)
            ->where(fn ($retry) => $retry->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->toBase()
            ->update(['status' => 'processing', 'claim_token' => $token, 'claimed_at' => $now, 'updated_at' => $now]);

        return $claimed === 0
            ? new Collection
            : ReminderQueueItem::query()->where('claim_token', $token)->where('status', 'processing')->orderBy('scheduled_at')->orderBy('id')->get();
    }

    /**
     * Sends or settles one claimed row.
     *
     * @return 'sent'|'retrying'|'failed'|'cancelled'|'skipped'|'waiting'
     */
    public function process(ReminderQueueItem $item): string
    {
        $now = now();
        $settings = $this->settings();
        $loan = Loan::query()->with(['borrower', 'branch', 'amortizationSchedules'])->find($item->loan_id);
        $rule = $this->rule($item->rule_id);
        $decision = $this->eligibility->check($item, $loan, $rule, $settings, ContactHours::from($settings), $now);

        if ($decision->outcome === EligibilityDecision::WAIT) {
            $this->release($item, $decision->waitUntil);

            return 'waiting';
        }

        // The officer follows up while the member still owes and nothing
        // pauses the loan, even when the member's own message can't go.
        if ($loan !== null && $rule !== null && $decision->stillOwedAndNotPaused()) {
            $this->followUpIfLongPastDue($loan, $item, $rule, (float) $decision->amountDue);
        }

        if ($decision->outcome === EligibilityDecision::CANCEL) {
            return $this->finish($item, 'cancelled', (string) $decision->code, (string) $decision->reason, $decision);
        }

        if ($decision->outcome === EligibilityDecision::SKIP) {
            if ($item->attempts > 0 && in_array($decision->code, ['outside_contact_hours', 'fire_date_passed'], true)) {
                return $this->fail($item, $loan, 'retry_window_closed', 'Could not be retried within contact hours');
            }

            return $this->finish($item, 'skipped', (string) $decision->code, (string) $decision->reason, $decision);
        }

        return $this->send($item, $loan, $decision, $settings);
    }

    /**
     * Rows a dead run left in `processing`: one whose attempt had started is
     * failed and never resent (the driver may have taken it); one that never
     * reached the driver goes back to the queue.
     */
    public function recoverStale(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('reminders.dispatch.stale_claim_minutes', 10)));
        $recovered = 0;

        ReminderQueueItem::query()
            ->where('status', 'processing')
            ->where('claimed_at', '<', $cutoff)
            ->orderBy('id')
            ->each(function (ReminderQueueItem $item) use (&$recovered): void {
                $recovered++;

                if ($item->sending_started_at === null) {
                    $this->release($item);

                    return;
                }

                Log::warning('A reminder was left mid-send by a run that stopped; it is marked failed and not resent.', [
                    'queue_item_id' => $item->id,
                ]);
                $this->fail($item, Loan::query()->with('borrower')->find($item->loan_id), 'interrupted', 'Sending was interrupted; not resent so the member is not sent it twice');
            });

        return $recovered;
    }

    private function send(ReminderQueueItem $item, Loan $loan, EligibilityDecision $decision, ReminderSetting $settings): string
    {
        $language = $decision->preference?->language;
        $template = ReminderTemplate::resolve($item->template_type, $item->channel, $language);

        if ($template === null) {
            return $this->finish($item, 'skipped', 'template_unavailable', "No active {$item->template_type} template for ".ReminderEligibility::channelLabel($item->channel), $decision);
        }

        $period = $decision->period;
        $values = $this->renderer->variables($loan, (int) $period->period_number, $period->due_date, (float) $decision->amountDue, $item->channel, $settings);
        $rendered = $this->renderer->render($template->subject, $template->body, $values, $item->channel);

        if ($rendered->unknownVariables !== [] || $rendered->body === '') {
            $detail = $rendered->unknownVariables === [] ? 'it renders empty' : 'it uses unknown variables: '.implode(', ', $rendered->unknownVariables);

            return $this->finish($item, 'skipped', 'template_unavailable', mb_substr("The {$template->name} template can't be sent: {$detail}", 0, 255), $decision);
        }

        $driver = $this->drivers->for($item->channel);
        $smsParts = $item->channel === 'sms' ? SmsParts::count($rendered->body) : null;
        $message = $this->stageMessage($item, $loan, $template, $rendered, $decision, $driver, $smsParts);

        if ($message === null) {
            return $this->finish($item, 'cancelled', 'superseded', 'Another reminder went out for this loan on this channel today', $decision);
        }

        // Committed before the driver call: from here a dead run's row is
        // failed, never resent.
        $item->forceFill([
            'sending_started_at' => now(),
            'attempts' => $item->attempts + 1,
            'amount_due' => $decision->amountDue,
            'recipient_masked' => $decision->maskedRecipient,
        ])->save();
        $message->forceFill(['attempts' => $item->attempts])->save();

        try {
            $result = $driver->send(new OutgoingReminder(
                (int) $message->id,
                (int) $item->id,
                $item->channel,
                (string) $decision->recipient,
                (string) $decision->maskedRecipient,
                $rendered->subject,
                $rendered->body,
                $smsParts,
            ));
        } catch (Throwable $e) {
            Log::error('A reminder driver threw; the attempt counts as a temporary failure.', [
                'queue_item_id' => $item->id,
                'driver' => $driver->name(),
                'exception_class' => $e::class,
            ]);
            $result = DriverResult::temporaryFailure('driver_error', 'The driver failed');
        }

        return $this->record($item, $message, $loan, $result, $settings, (string) $decision->recipient);
    }

    /**
     * The message row for this attempt, written with exactly what will be
     * handed over, or null when another message already went out for this
     * loan on this channel today.
     */
    private function stageMessage(ReminderQueueItem $item, Loan $loan, ReminderTemplate $template, RenderedReminder $rendered, EligibilityDecision $decision, ReminderDriver $driver, ?int $smsParts): ?ReminderMessage
    {
        $message = ReminderMessage::query()->firstOrNew(['queue_item_id' => $item->id]);
        $message->fill([
            'loan_id' => $loan->id,
            'borrower_id' => $loan->borrower_id,
            'rule_id' => $item->rule_id,
            'period_number' => $item->period_number,
            'channel' => $item->channel,
            'template_type' => $item->template_type,
            'language' => $template->language,
            'subject' => $rendered->subject,
            'body' => $rendered->body,
            'recipient' => $decision->recipient,
            'recipient_masked' => (string) $decision->maskedRecipient,
            'provider' => $driver->name(),
            'status' => 'pending',
            'sms_parts' => $smsParts,
            'test_mode' => config('reminders.live_sending') !== true || $driver->reachesNobody(),
            'send_date' => $message->send_date?->toDateString() ?? ReminderClock::today()->toDateString(),
            'scheduled_at' => $item->scheduled_at,
            'source' => $item->source,
            'created_by' => $item->created_by,
        ]);

        try {
            DB::transaction(fn () => $message->save());
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $message;
    }

    private function record(ReminderQueueItem $item, ReminderMessage $message, Loan $loan, DriverResult $result, ReminderSetting $settings, string $recipient): string
    {
        if ($result->outcome === DriverResult::SENT) {
            $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'provider_reference' => $result->reference, 'cost' => $result->cost, 'failure_reason' => null])->save();
            $item->forceFill(['status' => 'sent', 'reason_code' => null, 'reason' => null, 'next_attempt_at' => null] + $this->unclaimed())->save();
            ReminderEvent::forItem('sent', $item, ReminderEligibility::channelLabel($item->channel).' to '.$item->recipient_masked, (int) $message->id);

            return 'sent';
        }

        if ($result->outcome === DriverResult::TEMPORARY && $item->attempts < $settings->maxAttempts()) {
            $message->forceFill(['status' => 'pending', 'failure_reason' => $result->reason])->save();
            $item->forceFill([
                'status' => 'queued',
                'reason_code' => $result->reasonCode,
                'reason' => $result->reason,
                'next_attempt_at' => now()->addMinutes($settings->retryDelayMinutes()),
            ] + $this->unclaimed())->save();
            Log::warning('A reminder attempt failed and will be retried.', ['queue_item_id' => $item->id, 'attempt' => $item->attempts, 'reason_code' => $result->reasonCode]);

            return 'retrying';
        }

        $message->forceFill(['status' => $result->deliveryStatus, 'failure_reason' => $result->reason])->save();
        $this->finish($item, 'failed', (string) $result->reasonCode, (string) $result->reason, null, (int) $message->id);

        if ($result->outcome === DriverResult::PERMANENT) {
            $this->flagContact($loan, $item, $recipient, (string) $result->reason);
            $this->notices->contactInvalid($loan, $item->channel, $recipient, (string) $item->recipient_masked, (string) $result->reason);
        } elseif ($result->outcome === DriverResult::TEMPORARY) {
            $this->notices->failed($loan, $item, (string) $result->reason);
        }

        return 'failed';
    }

    private function fail(ReminderQueueItem $item, ?Loan $loan, string $code, string $reason): string
    {
        $message = ReminderMessage::query()->where('queue_item_id', $item->id)->first();
        $message?->forceFill(['status' => 'failed', 'failure_reason' => $reason])->save();
        $this->finish($item, 'failed', $code, $reason, null, $message?->id);

        if ($loan !== null) {
            $this->notices->failed($loan, $item, $reason);
        }

        return 'failed';
    }

    /**
     * @return 'cancelled'|'skipped'|'failed'
     */
    private function finish(ReminderQueueItem $item, string $status, string $code, string $reason, ?EligibilityDecision $decision = null, ?int $messageId = null): string
    {
        $item->forceFill([
            'status' => $status,
            'reason_code' => $code,
            'reason' => mb_substr($reason, 0, 255),
            'next_attempt_at' => null,
            'amount_due' => $decision?->amountDue ?? $item->amount_due,
            'recipient_masked' => $decision?->maskedRecipient ?? $item->recipient_masked,
        ] + $this->unclaimed())->save();
        ReminderEvent::forItem($status, $item, $reason, $messageId);

        return $status;
    }

    private function release(ReminderQueueItem $item, ?Carbon $until = null): void
    {
        $item->forceFill([
            'status' => $item->attempts > 0 ? 'queued' : 'scheduled',
            'scheduled_at' => $until ?? $item->scheduled_at,
        ] + $this->unclaimed())->save();
    }

    /**
     * @return array{claim_token: null, claimed_at: null, sending_started_at: null}
     */
    private function unclaimed(): array
    {
        return ['claim_token' => null, 'claimed_at' => null, 'sending_started_at' => null];
    }

    /**
     * Records the bad value on the member's preferences, so no reminder goes
     * to it again until staff change the member's contact.
     */
    private function flagContact(Loan $loan, ReminderQueueItem $item, string $recipient, string $reason): void
    {
        $preference = BorrowerNotificationPreference::query()->firstOrNew(['borrower_id' => $loan->borrower_id]);
        $prefix = $item->channel === 'email' ? 'email' : 'mobile';
        $preference->fill([
            "{$prefix}_invalid_value" => $recipient,
            "{$prefix}_invalid_reason" => mb_substr($reason, 0, 255),
            "{$prefix}_invalid_at" => now(),
            "{$prefix}_invalid_by" => null,
        ])->save();
    }

    private function followUpIfLongPastDue(Loan $loan, ReminderQueueItem $item, ReminderRule $rule, float $amountDue): void
    {
        if ($rule->trigger !== 'after_due' || $item->template_type !== 'long_past_due') {
            return;
        }

        $daysPastDue = (int) ReminderClock::manila($item->due_date)->startOfDay()->diffInDays(ReminderClock::today(), false);
        $this->notices->followUp($loan, $item, max(0, $daysPastDue), $amountDue);
    }

    private function settings(): ReminderSetting
    {
        return $this->settings ??= ReminderSetting::current();
    }

    private function rule(?int $id): ?ReminderRule
    {
        if ($id === null) {
            return null;
        }

        if (! array_key_exists($id, $this->rules)) {
            $this->rules[$id] = ReminderRule::query()->find($id);
        }

        return $this->rules[$id];
    }
}
