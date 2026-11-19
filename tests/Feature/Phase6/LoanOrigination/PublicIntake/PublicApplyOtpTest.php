<?php

use App\Models\AuditLog;
use App\Models\OriginationApplication;
use App\Models\OriginationConsent;
use App\Models\OriginationNotice;
use App\Models\OriginationProductSetting;
use App\Models\OriginationSetting;
use App\Services\LoanOrigination\Intake\ApplicantSms;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\Drivers\OutgoingReminder;
use App\Services\Reminders\Drivers\ReminderDriver;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Phase6\LoanOrigination\PublicIntake\PublicApplyHelpers;
use Tests\TestCase;

/*
 * LO-B2: the landing page, the code step and sessions (origination decisions
 * 1, 2 and 6–9). No SMS provider exists, so the only way past the code step is
 * the staging test-code mode, which serves allow-listed staff numbers only
 * and sends nothing. Codes are 6 digits, 5 minutes, 5 tries, stored hashed.
 * Sessions are random, stored hashed, tied to the link and the application,
 * and end after 30 minutes idle or 4 hours.
 */
uses(TestCase::class, PublicApplyHelpers::class);

afterEach(fn () => Carbon::setTestNow());

/** A driver that reaches someone, as a real provider would. */
function loPubRealDriver(): ReminderDriver
{
    return new class implements ReminderDriver
    {
        /** @var list<OutgoingReminder> */
        public array $sent = [];

        public function name(): string
        {
            return 'provider';
        }

        public function reachesNobody(): bool
        {
            return false;
        }

        public function send(OutgoingReminder $reminder): DriverResult
        {
            $this->sent[] = $reminder;

            return DriverResult::sent('p-1');
        }

        public function creditsRemaining(): ?int
        {
            return null;
        }
    };
}

function loPubUseDriver(ReminderDriver $driver): void
{
    app()->instance(ApplicantSms::class, new class($driver) extends ApplicantSms
    {
        public function __construct(private readonly ReminderDriver $fixed) {}

        public function driver(): ReminderDriver
        {
            return $this->fixed;
        }
    });
}

it('shows the landing page with the link, its products, the notices and the code channels', function () {
    $link = $this->enableIntake();
    $product = $this->linkProduct($link);

    $data = $this->getJson("/api/public-apply/{$link->token}")->assertOk()->json('data');

    expect($data['accepting'])->toBeTrue()
        ->and($data['closed_reason'])->toBeNull()
        ->and($data['otp_channels'])->toBe(['sms'])
        ->and($data['organization']['name'])->toBe('Binhs Coop')
        ->and($data['organization']['contact_email'])->toBe('loans@example.test')
        ->and(array_column($data['products'], 'id'))->toBe([$product->id])
        ->and($data['products'][0]['payment_frequencies'])->toBe(['monthly', 'semi_monthly'])
        ->and($data['privacy_notice'])->toBe(['version' => '2026.1', 'text' => 'Draft privacy_notice text', 'final' => false])
        ->and($data['credit_authorization'])->toBeNull()
        ->and($data['branches'])->toHaveCount(1)
        ->and($data['max_upload_mb'])->toBe(10)
        ->and($data['accepted_file_types'])->toBe(['jpg', 'jpeg', 'png', 'pdf'])
        ->and(array_column($data['documents'], 'type'))->toContain('government_id');

    expect($link->fresh()->visits)->toBe(1);
});

it('says why a link is closed', function (Closure $close, string $reason) {
    $link = $this->enableIntake();
    $close($link);

    $this->getJson("/api/public-apply/{$link->token}")->assertOk()
        ->assertJsonPath('data.accepting', false)
        ->assertJsonPath('data.closed_reason', $reason);
})->with([
    'settings off' => [fn () => OriginationSetting::current()->forceFill(['online_applications_enabled' => false])->save(), 'Online applications are closed right now. Please contact the office.'],
    'link off' => [fn ($link) => $link->forceFill(['active' => false])->save(), 'This application link has been turned off. Please contact the office.'],
    'link expired' => [fn ($link) => $link->forceFill(['expires_at' => now()->subMinute()])->save(), 'This application link has expired. Please contact the office.'],
    'no sending' => [fn () => config(['loan_origination_intake.otp.test_codes' => false]), 'Online applications open once text messages can be sent.'],
    'product not online' => [fn ($link) => OriginationProductSetting::query()->where('loan_product_id', $link->loanProducts()->value('loan_products.id'))->update(['online' => false]), 'No loan products are offered online right now.'],
]);

it('lists no code channel without a provider or the test-code mode, so nobody gets past the code step', function () {
    $link = $this->enableIntake();
    config(['loan_origination_intake.otp.test_codes' => false]);

    $this->getJson("/api/public-apply/{$link->token}")->assertJsonPath('data.otp_channels', []);
    $this->requestCode($link)->assertStatus(409)->assertJsonPath('message', 'Online applications open once text messages can be sent.');
});

it('shows an allow-listed staff number its code in test mode, and sends nothing', function () {
    $link = $this->enableIntake();
    Log::spy();

    $data = $this->requestCode($link)->assertOk()->json('data');

    expect($data['delivered'])->toBeFalse()
        ->and($data['channel'])->toBe('sms')
        ->and($data['destination'])->toBe('63917*****67')
        ->and($data['expires_in'])->toBe(300)
        ->and($data['resend_after'])->toBe(60)
        ->and($data['test_mode']['notice'])->toBe('Test mode: not sent')
        ->and($data['test_mode']['code'])->toMatch('/^\d{6}$/');

    Log::shouldNotHaveReceived('info');
    expect(DB::table('origination_otp_challenges')->value('code_hash'))->not->toContain($data['test_mode']['code']);
});

it('gives any other number no code in test mode, with the same answer otherwise', function () {
    $link = $this->enableIntake();

    $data = $this->requestCode($link, '09181112222')->assertOk()->json('data');

    expect($data)->not->toHaveKey('test_mode')
        ->and($data['delivered'])->toBeFalse()
        ->and($data['destination'])->toBe('63918*****22');
});

it('ignores allow-all-recipients for test codes: only listed numbers get one', function () {
    $link = $this->enableIntake();
    config(['reminders.allow_all_recipients' => true]);

    expect($this->requestCode($link, '09181112222')->assertOk()->json('data'))->toBeArray()->not->toHaveKey('test_mode');
});

it('sends through a provider only to an allow-listed number, never shows the code', function () {
    $link = $this->enableIntake();
    config(['loan_origination_intake.otp.test_codes' => false]);
    loPubUseDriver($driver = loPubRealDriver());

    $this->getJson("/api/public-apply/{$link->token}")->assertJsonPath('data.otp_channels', ['sms']);

    $listed = $this->requestCode($link)->assertOk()->json('data');
    $other = $this->requestCode($link, '09181112222')->assertOk()->json('data');

    expect($listed['delivered'])->toBeTrue()->and($listed)->not->toHaveKey('test_mode')
        ->and($other['delivered'])->toBeFalse()
        ->and($driver->sent)->toHaveCount(1)
        ->and($driver->sent[0]->recipient)->toBe('639171234567');
});

it('takes Philippine mobile numbers only, by SMS only', function (array $input, string $field) {
    $link = $this->enableIntake();

    $this->postJson("/api/public-apply/{$link->token}/otp", ['channel' => 'sms', 'mobile' => '09171234567', 'purpose' => 'apply', ...$input])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'landline' => [['mobile' => '0281234567'], 'mobile'],
    'foreign' => [['mobile' => '+14155550123'], 'mobile'],
    'email' => [['channel' => 'email', 'email' => 'a@example.test'], 'channel'],
    'purpose' => [['purpose' => 'admin'], 'purpose'],
    'track without reference' => [['purpose' => 'track'], 'reference_number'],
]);

it('allows one code a minute and five an hour per mobile', function () {
    $link = $this->enableIntake();

    $this->requestCode($link)->assertOk();
    $this->requestCode($link)->assertStatus(429);

    foreach (range(1, 4) as $i) {
        $this->travel(61)->seconds();
        $this->requestCode($link)->assertOk();
    }

    $this->travel(61)->seconds();
    $this->requestCode($link)->assertStatus(429);
    $this->requestCode($link, '09181112222')->assertOk();
});

it('stops sending codes once the deployment\'s daily cap is reached', function () {
    $link = $this->enableIntake();
    config(['loan_origination_intake.otp.daily_cap' => 1]);
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->requestCode($link)->assertOk();
    $this->requestCode($link, $this->secondStaffMobile)->assertStatus(429)
        ->assertJsonPath('message', 'Online applications are busy right now. Please try again tomorrow or contact the office.');
});

it('starts a draft on a verified code, records the privacy notice, and answers a session', function () {
    $link = $this->enableIntake();
    $before = AuditLog::query()->max('id') ?? 0;
    $code = $this->requestCode($link)->json('data.test_mode.code');

    $data = $this->verifyCode($link, $code)->assertOk()->json('data');

    expect($data['session_token'])->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and($data['application']['status'])->toBe('draft')
        ->and($data['application']['reference_number'])->toBeNull()
        ->and($data['application']['verified_contact']['mobile'])->toBe('63917*****67');

    $application = OriginationApplication::query()->sole();
    expect($application->link_id)->toBe($link->id)
        ->and($application->branch_id)->toBe($link->branch_id)
        ->and($application->mobile)->toBe('639171234567')
        ->and($application->mobile_hash)->toBe(OriginationApplication::mobileHash('09171234567'))
        ->and($link->fresh()->started)->toBe(1)
        ->and(DB::table('origination_sessions')->value('token_hash'))->toBe(hash('sha256', $data['session_token']));

    $consent = OriginationConsent::query()->sole();
    expect($consent->type)->toBe('privacy_notice')
        ->and($consent->stage)->toBe('verify')
        ->and($consent->version)->toBe('2026.1')
        ->and($consent->notice_id)->toBe(OriginationNotice::query()->where('type', 'privacy_notice')->value('id'));

    $audit = AuditLog::query()->where('id', '>', $before)->sole();
    expect($audit->actor_type)->toBe('applicant')
        ->and($audit->user_id)->toBeNull()
        ->and(json_encode($audit->new_values))->not->toContain('0917')->not->toContain('639171234567');
});

it('needs the current privacy notice acknowledged before anything is stored', function () {
    $link = $this->enableIntake();
    $code = $this->requestCode($link)->json('data.test_mode.code');

    $this->verifyCode($link, $code, null, 'apply', ['privacy_notice_version' => '2025.9'])
        ->assertUnprocessable()->assertJsonValidationErrors('privacy_notice_version');

    expect(OriginationApplication::query()->count())->toBe(0);
});

it('refuses a wrong code, and burns the code after five tries', function () {
    $link = $this->enableIntake();
    $code = $this->requestCode($link)->json('data.test_mode.code');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        $this->verifyCode($link, $wrong)->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    $this->verifyCode($link, $code)->assertUnprocessable()->assertJsonValidationErrors('code');
    expect(OriginationApplication::query()->count())->toBe(0);
});

it('refuses an expired code and a used one', function () {
    $link = $this->enableIntake();
    $code = $this->requestCode($link)->json('data.test_mode.code');
    $this->travel(301)->seconds();
    $this->verifyCode($link, $code)->assertUnprocessable();

    $this->travel(61)->seconds();
    $code = $this->requestCode($link)->json('data.test_mode.code');
    $this->verifyCode($link, $code)->assertOk();
    $this->verifyCode($link, $code)->assertUnprocessable();
});

it('keeps only the newest code open for a mobile', function () {
    $link = $this->enableIntake();
    $first = $this->requestCode($link)->json('data.test_mode.code');
    $this->travel(61)->seconds();
    $second = $this->requestCode($link)->json('data.test_mode.code');

    if ($first !== $second) {
        $this->verifyCode($link, $first)->assertUnprocessable();
    }
    $this->verifyCode($link, $second)->assertOk();
});

it('returns the open draft for the mobile on a second apply, never a second draft', function () {
    $link = $this->enableIntake();
    $this->startApplication($link);
    $this->travel(61)->seconds();
    $this->startApplication($link);

    expect(OriginationApplication::query()->count())->toBe(1)
        ->and($link->fresh()->started)->toBe(1);
});

it('resumes only an open draft for the mobile, and tracks only with its reference', function () {
    $link = $this->enableIntake();

    $code = $this->requestCode($link, null, 'resume')->json('data.test_mode.code');
    $this->verifyCode($link, $code, null, 'resume')->assertNotFound();

    $this->travel(61)->seconds();
    $this->startApplication($link);
    $this->travel(61)->seconds();
    $resumed = $this->startApplication($link, 'resume');
    expect($resumed)->not->toBe('');

    $this->travel(61)->seconds();
    $code = $this->requestCode($link, null, 'track', ['reference_number' => 'OA-2026-000099'])->json('data.test_mode.code');
    $this->verifyCode($link, $code, null, 'track', ['reference_number' => 'OA-2026-000099'])->assertNotFound();
});

it('refuses a missing, unknown, idle or old session', function () {
    $link = $this->enableIntake();
    $session = $this->startApplication($link);

    $this->getJson("/api/public-apply/{$link->token}/draft")->assertUnauthorized()->assertJsonPath('code', 'session_required');
    $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession('nope'))->assertUnauthorized();
    $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession($session))->assertOk();

    $this->travel(29)->minutes();
    $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession($session))->assertOk();
    $this->travel(31)->minutes();
    $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession($session))->assertUnauthorized()
        ->assertJsonPath('code', 'session_expired');

    $this->travel(61)->seconds();
    $fresh = $this->startApplication($link, 'resume');
    foreach (range(1, 9) as $i) {
        $this->travel(25)->minutes();
        $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession($fresh))->assertOk();
    }
    $this->travel(20)->minutes();
    $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession($fresh))->assertUnauthorized();
});

it('ties a session to its link', function () {
    $link = $this->enableIntake();
    $other = $this->enableIntake(['name' => 'Other link']);
    $session = $this->startApplication($link);

    $this->getJson("/api/public-apply/{$other->token}/draft", $this->applicantSession($session))->assertUnauthorized();
});

it('ends earlier sessions of the application when a new code is verified', function () {
    $link = $this->enableIntake();
    $first = $this->startApplication($link);
    $this->travel(61)->seconds();
    $this->startApplication($link, 'resume');

    $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession($first))->assertUnauthorized();
});
