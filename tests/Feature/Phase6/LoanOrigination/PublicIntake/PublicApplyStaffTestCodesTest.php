<?php

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanOrigination\Intake\ApplicantSms;
use App\Services\LoanOrigination\Intake\TestCodes;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\Drivers\OutgoingReminder;
use App\Services\Reminders\Drivers\ReminderDriver;
use App\Support\PhilippineMobile;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Phase6\LoanOrigination\PublicIntake\PublicApplyHelpers;
use Tests\TestCase;

/*
 * Finish run, round 4: staging test codes for staff numbers. With the
 * test-code mode on, a code asked for on an open test link is shown on screen
 * for the mobile number on an active staff account that works on loan
 * origination (`loan_origination:view`; super_admin always), as for a number
 * on the reminders allow-list. Real links, closed links, other numbers, the
 * mode off and production are unchanged, a staff account's number never
 * widens who a code is sent to, and tracking opens only applications made on
 * a link of the same kind (test or real).
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

/** A role that is switched on, or off, carrying $permissions. */
function loStaffCodesRole(string $name, array $permissions, bool $active = true): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web', 'is_system' => false, 'is_active' => $active]);
    $role->syncPermissions($permissions);

    return $role;
}

/** A staff account that works on loan origination: an active role holding `loan_origination:view`. */
function loStaffCodesStaff(?string $mobile): User
{
    $role = Role::query()->where('name', 'origination_desk')->first()
        ?? loStaffCodesRole('origination_desk', ['loan_origination:view']);

    $user = User::factory()->create(['mobile_number' => $mobile]);
    $user->assignRole($role);

    return $user;
}

/** A submitted application on $link for $mobile, as the intake leaves one. */
function loStaffCodesSubmitted(OriginationLink $link, string $mobile, string $reference): void
{
    $application = OriginationApplication::query()->create([
        'link_id' => $link->id, 'branch_id' => $link->branch_id, 'mobile' => PhilippineMobile::normalise($mobile),
        'mobile_hash' => OriginationApplication::mobileHash($mobile), 'is_test' => $link->is_test,
        'reference_number' => $reference, 'started_at' => now(), 'submitted_at' => now(),
    ]);

    OriginationApplication::withinTransition(function () use ($application): void {
        $application->status = 'submitted';
        $application->save();
    });
}

it('shows an origination staff number its code on a test link, sends nothing, and the code opens an application', function () {
    $link = $this->enableIntake();
    loStaffCodesUseProvider($provider = loStaffCodesProvider());
    loStaffCodesStaff('09175550001');

    $data = $this->requestCode($link, '09175550001')->assertOk()->json('data');

    expect($data['test_mode']['notice'])->toBe('Test mode: not sent')
        ->and($data['test_mode']['code'])->toMatch('/^\d{6}$/')
        ->and($data['delivered'])->toBeFalse()
        ->and($data['destination'])->toBe('63917*****01')
        ->and($provider->sent)->toBe([]);

    $this->verifyCode($link, $data['test_mode']['code'], '09175550001')->assertOk()
        ->assertJsonPath('data.application.status', 'draft');
});

it('shows codes to admin, super_admin with no permission rows, a role holding loan_origination:view and a direct grant', function () {
    $link = $this->enableIntake();
    User::factory()->create(['mobile_number' => '09175550021'])->assignRole('admin');
    User::factory()->create(['mobile_number' => '09175550022'])->assignRole('super_admin');
    loStaffCodesStaff('09175550023');
    User::factory()->create(['mobile_number' => '09175550024'])->givePermissionTo('loan_origination:view');

    // super_admin passes every check by its role (Gate::before), even with no permission rows.
    DB::table('role_has_permissions')->where('role_id', Role::query()->where('name', 'super_admin')->value('id'))->delete();

    foreach (['09175550021', '09175550022', '09175550023', '09175550024'] as $mobile) {
        $this->requestCode($link, $mobile)->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
    }
});

it('gives nothing to a staff account that doesn\'t work on loan origination', function () {
    $link = $this->enableIntake();
    Borrower::factory()->create(['contact_number' => '09175550011']);

    // A teller sets their own mobile to a member's number.
    $teller = User::factory()->create();
    $teller->assignRole('cashier');
    $this->actingAs($teller)->patchJson('/api/auth/me', ['mobile_number' => '09175550011'])->assertOk();

    // Loan origination through a role that is switched off grants nothing.
    User::factory()->create(['mobile_number' => '09175550012'])
        ->assignRole(loStaffCodesRole('origination_paused', ['loan_origination:view'], active: false));

    foreach (['09175550011', '09175550012'] as $mobile) {
        expect($this->requestCode($link, $mobile)->assertOk()->json('data'))->not->toHaveKey('test_mode');
    }
});

it('stops showing a staff number its code once the account is deactivated', function () {
    $link = $this->enableIntake();
    $staff = loStaffCodesStaff('09175550002');

    $this->requestCode($link, '09175550002')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');

    $staff->forceFill(['status' => 'inactive'])->save();
    $this->travel(61)->seconds();

    $data = $this->requestCode($link, '09175550002')->assertOk()->json('data');
    expect($data)->not->toHaveKey('test_mode')
        ->and($data['delivered'])->toBeFalse();
});

it('gives a member\'s number no code, unless it is also an origination staff number', function () {
    $link = $this->enableIntake();
    Borrower::factory()->create(['contact_number' => '09175550003']);

    expect($this->requestCode($link, '09175550003')->assertOk()->json('data'))->not->toHaveKey('test_mode');

    loStaffCodesStaff('09175550003');
    $this->travel(61)->seconds();

    $this->requestCode($link, '09175550003')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
});

it('gives a staff number nothing on a real link, even where a provider could send', function () {
    $test = $this->enableIntake();
    $real = $this->enableIntake(['name' => 'Live link', 'is_test' => false]);
    loStaffCodesUseProvider($provider = loStaffCodesProvider());
    loStaffCodesStaff('09175550004');

    $data = $this->requestCode($real, '09175550004')->assertOk()->json('data');
    expect($data)->not->toHaveKey('test_mode')
        ->and($data['delivered'])->toBeFalse()
        ->and($provider->sent)->toBe([]);

    $this->travel(61)->seconds();
    $this->requestCode($test, '09175550004')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
    expect($provider->sent)->toBe([]);
});

it('shows no test codes on a closed or expired test link', function (Closure $close) {
    $link = $this->enableIntake();
    loStaffCodesStaff('09175550015');
    $close($link);
    $testCodes = app(TestCodes::class);

    expect($testCodes->enabledOn($link))->toBeFalse()
        ->and($testCodes->serves($link, '09175550015'))->toBeFalse()
        ->and($testCodes->serves($link, $this->staffMobile))->toBeFalse();

    $this->getJson("/api/public-apply/{$link->token}")->assertJsonPath('data.otp_channels', []);
    $track = $this->requestCode($link, '09175550015', 'track', ['reference_number' => 'OA-2026-000099'])->assertStatus(409);
    expect($track->json())->not->toHaveKey('data');
})->with([
    'turned off' => [fn (OriginationLink $link) => $link->forceFill(['active' => false])->save()],
    'expired' => [fn (OriginationLink $link) => $link->forceFill(['expires_at' => now()->subMinute()])->save()],
]);

it('tracks from a test link only applications made on test links, and from a real link only real ones', function () {
    $test = $this->enableIntake();
    $real = $this->enableIntake(['name' => 'Live link', 'is_test' => false]);
    loStaffCodesUseProvider($provider = loStaffCodesProvider());
    loStaffCodesSubmitted($real, $this->staffMobile, 'OA-2026-000101');
    loStaffCodesSubmitted($test, $this->staffMobile, 'OA-2026-000102');
    $track = fn (string $reference) => ['reference_number' => $reference];

    // From the test link, a shown code: the real application isn't found, the test one is.
    $code = $this->requestCode($test, null, 'track', $track('OA-2026-000101'))->assertOk()->json('data.test_mode.code');
    $this->verifyCode($test, $code, null, 'track', $track('OA-2026-000101'))->assertNotFound()
        ->assertJsonPath('message', "We couldn't find an application with that reference for this number.");

    $this->travel(61)->seconds();
    $code = $this->requestCode($test, null, 'track', $track('OA-2026-000102'))->assertOk()->json('data.test_mode.code');
    $this->verifyCode($test, $code, null, 'track', $track('OA-2026-000102'))->assertOk();

    // From the real link, a code sent by the provider: the reverse.
    $sentCode = function () use ($provider): string {
        preg_match('/code is (\d{6})/', end($provider->sent)->body, $match);

        return $match[1];
    };

    $this->travel(61)->seconds();
    $this->requestCode($real, null, 'track', $track('OA-2026-000102'))->assertOk()->assertJsonPath('data.delivered', true);
    $this->verifyCode($real, $sentCode(), null, 'track', $track('OA-2026-000102'))->assertNotFound();

    $this->travel(61)->seconds();
    $this->requestCode($real, null, 'track', $track('OA-2026-000101'))->assertOk()->assertJsonPath('data.delivered', true);
    $this->verifyCode($real, $sentCode(), null, 'track', $track('OA-2026-000101'))->assertOk();
});

it('logs nothing, audits nothing, reads staff accounts in one query and never matches a blank or missing number', function () {
    $link = $this->enableIntake();
    loStaffCodesStaff(null);
    loStaffCodesStaff('');
    loStaffCodesStaff('09175550013');
    $before = AuditLog::query()->max('id') ?? 0;
    $usersQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$usersQueries): void {
        if (preg_match('/\bfrom `users`/', $query->sql) === 1) {
            $usersQueries++;
        }
    });
    Log::spy();

    $this->requestCode($link, '09175550013')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');

    expect($usersQueries)->toBe(1)
        ->and(AuditLog::query()->where('id', '>', $before)->exists())->toBeFalse();

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'channel', 'stack'] as $method) {
        Log::shouldNotHaveReceived($method);
    }

    expect($this->requestCode($link, '09175550014')->assertOk()->json('data'))->not->toHaveKey('test_mode');
});

it('gives a staff number nothing with test codes off, or in production', function () {
    $link = $this->enableIntake();
    loStaffCodesStaff('09175550005');
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
    loStaffCodesStaff('09175550006');

    expect(User::query()->where('mobile_number', $this->staffMobile)->exists())->toBeFalse();
    $this->requestCode($link)->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
    $this->requestCode($link, '09175550006')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
});

it('matches a staff number however either side writes it', function (string $stored, string $typed) {
    $link = $this->enableIntake();
    loStaffCodesStaff($stored);

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
    loStaffCodesStaff($stored);

    expect($this->requestCode($link, '09175550008')->assertOk()->json('data'))->not->toHaveKey('test_mode');

    loStaffCodesStaff('0917 555 0008');
    $this->travel(61)->seconds();

    $this->requestCode($link, '09175550008')->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
})->with([
    'a foreign number' => ['+1 917 555 0008'],
    'an international prefix' => ['0019175550008'],
    'words around it' => ['ext 09175550008'],
]);
