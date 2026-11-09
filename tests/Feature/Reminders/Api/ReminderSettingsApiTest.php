<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\AuditLog;
use App\Models\ReminderBranchSetting;
use App\Models\ReminderMessage;
use App\Models\ReminderSetting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The settings row through the API: write-only encrypted credentials, the
 * engine's own limits, and a test mode no saved provider can switch off.
 */
class ReminderSettingsApiTest extends TestCase
{
    use UsesReminderApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
    }

    public function test_a_key_is_stored_encrypted_kept_on_a_blank_put_and_never_in_a_response_or_audit_row(): void
    {
        $saved = $this->putJson('/api/reminders/settings', ['sms_provider' => ['provider' => 'semaphore', 'sender_id' => 'BINHS', 'api_key' => 'sk-very-secret-key', 'api_secret' => 'shh-secret-two', 'cost_per_segment' => 0.5]])->assertOk();

        $this->assertTrue($saved->json('data.sms_provider.has_credentials'));
        $this->assertFalse($saved->json('data.email_provider.has_credentials'));
        $raw = (array) DB::table('reminder_settings')->orderBy('id')->first();
        $this->assertNotSame('sk-very-secret-key', $raw['sms_api_key']);
        $this->assertStringNotContainsString('sk-very-secret-key', json_encode($raw));
        $this->assertSame('sk-very-secret-key', ReminderSetting::current()->sms_api_key);

        $this->putJson('/api/reminders/settings', ['sms_provider' => ['provider' => 'semaphore', 'sender_id' => 'BINHS2', 'api_key' => '', 'api_secret' => null]])->assertOk();
        $this->putJson('/api/reminders/settings', ['sms_provider' => ['provider' => 'semaphore', 'sender_id' => 'BINHS3']])->assertOk();
        $this->assertSame('sk-very-secret-key', ReminderSetting::current()->sms_api_key);
        $this->assertSame('shh-secret-two', ReminderSetting::current()->sms_api_secret);
        $this->assertSame('BINHS3', ReminderSetting::current()->sms_sender_id);

        $audits = json_encode(AuditLog::query()->where('auditable_type', ReminderSetting::class)->get()->toArray());
        $this->assertStringContainsString('BINHS', $audits);
        $this->assertStringNotContainsString('sk-very-secret-key', $audits);
        $this->assertStringNotContainsString('shh-secret-two', $audits);
    }

    public function test_a_saved_provider_never_switches_off_test_mode_and_dispatch_still_only_logs(): void
    {
        config(['reminders.drivers.sms' => 'log', 'reminders.drivers.email' => 'log', 'reminders.live_sending' => true]);
        $this->putJson('/api/reminders/settings', [
            'enabled' => true,
            'sms_provider' => ['provider' => 'semaphore', 'sender_id' => 'BINHS', 'api_key' => 'sk-very-secret-key'],
            'email_provider' => ['provider' => 'mailgun', 'sender_name' => 'Binhs', 'sender_email' => 'no-reply@binhs.test', 'api_key' => 'mg-key'],
        ])->assertOk()->assertJsonPath('data.test_mode', true);

        $this->assertTrue($this->getJson('/api/reminders/settings')->json('data.test_mode'));
        $this->assertTrue($this->getJson('/api/reminders/dashboard')->json('data.test_mode'));
        $this->assertTrue($this->getJson('/api/reminders/dashboard')->json('data.enabled'));

        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $messages = ReminderMessage::query()->where('loan_id', $loan->id)->get();
        $this->assertNotEmpty($messages);
        $this->assertSame(['log'], $messages->pluck('provider')->unique()->values()->all());
        $this->assertSame([true], $messages->pluck('test_mode')->unique()->values()->all());
    }

    public function test_test_mode_is_read_only_on_save(): void
    {
        $this->putJson('/api/reminders/settings', ['test_mode' => false])->assertOk()->assertJsonPath('data.test_mode', true);
    }

    public function test_the_engine_limits_are_validated(): void
    {
        $this->putJson('/api/reminders/settings', ['timezone' => 'UTC'])->assertJsonValidationErrors('timezone');
        $this->putJson('/api/reminders/settings', ['contact_hours' => ['start' => '05:00', 'end' => '18:00']])->assertJsonValidationErrors('contact_hours.start');
        $this->putJson('/api/reminders/settings', ['contact_hours' => ['start' => '08:00', 'end' => '23:00']])->assertJsonValidationErrors('contact_hours.end');
        $this->putJson('/api/reminders/settings', ['contact_hours' => ['start' => '18:00', 'end' => '08:00']])->assertJsonValidationErrors('contact_hours.end');
        $this->putJson('/api/reminders/settings', ['retry' => ['max_attempts' => 4, 'delay_minutes' => 15]])->assertJsonValidationErrors('retry.max_attempts');
        $this->putJson('/api/reminders/settings', ['retry' => ['max_attempts' => 0, 'delay_minutes' => 15]])->assertJsonValidationErrors('retry.max_attempts');

        $this->putJson('/api/reminders/settings', [
            'timezone' => 'Asia/Manila',
            'default_send_time' => '09:30',
            'channels' => ['sms' => true, 'email' => false],
            'contact_hours' => ['start' => '06:00', 'end' => '22:00'],
            'retry' => ['max_attempts' => 2, 'delay_minutes' => 20],
            'payment_instructions' => 'Pay at any branch.',
        ])->assertOk()
            ->assertJsonPath('data.default_send_time', '09:30')
            ->assertJsonPath('data.channels', ['sms' => true, 'email' => false])
            ->assertJsonPath('data.contact_hours', ['start' => '06:00', 'end' => '22:00'])
            ->assertJsonPath('data.retry', ['max_attempts' => 2, 'delay_minutes' => 20])
            ->assertJsonPath('data.payment_instructions', 'Pay at any branch.');
    }

    public function test_branches_are_upserted(): void
    {
        $body = ['branches' => [['branch_id' => $this->branch->id, 'use_company_default' => false, 'sender_name' => 'Main Coop', 'contact_phone' => '0917 000 2222', 'contact_email' => 'main@binhs.test']]];

        $this->putJson('/api/reminders/settings', $body)->assertOk();
        $this->putJson('/api/reminders/settings', $body)->assertOk();

        $this->assertSame(1, ReminderBranchSetting::query()->where('branch_id', $this->branch->id)->count());
        $row = collect($this->getJson('/api/reminders/settings')->json('data.branches'))->firstWhere('branch_id', $this->branch->id);
        $this->assertSame(['branch_id' => $this->branch->id, 'branch_name' => $this->branch->name, 'use_company_default' => false, 'sender_name' => 'Main Coop', 'contact_phone' => '0917 000 2222', 'contact_email' => 'main@binhs.test'], $row);
    }

    public function test_the_settings_shape_matches_the_frontends_type(): void
    {
        $settings = $this->getJson('/api/reminders/settings')->assertOk()->json('data');

        $this->assertSame(['enabled', 'test_mode', 'timezone', 'default_send_time', 'channels', 'contact_hours', 'retry', 'sms_provider', 'email_provider', 'payment_instructions', 'branches'], array_keys($settings));
        $this->assertSame(['provider', 'sender_id', 'has_credentials', 'cost_per_segment'], array_keys($settings['sms_provider']));
        $this->assertSame(['provider', 'sender_name', 'sender_email', 'reply_to', 'has_credentials'], array_keys($settings['email_provider']));
        $this->assertSame(['branch_id', 'branch_name', 'use_company_default', 'sender_name', 'contact_phone', 'contact_email'], array_keys($settings['branches'][0]));
        $this->assertSame('Asia/Manila', $settings['timezone']);
    }

    public function test_a_view_only_role_reads_the_settings_but_cannot_save_them(): void
    {
        $this->actingAs($this->staff(['reminders:view']));

        $this->getJson('/api/reminders/settings')->assertOk()->assertJsonPath('data.test_mode', true);
        $this->putJson('/api/reminders/settings', ['enabled' => true])->assertForbidden();
    }
}
