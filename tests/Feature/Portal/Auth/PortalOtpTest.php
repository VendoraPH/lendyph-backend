<?php

namespace Tests\Feature\Portal\Auth;

use App\Models\Borrower;
use App\Models\PortalOtpChallenge;
use App\Models\PortalSetting;
use App\Models\PortalTermsVersion;
use App\Services\Portal\PortalSettings;
use App\Services\Reminders\Drivers\FakeDriver;
use App\Services\Reminders\Drivers\OutgoingReminder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The one-time password step (portal design §1, §8): built, off by default,
 * refused while SMS is log-only, and never sent to a number the allow-list
 * doesn't permit. Nothing here reaches a real member.
 */
class PortalOtpTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    private const MOBILE = '09171234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return array{0: Borrower, 1: string} */
    private function invitedMember(): array
    {
        $member = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'birthdate' => '1991-07-04',
            'contact_number' => self::MOBILE,
            'status' => 'active',
        ]);

        $code = (string) $this->actingAs($this->admin)
            ->postJson("/api/portal-admin/members/{$member->id}/invite", ['identity_checked_as' => 'Passport'])
            ->assertCreated()
            ->json('data.code');

        return [$member, $code];
    }

    private function verify(Borrower $member, string $code): array
    {
        return $this->portalJson('POST', '/api/portal/auth/codes/verify', [
            'member_number' => $member->fresh()->membership_number ?: $member->borrower_code,
            'code' => $code,
            'birthdate' => '1991-07-04',
        ])->assertOk()->json('data');
    }

    public function test_otp_is_off_by_default_and_cannot_be_turned_on_while_sms_is_log_only(): void
    {
        $this->assertFalse(app(PortalSettings::class)->current()->otp_required);

        [$member, $code] = $this->invitedMember();
        $this->assertFalse($this->verify($member, $code)['otp_required']);

        $this->actingAs($this->admin)->getJson('/api/portal-admin/settings')
            ->assertOk()
            ->assertJsonPath('data.otp_required', false)
            ->assertJsonPath('data.otp_blocked_reason', PortalSettings::OTP_BLOCKED);

        $this->actingAs($this->admin)->putJson('/api/portal-admin/settings', ['otp_required' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.otp_required.0', PortalSettings::OTP_BLOCKED);

        config(['reminders.drivers.sms' => 'fake']);
        $this->actingAs($this->admin)->putJson('/api/portal-admin/settings', ['otp_required' => true])->assertStatus(422);
        $this->assertFalse(app(PortalSettings::class)->current()->otp_required);
    }

    public function test_when_otp_is_on_the_fake_driver_gets_the_code_and_complete_waits_for_it(): void
    {
        config(['reminders.drivers.sms' => 'fake', 'reminders.allow_list' => [self::MOBILE]]);
        $fake = app(FakeDriver::class);
        $this->app->instance(FakeDriver::class, $fake);
        PortalSetting::query()->update(['otp_required' => true]);

        [$member, $code] = $this->invitedMember();
        $verified = $this->verify($member, $code);

        $this->assertTrue($verified['otp_required']);
        $this->assertSame('63917*****67', $verified['sent_to']);

        $sent = $fake->sentTo('sms');
        $this->assertCount(1, $sent);
        /** @var OutgoingReminder $message */
        $message = $sent[0];
        $this->assertSame(0, $message->queueItemId);
        $this->assertSame((int) PortalOtpChallenge::query()->value('id'), $message->messageId);
        $this->assertMatchesRegularExpression('/\b(\d{6})\b/', $message->body);
        preg_match('/\b(\d{6})\b/', $message->body, $match);
        $otp = $match[1];
        $this->assertNotSame($otp, PortalOtpChallenge::query()->value('code_hash'));

        $step = $verified['step_token'];
        $completeBody = [
            'step_token' => $step,
            'password' => 'Brand-new-pass-42',
            'password_confirmation' => 'Brand-new-pass-42',
            'accepted_terms_version' => PortalTermsVersion::current()->version,
        ];

        $this->portalJson('POST', '/api/portal/auth/codes/complete', $completeBody)->assertStatus(422)->assertJsonValidationErrors('otp');

        $wrong = $otp === '000000' ? '111111' : '000000';
        $this->portalJson('POST', '/api/portal/auth/codes/otp', ['step_token' => $step, 'otp' => $wrong])->assertStatus(422)->assertJsonValidationErrors('otp');
        $this->portalJson('POST', '/api/portal/auth/codes/otp', ['step_token' => $step, 'otp' => $otp])->assertOk();

        $this->portalJson('POST', '/api/portal/auth/codes/complete', $completeBody)->assertOk();
    }

    public function test_a_number_outside_the_allow_list_is_never_sent_to(): void
    {
        config(['reminders.drivers.sms' => 'fake', 'reminders.allow_list' => ['09998887777']]);
        $fake = app(FakeDriver::class);
        $this->app->instance(FakeDriver::class, $fake);
        PortalSetting::query()->update(['otp_required' => true]);

        [$member, $code] = $this->invitedMember();
        $this->verify($member, $code);

        $this->assertCount(0, $fake->sentTo('sms'));
    }

    public function test_the_log_driver_never_logs_the_code(): void
    {
        config(['reminders.drivers.sms' => 'log', 'reminders.allow_list' => [self::MOBILE]]);
        PortalSetting::query()->update(['otp_required' => true]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        [$member, $code] = $this->invitedMember();
        $this->verify($member, $code);

        $this->assertNotEmpty($logged, 'The log driver logged the hand-over.');
        foreach ($logged as $line) {
            $this->assertDoesNotMatchRegularExpression('/(?<!\d)\d{6}(?!\d)/', $line, 'A log line holds a six-digit code: '.$line);
            $this->assertStringContainsString('63917*****67', $line);
        }
    }
}
