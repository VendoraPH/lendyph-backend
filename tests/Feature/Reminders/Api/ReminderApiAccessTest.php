<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\Loan;
use App\Models\ReminderPause;
use App\Models\ReminderRule;
use App\Models\ReminderTemplate;
use Tests\TestCase;

/**
 * Who may call each reminders endpoint, and what a branch user can reach:
 * one permission per endpoint (403 without it), another branch's loan, member,
 * message or pause answers 404 (or fails like a missing id in a body), an
 * out-of-scope branch filter answers 403, and other branches' rows stay out of
 * every list, count and stat. No response ever holds a full mobile number,
 * email address or credential.
 */
class ReminderApiAccessTest extends TestCase
{
    use UsesReminderApi;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        $this->at('2026-09-15 08:00');
        $this->loan = $this->loanDue('2026-09-15');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private function endpoints(): array
    {
        $rule = ReminderRule::query()->firstOrFail();
        $template = ReminderTemplate::query()->firstOrFail();
        $message = $this->messageRow($this->queueRow($this->loan));
        $loan = $this->loan->id;
        $member = $this->loan->borrower_id;

        return [
            'dashboard' => ['GET', '/api/reminders/dashboard', ['reminders:view']],
            'rules' => ['GET', '/api/reminders/rules', ['reminders:view']],
            'rule store' => ['POST', '/api/reminders/rules', ['reminders:settings']],
            'rule update' => ['PUT', "/api/reminders/rules/{$rule->id}", ['reminders:settings']],
            'rule deactivate' => ['POST', "/api/reminders/rules/{$rule->id}/deactivate", ['reminders:settings']],
            'templates' => ['GET', '/api/reminders/templates', ['reminders:view']],
            'template store' => ['POST', '/api/reminders/templates', ['reminders:settings']],
            'template update' => ['PUT', "/api/reminders/templates/{$template->id}", ['reminders:settings']],
            'template preview' => ['POST', '/api/reminders/templates/preview', ['reminders:settings']],
            'queue' => ['GET', '/api/reminders/queue', ['reminders:view']],
            'history' => ['GET', '/api/reminders/history', ['reminders:view']],
            'history detail' => ['GET', "/api/reminders/history/{$message->id}", ['reminders:view']],
            'loan summary' => ['GET', "/api/loans/{$loan}/reminders", ['reminders:view']],
            'manual preview' => ['POST', '/api/reminders/manual-send/preview', ['reminders:send']],
            'manual send' => ['POST', '/api/reminders/manual-send', ['reminders:send']],
            'pauses' => ['GET', '/api/reminders/pauses', ['reminders:view']],
            'pause' => ['POST', '/api/reminders/pause', ['reminders:pause']],
            'resume' => ['POST', '/api/reminders/resume', ['reminders:pause']],
            'settings' => ['GET', '/api/reminders/settings', ['reminders:view']],
            'settings update' => ['PUT', '/api/reminders/settings', ['reminders:settings']],
            'preferences' => ['GET', "/api/borrowers/{$member}/notification-preferences", ['reminders:view']],
            'preferences update' => ['PUT', "/api/borrowers/{$member}/notification-preferences", ['reminders:view', 'borrowers:update']],
        ];
    }

    public function test_there_are_exactly_twenty_two_reminder_routes(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/reminders/')
                || str_ends_with($route->uri(), '/reminders') && str_starts_with($route->uri(), 'api/loans/')
                || str_ends_with($route->uri(), '/notification-preferences'))
            ->flatMap(fn ($route) => collect(array_diff($route->methods(), ['HEAD']))->map(fn ($method) => $method.' '.$route->uri()))
            ->sort()->values()->all();

        $this->assertSame(collect($this->endpoints())->map(fn ($e) => $e[0].' '.preg_replace('/\/\d+/', '/{id}', ltrim($e[1], '/')))
            ->map(fn ($e) => str_replace(['loans/{id}', 'borrowers/{id}'], ['loans/{loan}', 'borrowers/{borrower}'], $e))
            ->sort()->values()->all(), $routes);
    }

    public function test_each_endpoint_answers_403_without_its_permission_and_not_403_with_it(): void
    {
        $everyOther = fn (array $needed) => array_values(array_diff([...self::REMINDER_PERMISSIONS, 'borrowers:update', 'borrowers:view', 'loans:view'], $needed));

        foreach ($this->endpoints() as $name => [$method, $uri, $needed]) {
            foreach ($needed as $missing) {
                $without = $this->staff(array_values(array_diff([...$everyOther($needed), ...$needed], [$missing])));
                $this->actingAs($without)->json($method, $uri, [])->assertStatus(403, "{$name} without {$missing}");
            }

            $this->actingAs($this->staff($needed));
            $status = $this->json($method, $uri, [])->status();
            $this->assertNotSame(403, $status, "{$name} with ".implode(', ', $needed));
            $this->assertNotSame(500, $status, "{$name} answered 500");
        }
    }

    public function test_another_branchs_loan_member_message_and_rule_answer_404(): void
    {
        $far = $this->farLoanDue('2026-09-15');
        $farMessage = $this->messageRow($this->queueRow($far));
        $farRule = ReminderRule::query()->create([
            'name' => 'Far only', 'trigger' => 'on_due', 'days' => 0, 'send_time' => '09:00', 'channels' => ['sms'],
            'branch_id' => $far->branch_id, 'borrower_type' => 'all', 'template_type' => 'due_today',
        ]);
        $this->actingAs($this->staff([...self::REMINDER_PERMISSIONS, 'borrowers:update']));

        $this->getJson("/api/reminders/history/{$farMessage->id}")->assertNotFound();
        $this->getJson("/api/loans/{$far->id}/reminders")->assertNotFound();
        $this->getJson("/api/borrowers/{$far->borrower_id}/notification-preferences")->assertNotFound();
        $this->putJson("/api/borrowers/{$far->borrower_id}/notification-preferences", ['sms_enabled' => false])->assertNotFound();
        $this->putJson("/api/reminders/rules/{$farRule->id}", $this->ruleBody())->assertNotFound();
        $this->postJson("/api/reminders/rules/{$farRule->id}/deactivate")->assertNotFound();
        $this->assertTrue($farRule->fresh()->is_active);
        $this->assertNotContains($farRule->id, array_column($this->getJson('/api/reminders/rules')->assertOk()->json('data'), 'id'));
    }

    public function test_another_branchs_ids_in_a_body_fail_like_missing_ones(): void
    {
        $far = $this->farLoanDue('2026-09-15');
        $this->actingAs($this->staff(self::REMINDER_PERMISSIONS));

        $this->postJson('/api/reminders/manual-send/preview', ['loan_id' => $far->id, 'channel' => 'sms', 'template_type' => 'due_today'])->assertJsonValidationErrors('loan_id');
        $this->postJson('/api/reminders/manual-send', ['loan_id' => $far->id, 'channel' => 'sms', 'template_type' => 'due_today'])->assertJsonValidationErrors('loan_id');
        $this->postJson('/api/reminders/templates/preview', ['channel' => 'sms', 'body' => 'Hi {{borrower_first_name}}', 'loan_id' => $far->id])->assertJsonValidationErrors('loan_id');
        $this->postJson('/api/reminders/pause', ['scope' => 'loan', 'loan_id' => $far->id, 'reason' => 'x'])->assertJsonValidationErrors('loan_id');
        $this->postJson('/api/reminders/pause', ['scope' => 'borrower', 'borrower_id' => $far->borrower_id, 'reason' => 'x'])->assertJsonValidationErrors('borrower_id');
        $this->postJson('/api/reminders/resume', ['scope' => 'loan', 'loan_id' => $far->id])->assertJsonValidationErrors('loan_id');
        $this->postJson('/api/reminders/rules', $this->ruleBody(['branch_id' => $far->branch_id]))->assertJsonValidationErrors('branch_id');
        $this->assertSame(0, ReminderPause::query()->count());
    }

    public function test_an_out_of_scope_branch_filter_answers_403(): void
    {
        $far = $this->farBranch();
        $this->actingAs($this->staff(self::REMINDER_PERMISSIONS));

        $this->getJson("/api/reminders/dashboard?branch_id={$far->id}")->assertForbidden();
        $this->getJson("/api/reminders/queue?branch_id={$far->id}")->assertForbidden();
        $this->getJson("/api/reminders/history?branch_id={$far->id}")->assertForbidden();
        $this->getJson("/api/reminders/queue?branch_id={$this->branch->id}")->assertOk();
    }

    public function test_a_global_pause_or_resume_and_other_branches_settings_need_view_all(): void
    {
        $far = $this->farBranch();
        $this->actingAs($this->staff(self::REMINDER_PERMISSIONS));

        $this->postJson('/api/reminders/pause', ['scope' => 'global', 'reason' => 'Typhoon'])->assertForbidden();
        $this->postJson('/api/reminders/resume', ['scope' => 'global'])->assertForbidden();
        $this->putJson('/api/reminders/settings', ['branches' => [['branch_id' => $far->id, 'use_company_default' => false, 'sender_name' => 'Far']]])->assertForbidden();
        $this->assertNotContains($far->id, array_column($this->getJson('/api/reminders/settings')->assertOk()->json('data.branches'), 'branch_id'));

        $this->actingAs($this->staff([...self::REMINDER_PERMISSIONS, 'branches:view_all']));
        $this->postJson('/api/reminders/pause', ['scope' => 'global', 'reason' => 'Typhoon'])->assertCreated();
        $this->postJson('/api/reminders/resume', ['scope' => 'global'])->assertOk();
        $this->putJson('/api/reminders/settings', ['branches' => [['branch_id' => $far->id, 'use_company_default' => false, 'sender_name' => 'Far']]])->assertOk();
        $this->assertContains($far->id, array_column($this->getJson('/api/reminders/settings')->assertOk()->json('data.branches'), 'branch_id'));
    }

    public function test_other_branches_rows_stay_out_of_every_list_count_and_stat(): void
    {
        $near = $this->queueRow($this->loan);
        $this->messageRow($near);
        $far = $this->farLoanDue('2026-09-15');
        $this->messageRow($this->queueRow($far));
        $this->queueRow($far, ['channel' => 'email', 'status' => 'skipped']);
        ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $far->id, 'reason' => 'Far pause', 'paused_at' => now()->subMinute()]);
        ReminderPause::query()->create(['scope' => 'borrower', 'borrower_id' => $far->borrower_id, 'reason' => 'Far member', 'paused_at' => now()->subMinute()]);
        $nearPause = ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $this->loan->id, 'reason' => 'Near pause', 'paused_at' => now()->subMinute()]);
        $global = ReminderPause::query()->create(['scope' => 'global', 'reason' => 'Everyone', 'paused_at' => now()->subMinute()]);
        $this->actingAs($this->staff(self::REMINDER_PERMISSIONS));

        $queue = $this->getJson('/api/reminders/queue')->assertOk();
        $this->assertSame([$this->loan->id], array_values(array_unique(array_column($queue->json('data'), 'loan_id'))));
        $this->assertSame(1, $queue->json('meta.total'));
        $this->assertSame(1, array_sum($queue->json('meta.stats')));

        $history = $this->getJson('/api/reminders/history')->assertOk();
        $this->assertSame([$this->loan->id], array_column($history->json('data'), 'loan_id'));
        $this->assertSame(1, array_sum($history->json('meta.stats')));

        $dashboard = $this->getJson('/api/reminders/dashboard?date=2026-09-15')->assertOk();
        $this->assertSame(1, $dashboard->json('data.counts.today'));
        $this->assertSame(1, $dashboard->json('data.counts.logged'));
        $this->assertSame([$this->loan->id], array_column($dashboard->json('data.items'), 'loan_id'));

        $pauses = $this->getJson('/api/reminders/pauses')->assertOk();
        $this->assertEqualsCanonicalizing([$nearPause->id, $global->id], array_column($pauses->json('data'), 'id'));

        $this->getJson("/api/reminders/queue?search={$far->loan_account_number}")->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_no_response_holds_a_full_mobile_number_email_or_credential(): void
    {
        $item = $this->queueRow($this->loan);
        $message = $this->messageRow($item);
        $this->messageRow($this->queueRow($this->loan, ['channel' => 'email']));
        ReminderPause::query()->create(['scope' => 'borrower', 'borrower_id' => $this->loan->borrower_id, 'reason' => 'Asked', 'paused_at' => now()->subMinute()]);
        $this->putJson('/api/reminders/settings', ['sms_provider' => ['provider' => 'semaphore', 'sender_id' => 'BINHS', 'api_key' => 'sk-very-secret-key', 'api_secret' => 'shh-secret-two']])->assertOk();
        $loan = $this->loan->id;
        $member = $this->loan->borrower_id;

        $responses = [
            $this->getJson('/api/reminders/dashboard?date=2026-09-15'),
            $this->getJson('/api/reminders/queue'),
            $this->getJson('/api/reminders/history'),
            $this->getJson("/api/reminders/history/{$message->id}"),
            $this->getJson("/api/loans/{$loan}/reminders"),
            $this->getJson('/api/reminders/pauses'),
            $this->getJson('/api/reminders/settings'),
            $this->putJson('/api/reminders/settings', ['sms_provider' => ['provider' => 'semaphore', 'sender_id' => 'BINHS', 'api_key' => 'sk-very-secret-key']]),
            $this->getJson("/api/borrowers/{$member}/notification-preferences"),
            $this->putJson("/api/borrowers/{$member}/notification-preferences", ['sms_enabled' => true, 'email_enabled' => true, 'preferred_channel' => 'both', 'language' => 'en']),
            $this->postJson('/api/reminders/manual-send/preview', ['loan_id' => $loan, 'channel' => 'both', 'template_type' => 'past_due']),
            $this->postJson('/api/reminders/templates/preview', ['channel' => 'email', 'subject' => 'Hi', 'body' => 'Hi {{borrower_first_name}}', 'loan_id' => $loan]),
            $this->getJson('/api/reminders/rules'),
            $this->getJson('/api/reminders/templates'),
        ];

        foreach ($responses as $response) {
            $this->assertLessThan(300, $response->status(), (string) $response->getContent());
            $json = (string) $response->getContent();
            foreach (['639171234501', '09171234501', '0917 123 4501', '9171234501', 'juan1@example.com', 'sk-very-secret-key', 'shh-secret-two', '09998887777'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ruleBody(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Two days before', 'trigger' => 'before_due', 'days' => 2, 'send_time' => '09:00',
            'channels' => ['sms'], 'branch_id' => null, 'loan_product_id' => null, 'borrower_type' => 'all',
            'template_type' => 'upcoming', 'is_active' => true,
        ], $overrides);
    }
}
