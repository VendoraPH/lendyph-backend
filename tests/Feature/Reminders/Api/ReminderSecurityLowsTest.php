<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\AuditLog;
use App\Models\BorrowerNotificationPreference;
use App\Models\Loan;
use App\Models\ReminderBranchSetting;
use App\Models\ReminderEvent;
use App\Models\ReminderPause;
use App\Models\ReminderSetting;
use Tests\TestCase;

/**
 * The re-review of 30f8572 (Lows): the manual send's reasons, company columns
 * a branch user never writes, provider text behind the member's details, and
 * rule templates that fit their trigger.
 */
class ReminderSecurityLowsTest extends TestCase
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

    public function test_manual_preview_and_send_without_borrowers_view_hide_recipients_and_a_members_pause_text(): void
    {
        ReminderPause::query()->create(['scope' => 'borrower', 'borrower_id' => $this->loan->borrower_id, 'reason' => 'Hospitalised', 'paused_at' => now()->subMinute()]);
        $this->actingAs($this->staff(['reminders:send']));
        $body = ['loan_id' => $this->loan->id, 'channel' => 'both', 'template_type' => 'due_today'];

        $preview = $this->postJson('/api/reminders/manual-send/preview', $body)->assertOk();
        $this->assertSame([null, null], array_column($preview->json('data.messages'), 'recipient'));
        $this->assertContains('Reminders are paused for this member', $preview->json('data.blocked_reasons'));
        $this->assertStringNotContainsString('Hospitalised', (string) $preview->getContent());

        $send = $this->postJson('/api/reminders/manual-send', $body)->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->assertContains('Reminders are paused for this member', $send->json('errors.channel'));
        $this->assertStringNotContainsString('Hospitalised', (string) $send->getContent());
    }

    public function test_an_out_of_branch_members_pause_and_timeline_stay_hidden(): void
    {
        $item = $this->queueRow($this->loan, ['status' => 'skipped', 'reason_code' => 'paused', 'reason' => 'Reminders are paused for this member: Hospitalised']);
        ReminderEvent::forItem('skipped', $item, 'Reminders are paused for this member: Hospitalised');
        ReminderPause::query()->create(['scope' => 'borrower', 'borrower_id' => $this->loan->borrower_id, 'reason' => 'Hospitalised', 'paused_at' => now()->subMinute()]);
        $this->loan->borrower->update(['branch_id' => $this->farBranch()->id]);
        $this->actingAs($this->staff(['reminders:view', 'loans:view', 'borrowers:view']));

        $summary = $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk();
        $this->assertSame('borrower', $summary->json('data.inherited_pause.scope'));
        $this->assertNull($summary->json('data.inherited_pause.reason'));
        $this->assertStringNotContainsString('Hospitalised', (string) $summary->getContent());
        $this->assertStringNotContainsString('Hospitalised', (string) $this->getJson('/api/reminders/queue')->getContent());
        $this->assertStringNotContainsString('Hospitalised', (string) $this->getJson('/api/reminders/pauses')->getContent());
    }

    public function test_provider_failure_text_is_hidden_from_callers_who_may_not_read_the_member(): void
    {
        BorrowerNotificationPreference::query()->create(['borrower_id' => $this->loan->borrower_id, 'mobile_invalid_value' => '639171234501', 'mobile_invalid_reason' => 'Carrier says: number 639171234501 ported']);
        $flagged = $this->queueRow($this->loan, ['status' => 'skipped', 'reason_code' => 'contact_flagged', 'reason' => 'Mobile number was marked invalid: Carrier says: number 639171234501 ported']);
        ReminderEvent::forItem('skipped', $flagged, $flagged->reason);
        $failedItem = $this->queueRow($this->loan, ['channel' => 'email', 'status' => 'failed', 'reason_code' => 'bounced', 'reason' => 'Mailbox juan1@example.com full']);
        ReminderEvent::forItem('failed', $failedItem, $failedItem->reason);
        $message = $this->messageRow($failedItem, ['status' => 'failed', 'failure_reason' => 'Mailbox juan1@example.com full']);
        $failedItem->update(['status' => 'failed']);
        $interrupted = $this->queueRow($this->loan, ['period_number' => 2, 'status' => 'failed', 'reason_code' => 'interrupted', 'reason' => 'Sending was interrupted; not resent so the member is not sent it twice']);

        $this->actingAs($this->staff(['reminders:view', 'loans:view']));
        $queue = collect($this->getJson('/api/reminders/queue')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('Mobile number was marked invalid', $queue[$flagged->id]['reason']);
        $this->assertNull($queue[$failedItem->id]['reason']);
        $this->assertSame('Sending was interrupted; not resent so the member is not sent it twice', $queue[$interrupted->id]['reason']);
        $this->assertNull($this->getJson("/api/reminders/history/{$message->id}")->json('data.failure_reason'));
        $summary = (string) $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk()->getContent();
        $this->assertStringNotContainsString('Carrier says', $summary);
        $this->assertStringNotContainsString('Mailbox', $summary);

        $this->actingAs($this->staff(['reminders:view', 'loans:view', 'borrowers:view']));
        $this->assertSame('Mailbox juan1@example.com full', $this->getJson("/api/reminders/history/{$message->id}")->json('data.failure_reason'));
    }

    public function test_a_branch_user_never_writes_company_columns(): void
    {
        ReminderSetting::current()->update(['sms_sender_id' => '0917', 'sms_api_key' => 'sk-stored', 'payment_instructions' => 'Pay at the office.']);
        $branchUser = $this->staff(['reminders:view', 'reminders:settings']);
        $this->actingAs($branchUser);
        $form = $this->form($this->getJson('/api/reminders/settings')->assertOk()->json('data'));

        // A numeric look-alike of a text column is a change.
        $lookAlike = $form;
        $lookAlike['sms_provider']['sender_id'] = '917';
        $this->putJson('/api/reminders/settings', $lookAlike)->assertForbidden();
        $this->assertSame('0917', ReminderSetting::current()->sms_sender_id);

        // Any filled key is a change, the stored one included: no oracle.
        foreach (['sk-stored', 'sk-guess'] as $key) {
            $withKey = $form;
            $withKey['sms_provider']['api_key'] = $key;
            $this->putJson('/api/reminders/settings', $withKey)->assertForbidden();
        }
        $withSecret = $form;
        $withSecret['sms_provider']['api_secret'] = 'anything';
        $this->putJson('/api/reminders/settings', $withSecret)->assertForbidden();

        // An admin changes a company field after the branch user loaded the
        // form; the branch user's stale whole-form save can't undo it.
        $this->actingAs($this->admin);
        $this->putJson('/api/reminders/settings', ['payment_instructions' => 'Pay by GCash only.'])->assertOk();
        $this->actingAs($branchUser);
        $this->putJson('/api/reminders/settings', $form)->assertForbidden();
        $this->assertSame('Pay by GCash only.', ReminderSetting::current()->payment_instructions);

        // Unchanged, the form saves the branch only and writes no company column.
        $fresh = $this->form($this->getJson('/api/reminders/settings')->json('data'));
        $fresh['branches'] = [['branch_id' => $this->branch->id, 'use_company_default' => false, 'sender_name' => 'Main Coop', 'contact_phone' => null, 'contact_email' => null]];
        $updatedBy = ReminderSetting::current()->updated_by;
        $audits = AuditLog::query()->where('auditable_type', ReminderSetting::class)->count();
        $this->putJson('/api/reminders/settings', $fresh)->assertOk();
        $this->assertSame($updatedBy, ReminderSetting::current()->updated_by);
        $this->assertSame($audits, AuditLog::query()->where('auditable_type', ReminderSetting::class)->count());
        $this->assertSame('Main Coop', ReminderBranchSetting::query()->where('branch_id', $this->branch->id)->value('sender_name'));
        $this->assertSame('sk-stored', ReminderSetting::current()->sms_api_key);
    }

    public function test_a_rules_template_must_fit_its_trigger(): void
    {
        $rule = fn (string $trigger, int $days, string $type) => ['name' => 'R', 'trigger' => $trigger, 'days' => $days, 'send_time' => '09:00', 'channels' => ['sms'], 'branch_id' => null, 'loan_product_id' => null, 'borrower_type' => 'all', 'template_type' => $type, 'is_active' => true];

        foreach ([['before_due', 3, 'past_due'], ['before_due', 3, 'payment_confirmation'], ['on_due', 0, 'long_past_due'], ['on_due', 0, 'upcoming'], ['after_due', 3, 'upcoming'], ['after_due', 3, 'due_today'], ['after_due', 3, 'partial_payment']] as [$trigger, $days, $type]) {
            $this->postJson('/api/reminders/rules', $rule($trigger, $days, $type))->assertJsonValidationErrors('template_type');
        }

        foreach ([['before_due', 3, 'upcoming'], ['on_due', 0, 'due_today'], ['after_due', 3, 'past_due'], ['after_due', 20, 'long_past_due']] as [$trigger, $days, $type]) {
            $this->postJson('/api/reminders/rules', $rule($trigger, $days, $type))->assertCreated();
        }
    }

    /**
     * The settings form as the frontend sends it back: the GET answer
     * without `test_mode` and `has_credentials`.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function form(array $settings): array
    {
        unset($settings['test_mode'], $settings['sms_provider']['has_credentials'], $settings['email_provider']['has_credentials']);

        return $settings;
    }
}
