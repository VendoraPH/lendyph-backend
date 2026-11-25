<?php

use App\Models\Borrower;
use App\Models\User;
use App\Services\LoanOrigination\Intake\ApplicantSms;
use App\Services\LoanOrigination\Intake\TestCodes;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\Drivers\OutgoingReminder;
use App\Services\Reminders\Drivers\ReminderDriver;
use Illuminate\Support\Carbon;
use Tests\Feature\Phase6\LoanOrigination\PublicIntake\PublicApplyHelpers;
use Tests\TestCase;

/*
 * Finish run, round 4: staging test codes for staff numbers. With the
 * test-code mode on, a code asked for on a test link is shown on screen for
 * the mobile number on an active staff account of the deployment, as for a
 * number on the reminders allow-list. Real links, other numbers, the mode off
 * and production are unchanged, and a staff account's number never widens who
 * a code is sent to.
 */
uses(TestCase::class, PublicApplyHelpers::class);

/*
 * The daily code caps count from Manila midnight and some tests travel past
 * the one-a-minute code limit, so each test starts at mid-day Manila.
 */
beforeEach(fn () => $this->travelTo(now('Asia/Manila')->setTime(12, 0)));

afterEach(fn () => Carbon::setTestNow());

/** A driver that reaches someone, as a real provider would, and records what it sends. */
function loStaffCodesProvider(): ReminderDriver
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

function loStaffCodesUseProvider(ReminderDriver $driver): void
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

it('shows an active staff account\'s number its code on a test link, sends nothing, and the code opens an application', function () {
    $link = $this->enableIntake();
    loStaffCodesUseProvider($provider = loStaffCodesProvider());
    User::factory()->create(['mobile_number' => '09175550001']);

    $data = $this->requestCode($link, '09175550001')->assertOk()->json('data');

    expect($data['test_mode']['notice'])->toBe('Test mode: not sent')
        ->and($data['test_mode']['code'])->toMatch('/^\d{6}$/')
        ->and($data['delivered'])->toBeFalse()
        ->and($data['destination'])->toBe('63917*****01')
        ->and($provider->sent)->toBe([]);

    $this->verifyCode($link, $data['test_mode']['code'], '09175550001')->assertOk()
        ->assertJsonPath('data.application.status', 'draft');
});

it('stops showing a staff number its code once the account is deactivated', function () {
    $link = $this->enableIntake();
    $staff = User::factory()->create(['mobile_number' => '09175550002']);

    $this->requestCode($link, '09175550002')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');

    $staff->forceFill(['status' => 'inactive'])->save();
    $this->travel(61)->seconds();

    $data = $this->requestCode($link, '09175550002')->assertOk()->json('data');
    expect($data)->not->toHaveKey('test_mode')
        ->and($data['delivered'])->toBeFalse();
});

it('gives a member\'s number no code, unless it is also on an active staff account', function () {
    $link = $this->enableIntake();
    Borrower::factory()->create(['contact_number' => '09175550003']);

    expect($this->requestCode($link, '09175550003')->assertOk()->json('data'))->not->toHaveKey('test_mode');

    User::factory()->create(['mobile_number' => '09175550003']);
    $this->travel(61)->seconds();

    $this->requestCode($link, '09175550003')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
});

it('gives a staff number nothing on a real link, even where a provider could send', function () {
    $test = $this->enableIntake();
    $real = $this->enableIntake(['name' => 'Live link', 'is_test' => false]);
    loStaffCodesUseProvider($provider = loStaffCodesProvider());
    User::factory()->create(['mobile_number' => '09175550004']);

    $data = $this->requestCode($real, '09175550004')->assertOk()->json('data');
    expect($data)->not->toHaveKey('test_mode')
        ->and($data['delivered'])->toBeFalse()
        ->and($provider->sent)->toBe([]);

    $this->travel(61)->seconds();
    $this->requestCode($test, '09175550004')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
    expect($provider->sent)->toBe([]);
});

it('gives a staff number nothing with test codes off, or in production', function () {
    $link = $this->enableIntake();
    User::factory()->create(['mobile_number' => '09175550005']);
    $testCodes = app(TestCodes::class);

    expect($testCodes->serves($link, '09175550005'))->toBeTrue();

    config(['loan_origination_intake.otp.test_codes' => false]);
    expect($testCodes->serves($link, '09175550005'))->toBeFalse();
    $this->requestCode($link, '09175550005')->assertStatus(409)
        ->assertJsonPath('message', 'Online applications open once text messages can be sent.');

    config(['loan_origination_intake.otp.test_codes' => true]);
    $app = app();
    $previous = $app['env'];

    try {
        $app['env'] = 'production';
        expect($testCodes->serves($link, '09175550005'))->toBeFalse();
    } finally {
        $app['env'] = $previous;
    }
});

it('keeps the allow-list: a listed number with no staff account still gets its code, beside a staff account\'s', function () {
    $link = $this->enableIntake();
    User::factory()->create(['mobile_number' => '09175550006']);

    expect(User::query()->where('mobile_number', $this->staffMobile)->exists())->toBeFalse();
    $this->requestCode($link)->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
    $this->requestCode($link, '09175550006')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
});

it('matches a staff number however either side writes it', function (string $stored, string $typed) {
    $link = $this->enableIntake();
    User::factory()->create(['mobile_number' => $stored]);

    $this->requestCode($link, $typed)->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
})->with([
    'spaces, typed with +63 and spaces' => ['0917 555 0007', '+63 917 555 0007'],
    '+63 with spaces, typed local' => ['+63 917 555 0007', '09175550007'],
    'brackets and dashes, typed 63' => ['(0917) 555-0007', '639175550007'],
    'no leading zero, typed with dashes' => ['9175550007', '0917-555-0007'],
    '63 without the plus, typed without the zero' => ['639175550007', '9175550007'],
]);

it('never takes a number that only ends in the same digits for a staff number', function (string $stored) {
    $link = $this->enableIntake();
    User::factory()->create(['mobile_number' => $stored]);

    expect($this->requestCode($link, '09175550008')->assertOk()->json('data'))->not->toHaveKey('test_mode');

    User::factory()->create(['mobile_number' => '0917 555 0008']);
    $this->travel(61)->seconds();

    $this->requestCode($link, '09175550008')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
})->with([
    'a foreign number' => ['+1 917 555 0008'],
    'an international prefix' => ['0019175550008'],
    'words around it' => ['ext 09175550008'],
]);
