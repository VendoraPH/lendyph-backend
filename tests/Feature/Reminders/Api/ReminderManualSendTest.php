<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\AuditLog;
use App\Models\BorrowerNotificationPreference;
use App\Models\Loan;
use App\Models\ReminderMessage;
use App\Models\ReminderPause;
use App\Models\ReminderQueueItem;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A manual reminder only queues rows; `reminders:dispatch` hands them to the
 * driver like any other. The preview runs the engine's own final check, and
 * the send repeats it under the loan's lock.
 */
class ReminderManualSendTest extends TestCase
{
    use UsesReminderApi;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        $this->at('2026-09-15 09:00');
        $this->loan = $this->loanDue('2026-09-15');
    }

    public function test_a_manual_send_queues_rows_that_the_log_driver_then_logs_in_test_mode(): void
    {
        config(['reminders.drivers.sms' => 'log', 'reminders.drivers.email' => 'log']);
        $lines = [];
        Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->message;
        });

        $preview = $this->postJson('/api/reminders/manual-send/preview', $this->body('both'))->assertOk();
        $this->assertSame(['borrower_name', 'loan_account_number', 'installment_number', 'due_date', 'amount_due', 'messages', 'blocked_reasons', 'notes', 'allowed_template_types', 'test_mode'], array_keys($preview->json('data')));
        $this->assertSame(['channel', 'recipient', 'subject', 'body'], array_keys($preview->json('data.messages.0')));
        $this->assertSame('63917*****01', $preview->json('data.messages.0.recipient'));
        $this->assertSame([], $preview->json('data.blocked_reasons'));
        $this->assertSame(['sms', 'email'], array_column($preview->json('data.messages'), 'channel'));
        $this->assertStringContainsString('PHP 5,000.00', $preview->json('data.messages.0.body'));
        $this->assertStringContainsString('₱5,000.00', $preview->json('data.messages.1.body'));
        $this->assertSame(5000.0, (float) $preview->json('data.amount_due'));
        $this->assertSame(0, ReminderQueueItem::query()->count());

        $sent = $this->postJson('/api/reminders/manual-send', $this->body('both'))->assertCreated();
        $this->assertSame(['queued', 'queued'], array_column($sent->json('data.queued'), 'status'));
        $this->assertSame(['manual', 'manual'], array_column($sent->json('data.queued'), 'source'));
        $this->assertSame(0, ReminderMessage::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'reminder_manual_send')->where('auditable_id', $this->loan->id)->count());

        $this->dispatchReminders();

        $messages = ReminderMessage::query()->where('loan_id', $this->loan->id)->get();
        $this->assertCount(2, $messages);
        $this->assertSame(['log'], $messages->pluck('provider')->unique()->values()->all());
        $this->assertSame([true], $messages->pluck('test_mode')->unique()->values()->all());
        $this->assertSame(['manual'], $messages->pluck('source')->unique()->values()->all());
        $this->assertContains('Reminder handed to the log driver; nothing was sent.', $lines);
        $this->assertSame($this->admin->id, (int) $messages->first()->created_by);
    }

    public function test_a_second_manual_send_the_same_day_answers_422(): void
    {
        $this->postJson('/api/reminders/manual-send', $this->body('sms'))->assertCreated();
        $this->postJson('/api/reminders/manual-send', $this->body('sms'))->assertUnprocessable();
        $this->assertSame(1, ReminderQueueItem::query()->where('source', 'manual')->count());

        $this->dispatchReminders();
        $preview = $this->postJson('/api/reminders/manual-send/preview', $this->body('sms'))->assertOk();
        $this->assertNotEmpty($preview->json('data.blocked_reasons'));
        $this->postJson('/api/reminders/manual-send', $this->body('sms'))->assertUnprocessable();
    }

    public function test_a_pause_blocks_it(): void
    {
        ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $this->loan->id, 'reason' => 'Disputed', 'paused_at' => now()->subMinute()]);

        $this->assertBlocked('Reminders are paused for this loan: Disputed');
    }

    public function test_an_opt_out_blocks_it(): void
    {
        BorrowerNotificationPreference::query()->create(['borrower_id' => $this->loan->borrower_id, 'sms_enabled' => false]);

        $this->assertBlocked('Member turned off SMS reminders');
    }

    public function test_the_allow_list_blocks_it(): void
    {
        config(['reminders.allow_all_recipients' => false, 'reminders.allow_list' => []]);

        $this->assertBlocked('Recipient is not on the allow-list');
    }

    public function test_contact_hours_block_it_after_closing(): void
    {
        $this->at('2026-09-15 19:00');

        $this->assertBlocked('Outside contact hours');
    }

    public function test_before_opening_it_waits_rather_than_blocks(): void
    {
        $this->at('2026-09-15 07:00');

        $preview = $this->postJson('/api/reminders/manual-send/preview', $this->body('sms'))->assertOk();
        $this->assertSame([], $preview->json('data.blocked_reasons'));
        $this->assertContains('Will go at 8:00 AM', $preview->json('data.notes'));
        $this->postJson('/api/reminders/manual-send', $this->body('sms'))->assertCreated();
    }

    public function test_nothing_due_blocks_it(): void
    {
        $this->payPeriod($this->loan, 4000, 1000);

        $this->assertBlocked('Nothing is due on this loan');
    }

    public function test_the_days_slot_blocks_it(): void
    {
        $this->messageRow($this->queueRow($this->loan));

        $this->assertBlocked('Another reminder went out for this loan on this channel today');
    }

    private function assertBlocked(string $reason): void
    {
        $preview = $this->postJson('/api/reminders/manual-send/preview', $this->body('sms'))->assertOk();
        $this->assertContains($reason, $preview->json('data.blocked_reasons'));

        $before = ReminderQueueItem::query()->count();
        $this->postJson('/api/reminders/manual-send', $this->body('sms'))->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->assertSame($before, ReminderQueueItem::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'reminder_manual_send')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $channel): array
    {
        return ['loan_id' => $this->loan->id, 'channel' => $channel, 'template_type' => 'due_today'];
    }
}
