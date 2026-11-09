<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\Loan;
use App\Models\ReminderEvent;
use App\Models\ReminderPause;
use Tests\TestCase;

/**
 * Queue and history paging and stats, the dashboard's counts, the loan
 * timeline, and every response shape key for key against the frontend's
 * `types/reminder.ts` (additions marked in the PR).
 */
class ReminderListsAndShapesTest extends TestCase
{
    use UsesReminderApi;

    private const QUEUE_ITEM = ['id', 'borrower_id', 'borrower_name', 'loan_id', 'loan_account_number', 'installment_number', 'due_date', 'amount_due', 'rule_id', 'rule_name', 'channel', 'recipient', 'scheduled_at', 'status', 'reason', 'reason_code', 'attempts', 'source', 'test_mode'];

    private const MESSAGE = ['id', 'queue_id', 'borrower_id', 'borrower_name', 'loan_id', 'loan_account_number', 'installment_number', 'channel', 'recipient', 'template_type', 'subject', 'body', 'status', 'scheduled_at', 'sent_at', 'delivered_at', 'provider', 'provider_reference', 'attempts', 'failure_reason', 'source', 'created_by_name', 'segments', 'cost', 'test_mode', 'redacted_at'];

    private const RULE = ['id', 'name', 'trigger', 'days', 'send_time', 'channels', 'branch_id', 'branch_name', 'loan_product_id', 'loan_product_name', 'borrower_type', 'template_type', 'is_active', 'created_at', 'updated_at'];

    private const TEMPLATE = ['id', 'name', 'type', 'channel', 'subject', 'body', 'language', 'is_active', 'updated_at'];

    private const PAUSE = ['id', 'scope', 'borrower_id', 'borrower_name', 'loan_id', 'loan_account_number', 'reason', 'paused_by_name', 'paused_at', 'until'];

    private const EVENT = ['id', 'kind', 'at', 'title', 'detail', 'channel', 'installment_number', 'test_mode'];

    private const QUEUE_STATS = ['scheduled', 'queued', 'processing', 'sent', 'delivered', 'failed', 'cancelled', 'skipped'];

    private const MESSAGE_STATS = ['pending', 'sent', 'delivered', 'failed', 'rejected', 'bounced', 'opened'];

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        $this->at('2026-09-15 09:00');
        $this->loan = $this->loanDue('2026-09-15');
    }

    public function test_queue_pages_by_25_clamps_at_100_and_its_stats_equal_the_rows(): void
    {
        foreach (range(1, 30) as $i) {
            $this->queueRow($this->loan, ['period_number' => $i, 'status' => $i % 3 === 0 ? 'skipped' : 'scheduled', 'reason_code' => $i % 3 === 0 ? 'paused' : null]);
        }

        $page = $this->getJson('/api/reminders/queue')->assertOk();
        $this->assertCount(25, $page->json('data'));
        $this->assertSame(30, $page->json('meta.total'));
        $this->assertSame(25, $page->json('meta.per_page'));
        $this->assertArrayHasKey('links', $page->json());
        $this->assertSame(self::QUEUE_STATS, array_keys($page->json('meta.stats')));
        $this->assertSame(['scheduled' => 20, 'skipped' => 10], array_filter($page->json('meta.stats')));

        $this->assertSame(100, $this->getJson('/api/reminders/queue?per_page=500')->json('meta.per_page'));
        $this->assertSame(1, $this->getJson('/api/reminders/queue?per_page=0')->json('meta.per_page'));

        $skipped = $this->getJson('/api/reminders/queue?status=skipped')->assertOk();
        $this->assertSame(10, $skipped->json('meta.total'));
        $this->assertSame(['scheduled' => 20, 'skipped' => 10], array_filter($skipped->json('meta.stats')));
        $this->assertSame(['paused'], array_values(array_unique(array_column($skipped->json('data'), 'reason_code'))));

        $this->assertSame(self::QUEUE_ITEM, array_keys($page->json('data.0')));
    }

    public function test_history_pages_filters_and_its_shape_matches(): void
    {
        $sms = $this->messageRow($this->queueRow($this->loan));
        $email = $this->messageRow($this->queueRow($this->loan, ['channel' => 'email']), ['status' => 'failed', 'failure_reason' => 'Bounced']);

        $page = $this->getJson('/api/reminders/history')->assertOk();
        $this->assertSame(2, $page->json('meta.total'));
        $this->assertSame(self::MESSAGE_STATS, array_keys($page->json('meta.stats')));
        $this->assertSame(['sent' => 1, 'failed' => 1], array_filter($page->json('meta.stats')));
        $this->assertSame(self::MESSAGE, array_keys($page->json('data.0')));

        $this->assertSame([$email->id], array_column($this->getJson('/api/reminders/history?status=failed')->json('data'), 'id'));
        $this->assertSame([$sms->id], array_column($this->getJson('/api/reminders/history?channel=sms')->json('data'), 'id'));

        $detail = $this->getJson("/api/reminders/history/{$sms->id}")->assertOk();
        $this->assertSame(self::MESSAGE, array_keys($detail->json('data')));
        $detail->assertJsonPath('data.queue_id', $sms->queue_item_id)
            ->assertJsonPath('data.installment_number', 1)
            ->assertJsonPath('data.recipient', '63917*****01')
            ->assertJsonPath('data.segments', 1)
            ->assertJsonPath('data.test_mode', true)
            ->assertJsonPath('data.borrower_name', 'Juan Dela Cruz');
    }

    public function test_a_redacted_message_sends_null_text_and_its_redaction_time(): void
    {
        $message = $this->messageRow($this->queueRow($this->loan), ['redacted_at' => now(), 'body' => null, 'recipient' => null]);

        $this->getJson("/api/reminders/history/{$message->id}")->assertOk()
            ->assertJsonPath('data.subject', null)
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.recipient', '63917*****01')
            ->assertJsonPath('data.redacted_at', now()->toIso8601String());
    }

    public function test_search_matches_member_name_and_loan_number_with_wildcards_literal(): void
    {
        $this->queueRow($this->loan);
        $other = $this->loanDue('2026-09-15', [], [], ['first_name' => 'Pedro', 'last_name' => 'Reyes']);
        $this->queueRow($other);

        $this->assertSame([$this->loan->id], array_column($this->getJson('/api/reminders/queue?search=Dela Cruz')->json('data'), 'loan_id'));
        $this->assertSame([$other->id], array_column($this->getJson('/api/reminders/queue?search='.$other->loan_account_number)->json('data'), 'loan_id'));
        $this->assertSame(0, $this->getJson('/api/reminders/queue?search=%25')->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/reminders/queue?search=_')->json('meta.total'));
    }

    public function test_the_dashboard_counts_logged_apart_from_sent_and_its_shape_matches(): void
    {
        $this->messageRow($this->queueRow($this->loan), ['cost' => 0.5]);
        $this->messageRow($this->queueRow($this->loan, ['channel' => 'email']), ['test_mode' => false, 'provider' => 'mailgun']);
        $this->queueRow($this->loan, ['period_number' => 2, 'status' => 'failed']);
        $this->queueRow($this->loan, ['period_number' => 3, 'status' => 'queued']);
        $late = $this->loanDue('2026-09-05');
        $this->queueRow($late, ['template_type' => 'past_due']);
        $global = ReminderPause::query()->create(['scope' => 'global', 'reason' => 'Typhoon', 'paused_at' => now()->subMinute()]);

        $dashboard = $this->getJson('/api/reminders/dashboard?date=2026-09-15')->assertOk()->json('data');

        $this->assertSame(['counts', 'sms', 'daily', 'items', 'global_pause', 'test_mode', 'enabled'], array_keys($dashboard));
        $this->assertSame(['today' => 5, 'sent' => 1, 'logged' => 1, 'pending' => 2, 'failed' => 1, 'sms_sent' => 0, 'sms_logged' => 1, 'emails_sent' => 1, 'emails_logged' => 0, 'upcoming_due' => 1, 'overdue' => 1], $dashboard['counts']);
        $this->assertSame(['credits_remaining' => null, 'low_credit_threshold' => null, 'sent_this_month' => 0, 'logged_this_month' => 1, 'estimated_cost_this_month' => 0.5], $dashboard['sms']);
        $this->assertCount(7, $dashboard['daily']);
        $this->assertSame(['date', 'sent', 'logged', 'delivered', 'failed'], array_keys($dashboard['daily'][0]));
        $this->assertSame(['date' => '2026-09-15', 'sent' => 1, 'logged' => 1, 'delivered' => 0, 'failed' => 0], $dashboard['daily'][6]);
        $this->assertSame('2026-09-09', $dashboard['daily'][0]['date']);
        $this->assertCount(5, $dashboard['items']);
        $this->assertSame(self::QUEUE_ITEM, array_keys($dashboard['items'][0]));
        $this->assertSame($global->id, $dashboard['global_pause']['id']);
        $this->assertSame(self::PAUSE, array_keys($dashboard['global_pause']));
        $this->assertTrue($dashboard['test_mode']);
        $this->assertTrue($dashboard['enabled']);
    }

    public function test_rules_templates_and_pauses_are_plain_arrays_with_matching_shapes(): void
    {
        ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $this->loan->id, 'reason' => 'Disputed', 'paused_at' => now()->subMinute(), 'paused_by' => $this->admin->id]);

        $rules = $this->getJson('/api/reminders/rules')->assertOk()->json('data');
        $this->assertCount(5, $rules);
        $this->assertSame(self::RULE, array_keys($rules[0]));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $rules[0]['send_time']);

        $templates = $this->getJson('/api/reminders/templates')->assertOk()->json('data');
        $this->assertCount(12, $templates);
        $this->assertSame(self::TEMPLATE, array_keys($templates[0]));

        $pauses = $this->getJson('/api/reminders/pauses')->assertOk()->json('data');
        $this->assertSame(self::PAUSE, array_keys($pauses[0]));
        $this->assertSame($this->loan->loan_account_number, $pauses[0]['loan_account_number']);
        $this->assertNotEmpty($pauses[0]['paused_by_name']);
    }

    public function test_the_loan_summary_and_its_timeline(): void
    {
        $this->at('2026-09-15 07:00');
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();
        ReminderPause::query()->create(['scope' => 'borrower', 'borrower_id' => $this->loan->borrower_id, 'reason' => 'Asked us', 'paused_at' => now()->subMinute()]);

        $summary = $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk()->json('data');

        $this->assertSame(['loan_id', 'next_due_date', 'next_amount_due', 'next_reminder_at', 'pause', 'inherited_pause', 'suggested_template_type', 'events'], array_keys($summary));
        $this->assertSame('2026-09-15', $summary['next_due_date']);
        $this->assertSame(5000.0, (float) $summary['next_amount_due']);
        $this->assertNull($summary['pause']);
        $this->assertSame('borrower', $summary['inherited_pause']['scope']);
        $this->assertSame('due_today', $summary['suggested_template_type']);
        $this->assertSame(self::EVENT, array_keys($summary['events'][0]));
        $sent = collect($summary['events'])->firstWhere('kind', 'sent');
        $this->assertNotNull($sent);
        $this->assertTrue($sent['test_mode']);
        $this->assertStringContainsString('logged', strtolower($sent['title']));
        $this->assertStringNotContainsString('sent', strtolower($sent['title']));
        $this->assertSame('paused', collect($summary['events'])->last()['kind']);
        $ats = array_column($summary['events'], 'at');
        $sorted = $ats;
        sort($sorted);
        $this->assertSame($sorted, $ats);
    }

    public function test_a_manual_send_and_a_payment_read_in_the_timeline(): void
    {
        $this->postJson('/api/reminders/manual-send', ['loan_id' => $this->loan->id, 'channel' => 'sms', 'template_type' => 'due_today'])->assertCreated();
        $this->dispatchReminders();
        $item = $this->queueRow($this->loan, ['channel' => 'email', 'status' => 'cancelled', 'reason_code' => 'paid', 'reason' => 'Payment received before send time']);
        ReminderEvent::forItem('cancelled', $item, 'Payment received before send time');

        $kinds = array_column($this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk()->json('data.events'), 'kind');

        $this->assertContains('manual_sent', $kinds);
        $this->assertContains('payment_received', $kinds);
        $this->assertNotContains('sent', $kinds);
    }

    public function test_preferences_show_masked_contact_and_save_only_the_four_fields(): void
    {
        $member = $this->loan->borrower_id;

        $read = $this->getJson("/api/borrowers/{$member}/notification-preferences")->assertOk()->json('data');
        $this->assertSame(['borrower_id', 'sms_enabled', 'email_enabled', 'preferred_channel', 'language', 'mobile_number', 'mobile_verified', 'email', 'email_verified', 'invalid_contact_reason', 'pause'], array_keys($read));
        $this->assertSame('63917*****01', $read['mobile_number']);
        $this->assertSame('ju***@example.com', $read['email']);

        $this->putJson("/api/borrowers/{$member}/notification-preferences", [
            'sms_enabled' => false, 'email_enabled' => true, 'preferred_channel' => 'email', 'language' => 'en',
            'mobile_verified_value' => '639999999999', 'mobile_invalid_value' => '639171234501',
        ])->assertOk()->assertJsonPath('data.sms_enabled', false)->assertJsonPath('data.preferred_channel', 'email');

        $this->assertDatabaseHas('borrower_notification_preferences', ['borrower_id' => $member, 'sms_enabled' => false, 'mobile_verified_value' => null, 'mobile_invalid_value' => null]);
        $this->putJson("/api/borrowers/{$member}/notification-preferences", ['preferred_channel' => 'fax'])->assertJsonValidationErrors('preferred_channel');
    }
}
