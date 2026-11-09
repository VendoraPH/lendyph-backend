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
 * - **Still ours.** Every write to a claimed row is an UPDATE guarded on its
 *   id, this run's claim token and `processing`. A run that stalled long
 *   enough for its claim to be released and taken by another run finds it
 *   changed nothing and stops; it never restages a message that is no longer
 *   pending, so it can't send a reminder a second time.
 * - **One bad row.** An error on one row fails that row, with a reason, and
 *   the run goes on: failed as "could not be prepared" before its attempt
 *   started, and failed and never resent after.
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

                try {
                    $outcome = $this->process($item);
                } catch (Throwable $e) {
                    $outcome = $this->failOnError($item, $e);
                }

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
     * @return 'sent'|'retrying'|'failed'|'cancelled'|'skipped'|'waiting'|'lost'
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
        // Confirms the claim is still this run's, and records where it goes.
        if (! $this->guardedUpdate($item, ['amount_due' => $decision->amountDue, 'recipient_masked' => $decision->maskedRecipient])) {
            return 'lost';
        }

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
        [$message, $slotTaken] = $this->stageMessage($item, $loan, $template, $rendered, $decision, $driver, $smsParts);

        if ($message === null) {
            return $slotTaken === null ? 'lost' : $this->finish($item, 'cancelled', 'superseded', $slotTaken, $decision);
        }

        // Committed before the driver call, and only while the claim is still
        // this run's: from here a dead run's row is failed, never resent.
        if (! $this->guardedUpdate($item, ['sending_started_at' => now(), 'attempts' => $item->attempts + 1])) {
            return 'lost';
        }

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
     * handed over. Null with a reason when another message already holds this
     * loan's slot on this channel today; null with no reason when this row's
     * own message is no longer pending (another run handed it over), which is
     * never restaged.
     *
     * @return array{0: ?ReminderMessage, 1: ?string}
     */
    private function stageMessage(ReminderQueueItem $item, Loan $loan, ReminderTemplate $template, RenderedReminder $rendered, EligibilityDecision $decision, ReminderDriver $driver, ?int $smsParts): array
    {
        $message = ReminderMessage::query()->firstOrNew(['queue_item_id' => $item->id]);

        if ($message->exists && $message->status !== 'pending') {
            Log::warning('A reminder message is no longer pending; it is not staged again.', ['queue_item_id' => $item->id, 'status' => $message->status]);

            return [null, null];
        }

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
            return [null, $this->slotTakenReason($item, (string) $message->send_date?->toDateString())];
        }

        return [$message, null];
    }

    /**
     * Why this row can't have today's slot: what the message holding it did.
     */
    private function slotTakenReason(ReminderQueueItem $item, string $sendDate): string
    {
        $holder = ReminderMessage::query()
            ->where('loan_id', $item->loan_id)
            ->where('channel', $item->channel)
            ->whereDate('send_date', $sendDate)
            ->where('queue_item_id', '!=', $item->id)
            ->value('status');

        return match ($holder) {
            'failed', 'rejected', 'bounced' => "Today's reminder for this loan on this channel failed; a second one doesn't go the same day",
            'pending' => 'Another reminder for this loan on this channel is being sent today',
            default => 'Another reminder went out for this loan on this channel today',
        };
    }

    private function record(ReminderQueueItem $item, ReminderMessage $message, Loan $loan, DriverResult $result, ReminderSetting $settings, string $recipient): string
    {
        if ($result->outcome === DriverResult::SENT) {
            $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'provider_reference' => $result->reference, 'cost' => $result->cost, 'failure_reason' => null])->save();
            $this->guardedUpdate($item, ['status' => 'sent', 'reason_code' => null, 'reason' => null, 'next_attempt_at' => null] + $this->unclaimed(), mustHold: false);
            ReminderEvent::forItem('sent', $item, ReminderEligibility::channelLabel($item->channel).' to '.$item->recipient_masked, (int) $message->id);

            return 'sent';
        }

        if ($result->outcome === DriverResult::TEMPORARY && $item->attempts < $settings->maxAttempts()) {
            $message->forceFill(['status' => 'pending', 'failure_reason' => $result->reason])->save();
            $this->guardedUpdate($item, [
                'status' => 'queued',
                'reason_code' => $result->reasonCode,
                'reason' => $result->reason,
                'next_attempt_at' => now()->addMinutes($settings->retryDelayMinutes()),
            ] + $this->unclaimed(), mustHold: false);
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

        if ($this->finish($item, 'failed', $code, $reason, null, $message?->id) === 'lost') {
            return 'lost';
        }

        $message?->forceFill(['status' => 'failed', 'failure_reason' => $reason])->save();

        if ($loan !== null) {
            $this->notices->failed($loan, $item, $reason);
        }

        return 'failed';
    }

    /**
     * @return 'cancelled'|'skipped'|'failed'
     */
    /**
     * @return 'cancelled'|'skipped'|'failed'|'lost'
     */
    private function finish(ReminderQueueItem $item, string $status, string $code, string $reason, ?EligibilityDecision $decision = null, ?int $messageId = null): string
    {
        $finished = $this->guardedUpdate($item, [
            'status' => $status,
            'reason_code' => $code,
            'reason' => mb_substr($reason, 0, 255),
            'next_attempt_at' => null,
            'amount_due' => $decision?->amountDue ?? $item->amount_due,
            'recipient_masked' => $decision?->maskedRecipient ?? $item->recipient_masked,
        ] + $this->unclaimed());

        if (! $finished) {
            return 'lost';
        }

        ReminderEvent::forItem($status, $item, $reason, $messageId);

        return $status;
    }

    private function release(ReminderQueueItem $item, ?Carbon $until = null): void
    {
        $this->guardedUpdate($item, [
            'status' => $item->attempts > 0 ? 'queued' : 'scheduled',
            'scheduled_at' => $until ?? $item->scheduled_at,
        ] + $this->unclaimed());
    }

    /**
     * Writes `$attributes` to the row only while it is still this run's
     * claim (same id, claim token, and `processing`). False when another run
     * released or took it: the caller stops and touches nothing else.
     *
     * After the driver call (`mustHold: false`) the outcome is recorded even so
     * would be lost; it is logged, since the hand-over has already happened.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardedUpdate(ReminderQueueItem $item, array $attributes, bool $mustHold = true): bool
    {
        $guard = fn () => ReminderQueueItem::query()
            ->whereKey($item->id)
            ->where('claim_token', $item->claim_token)
            ->where('status', 'processing');

        // MySQL counts changed rows, not matched ones: a write of the values
        // the row already holds reports 0, so a 0 is checked against the same
        // guard before it is read as "lost".
        $held = $item->claim_token !== null
            && ($guard()->toBase()->update($attributes + ['updated_at' => now()]) > 0 || $guard()->exists());

        if (! $held) {
            Log::warning($mustHold
                ? 'A reminder row is no longer this run\'s claim; this run leaves it alone.'
                : 'A reminder row stopped being this run\'s claim while it was being sent; its outcome was not recorded on the row.', [
                    'queue_item_id' => $item->id,
                ]);

            return false;
        }

        $item->forceFill($attributes)->syncOriginal();

        return true;
    }

    /**
     * An error on one row: failed with a reason, so it is never claimed again
     * and the rest of the run goes on. Before the row's attempt started it
     * "could not be prepared"; after, it is never resent. Logged by class and
     * masked recipient only: a database error's message can carry the full
     * number or the text.
     */
    private function failOnError(ReminderQueueItem $item, Throwable $e): string
    {
        $current = ReminderQueueItem::query()->find($item->id);

        Log::error('A reminder row failed with an error; it is marked failed and the run goes on.', [
            'queue_item_id' => $item->id,
            'recipient' => $current?->recipient_masked,
            'exception_class' => $e::class,
        ]);

        if ($current === null || $current->status !== 'processing' || $current->claim_token !== $item->claim_token) {
            return 'lost';
        }

        $loan = Loan::query()->with('borrower')->find($current->loan_id);

        return $current->sending_started_at === null
            ? $this->fail($current, $loan, 'error', 'Could not be prepared for sending')
            : $this->fail($current, $loan, 'interrupted', 'Sending was interrupted; not resent so the member is not sent it twice');
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
