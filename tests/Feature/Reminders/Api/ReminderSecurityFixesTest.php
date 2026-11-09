<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\Loan;
use App\Models\ReminderBranchSetting;
use App\Models\ReminderEvent;
use App\Models\ReminderPause;
use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use App\Models\Repayment;
use App\Services\Reminders\MemberContact;
use App\Services\Reminders\ReminderRenderer;
use Tests\TestCase;

/**
 * The security review of #235: a boolean branch id, company-wide settings,
 * templates and every-branch rules for `branches:view_all` only, member
 * contact details behind `borrowers:view` and the member's branch, short
 * email local parts, manual templates that fit the loan, escaped email
 * values, and a send time before contact hours end.
 */
class ReminderSecurityFixesTest extends TestCase
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

    public function test_a_boolean_branch_id_cannot_reach_a_branch_the_caller_cant_see(): void
    {
        $far = $this->farBranch();
        $this->actingAs($this->staff(['reminders:settings'], $far));
        $this->assertSame(1, (int) $this->branch->id);

        $this->putJson('/api/reminders/settings', ['branches' => [['branch_id' => true, 'use_company_default' => false, 'sender_name' => 'Hijack']]])->assertForbidden();

        $this->assertFalse(ReminderBranchSetting::query()->where('branch_id', $this->branch->id)->exists());
    }

    public function test_a_branch_user_cannot_change_company_wide_settings_but_can_save_the_form_unchanged(): void
    {
        $branchUser = $this->staff(['reminders:view', 'reminders:settings']);
        $this->actingAs($branchUser);
        $current = $this->getJson('/api/reminders/settings')->assertOk()->json('data');

        $this->putJson('/api/reminders/settings', ['enabled' => false])->assertForbidden();
        $this->putJson('/api/reminders/settings', ['sms_provider' => ['provider' => 'semaphore', 'sender_id' => 'X', 'api_key' => 'sk-new']])->assertForbidden();
        $this->putJson('/api/reminders/settings', ['payment_instructions' => 'Pay me instead'])->assertForbidden();
        $this->putJson('/api/reminders/settings', ['contact_hours' => ['start' => '06:00', 'end' => '22:00']])->assertForbidden();
        $this->assertTrue(ReminderSetting::current()->enabled);
        $this->assertNull(ReminderSetting::current()->sms_api_key);

        $form = $current;
        unset($form['test_mode']);
        $form['sms_provider'] = array_diff_key($form['sms_provider'], ['has_credentials' => true]);
        $form['email_provider'] = array_diff_key($form['email_provider'], ['has_credentials' => true]);
        $form['branches'] = [['branch_id' => $this->branch->id, 'branch_name' => $this->branch->name, 'use_company_default' => false, 'sender_name' => 'Main Coop', 'contact_phone' => null, 'contact_email' => null]];

        $this->putJson('/api/reminders/settings', $form)->assertOk();
        $this->assertSame('Main Coop', ReminderBranchSetting::query()->where('branch_id', $this->branch->id)->value('sender_name'));

        $this->actingAs($this->staff(['reminders:settings', 'branches:view_all']));
        $this->putJson('/api/reminders/settings', ['enabled' => false, 'payment_instructions' => 'Pay at any branch.'])->assertOk();
        $this->assertFalse(ReminderSetting::current()->enabled);
    }

    public function test_templates_and_every_branch_rules_need_view_all(): void
    {
        $template = ReminderTemplate::query()->firstOrFail();
        $global = ReminderRule::query()->whereNull('branch_id')->firstOrFail();
        $this->actingAs($this->staff(['reminders:view', 'reminders:settings']));

        $this->postJson('/api/reminders/templates', $this->template())->assertForbidden();
        $this->putJson("/api/reminders/templates/{$template->id}", $this->template(['type' => $template->type, 'channel' => $template->channel, 'language' => $template->language]))->assertForbidden();
        $this->postJson('/api/reminders/rules', $this->rule(['branch_id' => null]))->assertForbidden();
        $this->putJson("/api/reminders/rules/{$global->id}", $this->rule(['branch_id' => $this->branch->id]))->assertForbidden();
        $this->postJson("/api/reminders/rules/{$global->id}/deactivate")->assertForbidden();
        $this->assertTrue($global->fresh()->is_active);
        $this->assertNull($global->fresh()->branch_id);

        $own = $this->postJson('/api/reminders/rules', $this->rule(['branch_id' => $this->branch->id]))->assertCreated()->json('data.id');
        $this->putJson("/api/reminders/rules/{$own}", $this->rule(['branch_id' => $this->branch->id, 'days' => 4]))->assertOk()->assertJsonPath('data.days', 4);
        $this->putJson("/api/reminders/rules/{$own}", $this->rule(['branch_id' => null]))->assertForbidden();
        $this->postJson("/api/reminders/rules/{$own}/deactivate")->assertOk();

        $this->actingAs($this->staff(['reminders:settings', 'branches:view_all']));
        $this->postJson('/api/reminders/templates', $this->template())->assertCreated();
        $this->postJson('/api/reminders/rules', $this->rule(['branch_id' => null]))->assertCreated();
        $this->postJson("/api/reminders/rules/{$global->id}/deactivate")->assertOk();
    }

    public function test_member_preferences_need_borrowers_view_and_the_loan_card_needs_loans_view(): void
    {
        $member = $this->loan->borrower_id;

        $this->actingAs($this->staff(['reminders:view']));
        $this->getJson("/api/borrowers/{$member}/notification-preferences")->assertForbidden();
        $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertForbidden();

        $this->actingAs($this->staff(['reminders:view', 'borrowers:view', 'loans:view']));
        $this->getJson("/api/borrowers/{$member}/notification-preferences")->assertOk()->assertJsonPath('data.mobile_number', '63917*****01');
        $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk();

        $this->actingAs($this->staff(['reminders:view', 'borrowers:update']));
        $this->putJson("/api/borrowers/{$member}/notification-preferences", ['sms_enabled' => false])->assertOk()
            ->assertJsonPath('data.mobile_number', null)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.sms_enabled', false);
    }

    public function test_recipients_and_a_members_pause_text_need_borrowers_view_and_the_members_branch(): void
    {
        $item = $this->queueRow($this->loan, ['status' => 'skipped', 'reason_code' => 'paused', 'reason' => 'Reminders are paused for this member: Hospitalised', 'recipient_masked' => '63917*****01']);
        $message = $this->messageRow($this->queueRow($this->loan, ['channel' => 'email']));
        ReminderPause::query()->create(['scope' => 'borrower', 'borrower_id' => $this->loan->borrower_id, 'reason' => 'Hospitalised', 'paused_at' => now()->subMinute()]);
        ReminderEvent::forItem('sent', $item, 'SMS to 63917*****01', $message->id);

        $this->actingAs($this->staff(['reminders:view', 'loans:view']));
        $queue = collect($this->getJson('/api/reminders/queue')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($queue[$item->id]['recipient']);
        $this->assertSame('Reminders are paused for this member', $queue[$item->id]['reason']);
        $this->assertNull($this->getJson("/api/reminders/history/{$message->id}")->assertOk()->json('data.recipient'));
        $this->assertNull($this->getJson('/api/reminders/pauses')->assertOk()->json('data.0.reason'));
        $summary = $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk()->json('data');
        $this->assertNull($summary['inherited_pause']['reason']);
        $this->assertSame([], array_values(array_filter(array_column($summary['events'], 'detail'), fn ($detail) => str_contains((string) $detail, '63917') || str_contains((string) $detail, 'Hospitalised'))));
        $this->assertStringNotContainsString('Hospitalised', (string) $this->getJson('/api/reminders/dashboard')->getContent());

        $this->actingAs($this->staff(['reminders:view', 'loans:view', 'borrowers:view']));
        $this->assertSame('63917*****01', collect($this->getJson('/api/reminders/queue')->json('data'))->firstWhere('id', $item->id)['recipient']);
        $this->assertSame('Hospitalised', $this->getJson('/api/reminders/pauses')->json('data.0.reason'));

        // The loan is in the caller's branch, its member is not.
        $this->loan->borrower->update(['branch_id' => $this->farBranch()->id]);
        $this->assertNull(collect($this->getJson('/api/reminders/queue')->json('data'))->firstWhere('id', $item->id)['recipient']);
        $this->assertNull($this->getJson("/api/reminders/history/{$message->id}")->json('data.recipient'));
    }

    public function test_a_short_email_local_part_shows_one_character(): void
    {
        $this->assertSame('a***@x.com', MemberContact::maskEmail('ab@x.com'));
        $this->assertSame('a***@x.com', MemberContact::maskEmail('abc@x.com'));
        $this->assertSame('ab***@x.com', MemberContact::maskEmail('abcd@x.com'));
    }

    public function test_a_manual_send_offers_only_templates_that_fit_the_loan(): void
    {
        $this->assertSame(['due_today'], $this->getJson("/api/loans/{$this->loan->id}/reminders")->json('data.allowed_template_types'));

        foreach (['long_past_due', 'past_due', 'upcoming', 'payment_confirmation', 'partial_payment'] as $type) {
            $preview = $this->postJson('/api/reminders/manual-send/preview', $this->manual($type))->assertOk();
            $this->assertNotEmpty($preview->json('data.blocked_reasons'), $type);
            $this->postJson('/api/reminders/manual-send', $this->manual($type))->assertUnprocessable()->assertJsonValidationErrors('template_type');
        }
        $this->assertSame([], $this->postJson('/api/reminders/manual-send/preview', $this->manual('due_today'))->json('data.blocked_reasons'));

        $this->at('2026-09-12 09:00');
        $this->assertSame(['upcoming'], $this->getJson("/api/loans/{$this->loan->id}/reminders")->json('data.allowed_template_types'));

        $this->at('2026-09-17 09:00');
        $this->assertSame([], $this->getJson("/api/loans/{$this->loan->id}/reminders")->json('data.allowed_template_types'));

        $this->at('2026-09-20 09:00');
        $this->assertSame(['past_due'], $this->getJson("/api/loans/{$this->loan->id}/reminders")->json('data.allowed_template_types'));

        $this->payPeriod($this->loan, 1000, 0);
        Repayment::factory()->create(['loan_id' => $this->loan->id, 'received_by' => $this->admin->id, 'payment_date' => '2026-09-28']);
        $this->at('2026-09-30 09:00');
        $this->assertSame(['long_past_due', 'partial_payment', 'payment_confirmation'], $this->getJson("/api/loans/{$this->loan->id}/reminders")->json('data.allowed_template_types'));
    }

    public function test_email_values_are_escaped_and_sms_values_stay_plain(): void
    {
        $renderer = app(ReminderRenderer::class);
        $values = ['borrower_first_name' => '<b>Juan</b> & "Co"', 'borrower_full_name' => "Juan\r\nBcc: x@y.z"];

        $email = $renderer->render('Hi {{borrower_full_name}}', 'Hi {{borrower_first_name}}, <p>pay</p>', $values, 'email');
        $this->assertSame('Hi &lt;b&gt;Juan&lt;/b&gt; &amp; &quot;Co&quot;, <p>pay</p>', $email->body);
        $this->assertStringNotContainsString("\n", (string) $email->subject);
        $this->assertStringNotContainsString("\r", (string) $email->subject);

        $sms = $renderer->render(null, 'Hi {{borrower_first_name}}', $values, 'sms');
        $this->assertSame('Hi <b>Juan</b> & "Co"', $sms->body);
    }

    public function test_a_rule_send_time_must_be_before_contact_hours_end(): void
    {
        $this->postJson('/api/reminders/rules', $this->rule(['send_time' => '18:00']))->assertJsonValidationErrors('send_time');
        $this->postJson('/api/reminders/rules', $this->rule(['send_time' => '18:30']))->assertJsonValidationErrors('send_time');
        $this->postJson('/api/reminders/rules', $this->rule(['send_time' => '17:59']))->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function manual(string $type): array
    {
        return ['loan_id' => $this->loan->id, 'channel' => 'sms', 'template_type' => $type];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Two days before', 'trigger' => 'before_due', 'days' => 2, 'send_time' => '09:00',
            'channels' => ['sms'], 'branch_id' => null, 'loan_product_id' => null, 'borrower_type' => 'all',
            'template_type' => 'upcoming', 'is_active' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function template(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Partial payment SMS', 'type' => 'partial_payment', 'channel' => 'sms', 'subject' => null,
            'body' => 'Hi {{borrower_first_name}}, {{amount_due}} is still due.', 'language' => 'fil', 'is_active' => true,
        ], $overrides);
    }
}
