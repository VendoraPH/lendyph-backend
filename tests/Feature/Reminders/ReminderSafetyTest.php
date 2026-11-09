<?php

namespace Tests\Feature\Reminders;

use App\Models\AuditLog;
use App\Models\BorrowerNotificationPreference;
use App\Models\ReminderEvent;
use App\Models\ReminderMessage;
use App\Models\ReminderQueueItem;
use App\Models\ReminderSetting;
use App\Services\Reminders\AllowList;
use App\Services\Reminders\Drivers\DriverResolver;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\Drivers\LogDriver;
use App\Services\Reminders\Drivers\UnavailableDriver;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\TestCase;

/**
 * Nothing is ever sent: no network code, log-only drivers, safe defaults with
 * no `.env` change, and an allow-list in front of every driver.
 */
class ReminderSafetyTest extends TestCase
{
    use BuildsReminderLoans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
    }

    public function test_no_network_code_exists_in_the_reminders_engine_its_models_or_its_commands(): void
    {
        $files = collect(File::allFiles(app_path('Services/Reminders')))->map(fn ($file) => $file->getPathname())
            ->merge(File::glob(app_path('Models/Reminder*.php')))
            ->merge([
                app_path('Models/BorrowerNotificationPreference.php'),
                app_path('Console/Commands/PlanReminders.php'),
                app_path('Console/Commands/DispatchReminders.php'),
                app_path('Console/Commands/RedactReminderMessages.php'),
            ]);
        $this->assertGreaterThan(20, $files->count());

        $forbidden = [
            'Http facade' => '/\bHttp::|Facades\\\\Http\b/',
            'HTTP client' => '/Http\\\\Client/i',
            'Mail facade' => '/\bMail::|Facades\\\\Mail\b|Symfony\\\\Component\\\\Mailer/',
            'mail()' => '/(?<![\w>:$])mail\s*\(/i',
            'on-demand notification' => '/Notification::route/',
            'notify()' => '/->notify\s*\(/',
            'Guzzle' => '/guzzle/i',
            'curl' => '/\bcurl/i',
            'sockets' => '/\bsocket_|fsockopen|stream_socket_client/i',
            'URL streams' => '/\b(fopen|file_get_contents|file|readfile|copy)\s*\(\s*[\'"](https?|ftp|ssl|tls):/i',
            'processes' => '/Facades\\\\Process|Component\\\\Process|\bProcess::|(?<![\w>:$])(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/',
        ];
        foreach ($files as $path) {
            $this->assertFileExists($path);
            $source = File::get($path);
            foreach ($forbidden as $what => $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $source, "{$what} appears in {$path}");
            }
        }
    }

    public function test_with_the_default_config_no_driver_is_handed_anything(): void
    {
        config(['reminders' => require config_path('reminders.php')]);
        $this->assertFalse(config('reminders.allow_all_recipients'));
        $this->assertSame([], config('reminders.allow_list'));
        $lines = [];
        Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->message;
        });
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertSame(['skipped'], ReminderQueueItem::query()->where('loan_id', $loan->id)->whereDate('fire_date', '2026-09-15')->pluck('status')->unique()->values()->all());
        $this->assertSame(['not_allowed'], ReminderQueueItem::query()->where('loan_id', $loan->id)->whereDate('fire_date', '2026-09-15')->pluck('reason_code')->unique()->values()->all());
        $this->assertSame(0, ReminderMessage::query()->count());
        $this->assertCount(0, $this->driver->calls);
        $this->assertSame([], array_values(array_filter($lines, fn (string $line) => str_contains($line, 'driver'))));
    }

    public function test_the_fake_driver_exists_only_in_tests(): void
    {
        config(['reminders.drivers.sms' => 'fake']);
        $this->assertSame($this->driver, app(DriverResolver::class)->for('sms'));

        $this->app['env'] = 'production';
        try {
            $driver = app(DriverResolver::class)->for('sms');
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertInstanceOf(UnavailableDriver::class, $driver);
    }

    public function test_audit_rows_never_keep_a_members_full_number_or_email(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        BorrowerNotificationPreference::query()->create([
            'borrower_id' => $loan->borrower_id,
            'mobile_verified_value' => '639171234501',
            'email_verified_value' => 'juan1@example.com',
        ]);
        $this->plan();
        $this->driver->willReturn('sms', DriverResult::permanentFailure('invalid_number', 'Invalid mobile number'));
        $this->driver->willReturn('email', DriverResult::permanentFailure('bounced', 'Address bounced', 'bounced'));
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $rows = AuditLog::query()->where('auditable_type', BorrowerNotificationPreference::class)->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $json = json_encode($rows->map(fn (AuditLog $row) => [$row->old_values, $row->new_values])->all());
        $this->assertStringNotContainsString('639171234501', $json);
        $this->assertStringNotContainsString('juan1@example.com', $json);
        $this->assertStringContainsString('Invalid mobile number', $json);
    }

    public function test_the_config_defaults_are_safe_with_no_env_change(): void
    {
        $config = require config_path('reminders.php');

        $this->assertSame('log', $config['drivers']['sms']);
        $this->assertSame('log', $config['drivers']['email']);
        $this->assertFalse($config['live_sending']);
        $this->assertSame([], $config['allow_list']);
        $this->assertFalse($config['allow_all_recipients']);
    }

    public function test_an_unknown_driver_name_marks_the_row_not_set_up_and_logs_a_critical_error(): void
    {
        config(['reminders.drivers.sms' => 'semaphore']);
        $this->assertInstanceOf(UnavailableDriver::class, app(DriverResolver::class)->for('sms'));
        config(['reminders.drivers.sms' => 'log']);
        $this->assertInstanceOf(LogDriver::class, app(DriverResolver::class)->for('sms'));

        config(['reminders.drivers.sms' => 'semaphore', 'reminders.drivers.email' => 'semaphore']);
        Log::spy();
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $item = $this->item($loan, 'Due today');
        $this->assertSame(['failed', 'not_set_up', 'Sending is not set up'], [$item->status, $item->reason_code, $item->reason]);
        $this->assertCount(0, $this->driver->calls);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'not set up'))->atLeast()->once();
    }

    public function test_the_log_driver_writes_one_line_without_the_text_or_the_full_number(): void
    {
        config(['reminders.drivers.sms' => 'log', 'reminders.drivers.email' => 'log']);
        $lines = [];
        Log::listen(function ($event) use (&$lines) {
            $lines[] = [$event->level, $event->message, $event->context];
        });
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->rule('Due today')->update(['channels' => ['sms']]);
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $driverLines = array_values(array_filter($lines, fn ($line) => str_contains($line[1], 'log driver')));
        $this->assertCount(1, $driverLines);
        [, $message, $context] = $driverLines[0];
        $this->assertSame(['channel', 'message_id', 'queue_item_id', 'recipient', 'sms_parts'], collect($context)->keys()->sort()->values()->all());
        $this->assertSame('63917*****01', $context['recipient']);
        $everything = json_encode($lines);
        $this->assertStringNotContainsString('639171234501', $everything);
        $this->assertStringNotContainsString('09171234501', $everything);
        $this->assertStringNotContainsString('PHP 5,000.00', $everything);

        $message = ReminderMessage::query()->where('loan_id', $loan->id)->sole();
        $this->assertSame(['sent', 'log', true], [$message->status, $message->provider, $message->test_mode]);
    }

    public function test_only_allow_listed_recipients_are_handed_to_a_driver_unless_all_are_allowed(): void
    {
        config(['reminders.allow_all_recipients' => false, 'reminders.allow_list' => ['0917 123 4502', 'JUAN1@example.com']]);
        $allow = app(AllowList::class);
        $this->assertTrue($allow->permits('sms', '639171234502'));
        $this->assertFalse($allow->permits('sms', '639171234501'));
        $this->assertTrue($allow->permits('email', 'juan1@example.com'));

        $this->at('2026-09-15 07:00');
        $first = $this->loanDue('2026-09-15');
        $second = $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertSame('skipped', $this->item($first, 'Due today')->status);
        $this->assertSame('not_allowed', $this->item($first, 'Due today')->reason_code);
        $this->assertSame('sent', $this->item($first, 'Due today', 'email')->status);
        $this->assertSame('sent', $this->item($second, 'Due today')->status);
        $this->assertSame('skipped', $this->item($second, 'Due today', 'email')->status);
    }

    public function test_provider_credentials_are_encrypted_and_never_reach_the_audit_trail(): void
    {
        $settings = ReminderSetting::current();
        $settings->update(['sms_api_key' => 'key-123456', 'sms_api_secret' => 'secret-abcdef', 'email_api_key' => 'mail-key-999', 'sms_sender_id' => 'BINHS']);

        $stored = DB::table('reminder_settings')->first();
        $this->assertNotSame('key-123456', $stored->sms_api_key);
        $this->assertSame('key-123456', $settings->fresh()->sms_api_key);
        $this->assertArrayNotHasKey('sms_api_key', $settings->fresh()->toArray());

        $audit = AuditLog::query()->where('auditable_type', ReminderSetting::class)->latest('id')->firstOrFail();
        $this->assertSame('BINHS', $audit->new_values['sms_sender_id']);
        $json = json_encode([$audit->old_values, $audit->new_values]);
        foreach (['key-123456', 'secret-abcdef', 'mail-key-999', 'sms_api_key', 'sms_api_secret', 'email_api_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_reminder_events_are_append_only(): void
    {
        $event = ReminderEvent::query()->create(['kind' => 'scheduled', 'detail' => 'x']);

        $this->expectException(LogicException::class);
        $event->update(['detail' => 'y']);
    }

    public function test_reminder_events_cannot_be_deleted(): void
    {
        $event = ReminderEvent::query()->create(['kind' => 'scheduled', 'detail' => 'x']);

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_redact_messages_blanks_text_two_years_after_the_loan_closed_and_dry_run_writes_nothing(): void
    {
        $this->at('2026-09-15 07:00');
        $closed = $this->loanDue('2026-09-15');
        $recent = $this->loanDue('2026-09-15');
        $open = $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();
        $this->assertSame(6, ReminderMessage::query()->whereNotNull('body')->count());

        DB::table('loans')->whereIn('id', [$closed->id, $recent->id])->update(['status' => 'completed']);
        $this->at('2028-09-16 04:15');
        DB::table('loans')->where('id', $closed->id)->update(['updated_at' => now()->subYears(2)->subDay()]);
        DB::table('loans')->where('id', $recent->id)->update(['updated_at' => now()->subYears(2)->addDay()]);
        DB::table('loans')->where('id', $open->id)->update(['updated_at' => now()->subYears(3)]);
        $before = DB::table('reminder_messages')->orderBy('id')->get()->toJson();

        $this->artisan('reminders:redact-messages --dry-run')->expectsOutputToContain('Would redact 2 message(s)')->assertSuccessful();
        $this->assertSame($before, DB::table('reminder_messages')->orderBy('id')->get()->toJson());

        $this->artisan('reminders:redact-messages')->expectsOutputToContain('Redacted 2 message(s)')->assertSuccessful();
        $redacted = ReminderMessage::query()->where('loan_id', $closed->id)->get();
        $this->assertCount(2, $redacted);
        foreach ($redacted as $message) {
            $this->assertNull($message->body);
            $this->assertNull($message->subject);
            $this->assertNull($message->recipient);
            $this->assertNotNull($message->redacted_at);
            $this->assertNotNull($message->recipient_masked);
        }
        $this->assertSame(4, ReminderMessage::query()->whereNotNull('body')->count());

        $this->artisan('reminders:redact-messages')->expectsOutputToContain('Redacted 0 message(s)')->assertSuccessful();
    }

    public function test_the_commands_are_scheduled_in_manila_time_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event) => trim(preg_replace('/^.*artisan[\'"]?\s+/', '', $event->command)));

        $plan = $events->get('reminders:plan');
        $this->assertSame('30 * * * *', $plan->expression);
        $this->assertSame('Asia/Manila', $plan->timezone);
        $this->assertTrue($plan->withoutOverlapping);
        $this->assertSame(30, $plan->expiresAt);

        $dispatch = $events->get('reminders:dispatch');
        $this->assertSame('* * * * *', $dispatch->expression);
        $this->assertTrue($dispatch->runInBackground);
        $this->assertTrue($dispatch->withoutOverlapping);
        $this->assertSame(10, $dispatch->expiresAt);

        $redact = $events->get('reminders:redact-messages');
        $this->assertSame('15 4 * * *', $redact->expression);
        $this->assertSame('Asia/Manila', $redact->timezone);
    }
}
