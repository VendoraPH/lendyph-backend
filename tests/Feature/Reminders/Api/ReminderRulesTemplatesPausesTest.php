<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\AuditLog;
use App\Models\ReminderPause;
use App\Models\ReminderQueueItem;
use App\Models\ReminderRule;
use App\Models\ReminderTemplate;
use Tests\TestCase;

/**
 * Rule, template and pause writes: the frontend's own rule checks, unknown
 * variables and duplicate templates refused with 422, a preview that writes
 * nothing, and one pause in force per target.
 */
class ReminderRulesTemplatesPausesTest extends TestCase
{
    use UsesReminderApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        $this->at('2026-09-15 09:00');
    }

    public function test_rules_validate_like_the_rule_form(): void
    {
        $this->postJson('/api/reminders/rules', $this->rule(['trigger' => 'on_due', 'days' => 3]))->assertJsonValidationErrors('days');
        $this->postJson('/api/reminders/rules', $this->rule(['days' => 0]))->assertJsonValidationErrors('days');
        $this->postJson('/api/reminders/rules', $this->rule(['days' => 91]))->assertJsonValidationErrors('days');
        $this->postJson('/api/reminders/rules', $this->rule(['send_time' => '07:00']))->assertJsonValidationErrors('send_time');
        $this->postJson('/api/reminders/rules', $this->rule(['send_time' => '25:00']))->assertJsonValidationErrors('send_time');
        $this->postJson('/api/reminders/rules', $this->rule(['channels' => []]))->assertJsonValidationErrors('channels');
        $this->postJson('/api/reminders/rules', $this->rule(['channels' => ['fax']]))->assertJsonValidationErrors('channels.0');
        $this->postJson('/api/reminders/rules', $this->rule(['name' => ' ']))->assertJsonValidationErrors('name');
        $this->postJson('/api/reminders/rules', $this->rule(['template_type' => 'nope']))->assertJsonValidationErrors('template_type');
    }

    public function test_a_rule_is_created_updated_deactivated_and_audited(): void
    {
        $created = $this->postJson('/api/reminders/rules', $this->rule(['branch_id' => $this->branch->id]))->assertCreated()
            ->assertJsonPath('data.days', 2)->assertJsonPath('data.send_time', '09:00')->assertJsonPath('data.branch_name', $this->branch->name);
        $id = $created->json('data.id');

        $this->putJson("/api/reminders/rules/{$id}", $this->rule(['trigger' => 'on_due', 'days' => 0, 'send_time' => '10:30', 'template_type' => 'due_today']))->assertOk()
            ->assertJsonPath('data.trigger', 'on_due')->assertJsonPath('data.send_time', '10:30');
        $this->postJson("/api/reminders/rules/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertFalse(ReminderRule::query()->findOrFail($id)->is_active);
        $this->assertSame(3, AuditLog::query()->where('auditable_type', ReminderRule::class)->where('auditable_id', $id)->count());
        $this->putJson('/api/reminders/rules/999999', $this->rule())->assertNotFound();
    }

    public function test_templates_refuse_unknown_variables_a_missing_email_subject_and_a_duplicate(): void
    {
        $this->postJson('/api/reminders/templates', $this->template(['body' => 'Hi {{borrower_first_name}}, pay {{total_amount}}']))
            ->assertJsonValidationErrors('body')
            ->assertJsonFragment(['The body uses unknown variables: total_amount.']);
        $this->postJson('/api/reminders/templates', $this->template(['channel' => 'email', 'subject' => null]))->assertJsonValidationErrors('subject');
        $this->postJson('/api/reminders/templates', $this->template(['channel' => 'email', 'subject' => 'Pay {{nope}}']))->assertJsonValidationErrors('subject');

        $duplicate = ReminderTemplate::query()->where('type', 'due_today')->where('channel', 'sms')->firstOrFail();
        $this->postJson('/api/reminders/templates', $this->template(['type' => 'due_today', 'language' => $duplicate->language]))->assertJsonValidationErrors('type');

        $other = ReminderTemplate::query()->where('type', 'upcoming')->where('channel', 'sms')->firstOrFail();
        $this->putJson("/api/reminders/templates/{$other->id}", $this->template(['type' => 'due_today', 'language' => $duplicate->language]))->assertJsonValidationErrors('type');
        $this->putJson("/api/reminders/templates/{$duplicate->id}", $this->template(['type' => 'due_today', 'language' => $duplicate->language, 'body' => 'Due today: {{remaining_amount}}']))->assertOk()
            ->assertJsonPath('data.body', 'Due today: {{remaining_amount}}');
    }

    public function test_a_template_is_created_with_its_aliases(): void
    {
        $this->postJson('/api/reminders/templates', $this->template(['language' => 'fil', 'body' => 'Bayaran ang {{remaining_amount}} sa {{company_name}}']))->assertCreated()
            ->assertJsonPath('data.language', 'fil')->assertJsonPath('data.subject', null);
    }

    public function test_the_preview_renders_a_loans_next_period_or_samples_and_writes_nothing(): void
    {
        $loan = $this->loanDue('2026-09-15');
        $counts = [ReminderQueueItem::query()->count(), AuditLog::query()->count()];

        $real = $this->postJson('/api/reminders/templates/preview', ['channel' => 'sms', 'body' => 'Hi {{borrower_first_name}}, {{amount_due}} due {{due_date}} for {{loan_number}} {{nope}}', 'loan_id' => $loan->id])->assertOk();
        $this->assertSame(['subject', 'body', 'unknown_variables', 'sms_parts'], array_keys($real->json('data')));
        $this->assertSame("Hi Juan, PHP 5,000.00 due Sep 15, 2026 for {$loan->loan_account_number} {{nope}}", $real->json('data.body'));
        $this->assertSame(['nope'], $real->json('data.unknown_variables'));
        $this->assertSame(1, $real->json('data.sms_parts'));

        $sample = $this->postJson('/api/reminders/templates/preview', ['channel' => 'email', 'subject' => 'Due {{due_date}}', 'body' => '{{amount_due}}'])->assertOk();
        $this->assertSame('Due Sep 15, 2026', $sample->json('data.subject'));
        $this->assertSame('₱2,500.00', $sample->json('data.body'));
        $this->assertNull($sample->json('data.sms_parts'));
        $this->assertSame('PHP 2,500.00', $this->postJson('/api/reminders/templates/preview', ['channel' => 'sms', 'body' => '{{amount_due}}'])->json('data.body'));

        $this->assertSame($counts, [ReminderQueueItem::query()->count(), AuditLog::query()->count()]);
    }

    public function test_one_pause_in_force_per_target_and_resume_needs_one(): void
    {
        $loan = $this->loanDue('2026-09-15');

        $this->postJson('/api/reminders/pause', ['scope' => 'loan', 'loan_id' => $loan->id, 'reason' => 'Disputed', 'until' => '2026-09-01 00:00:00'])->assertJsonValidationErrors('until');
        $pause = $this->postJson('/api/reminders/pause', ['scope' => 'loan', 'loan_id' => $loan->id, 'reason' => 'Disputed', 'until' => '2026-10-01T00:00:00+08:00'])->assertCreated();
        $pause->assertJsonPath('data.scope', 'loan')->assertJsonPath('data.loan_id', $loan->id)->assertJsonPath('data.reason', 'Disputed');
        $this->postJson('/api/reminders/pause', ['scope' => 'loan', 'loan_id' => $loan->id, 'reason' => 'Again'])->assertJsonValidationErrors('scope');
        $this->postJson('/api/reminders/pause', ['scope' => 'borrower', 'borrower_id' => $loan->borrower_id, 'reason' => 'Asked'])->assertCreated();

        $this->postJson('/api/reminders/resume', ['scope' => 'loan', 'loan_id' => $loan->id])->assertOk();
        $resumed = ReminderPause::query()->findOrFail($pause->json('data.id'));
        $this->assertNotNull($resumed->resumed_at);
        $this->assertSame($this->admin->id, (int) $resumed->resumed_by);
        $this->postJson('/api/reminders/resume', ['scope' => 'loan', 'loan_id' => $loan->id])->assertJsonValidationErrors('scope');
        $this->postJson('/api/reminders/pause', ['scope' => 'loan', 'reason' => 'x'])->assertJsonValidationErrors('loan_id');
        $this->postJson('/api/reminders/pause', ['scope' => 'everyone', 'reason' => 'x'])->assertJsonValidationErrors('scope');
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
            'body' => 'Hi {{borrower_first_name}}, {{amount_due}} is still due on {{loan_number}}.', 'language' => 'fil', 'is_active' => true,
        ], $overrides);
    }
}
