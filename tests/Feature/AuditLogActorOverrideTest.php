<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Naming the actor when there is no request to read one from.
 *
 * AuditLogService has always taken the user from `auth()` and the address from
 * `request()`. Both are null in a queued job or a scheduled command, so the one
 * summary row a background process writes — "12,431 borrowers and 9,008 loans
 * imported" — would name nobody and come from nowhere, which is the single row
 * in that whole operation an auditor will actually ask about.
 *
 * The two overrides are trailing and optional so every existing call site keeps
 * working untouched; the last spec here holds that line by driving a real
 * request through one of them.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('username', 'super_admin')->first();
    $this->branch = Branch::first();
});

/**
 * The importer's case exactly: nobody authenticated, no inbound request, and the
 * actor captured earlier still has to reach the row.
 */
it('records an explicitly passed user id and ip address with nobody authenticated', function () {
    expect(auth()->id())->toBeNull();

    $log = AuditLogService::log(
        action: 'csv_import_completed',
        auditable: $this->branch,
        newValues: ['borrowers' => 12431, 'loans' => 9008],
        description: 'CSV migration run #1 completed.',
        userId: $this->admin->id,
        ipAddress: '203.0.113.9',
    );

    expect($log->user_id)->toBe($this->admin->id);
    expect($log->ip_address)->toBe('203.0.113.9');

    $stored = AuditLog::findOrFail($log->id);
    expect($stored->user_id)->toBe($this->admin->id);
    expect($stored->ip_address)->toBe('203.0.113.9');
});

/**
 * The override wins over an authenticated user rather than merely filling a
 * hole. Without this, the previous spec would pass on a service that ignored
 * the argument entirely and just happened to have a null `auth()->id()`.
 */
it('prefers the explicit user id over the authenticated one', function () {
    $other = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->actingAs($this->admin);

    $log = AuditLogService::log(
        action: 'csv_import_completed',
        description: 'Queued work, requested earlier by someone else.',
        userId: $other->id,
        ipAddress: '198.51.100.4',
    );

    expect($log->user_id)->toBe($other->id)->not->toBe($this->admin->id);
    expect($log->ip_address)->toBe('198.51.100.4');
});

it('falls back to the authenticated user when no user id is passed', function () {
    $this->actingAs($this->admin);

    $log = AuditLogService::log('login', $this->admin, description: 'Default-actor probe.');

    expect($log->user_id)->toBe($this->admin->id);
});

/**
 * The address default is asserted against `request()->ip()` rather than a
 * literal, because the literal would only be testing what the test harness puts
 * in REMOTE_ADDR. What matters is that the untouched path still reads the
 * request.
 */
it('falls back to the request ip when no ip address is passed', function () {
    $this->actingAs($this->admin);

    $log = AuditLogService::log('login', $this->admin, description: 'Default-ip probe.');

    expect($log->ip_address)->toBe(request()->ip());
});

/**
 * The compatibility claim, tested rather than asserted in a comment.
 *
 * AuthController::logout is one of the twenty-odd five-argument call sites that
 * were not touched, and it runs inside a fully authenticated request — so it
 * still stamps both the actor and the address from `auth()` and `request()`,
 * exactly as before the two parameters existed.
 *
 * Driven through a real token rather than actingAs(), because logout deletes
 * the current access token and actingAs() hands it a TransientToken instead of
 * a real one.
 */
it('leaves an existing call site stamping the request actor', function () {
    $token = $this->postJson('/api/auth/login', [
        'login' => 'super_admin',
        'password' => 'password',
    ])->assertOk()->json('token');

    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

    $log = AuditLog::where('action', 'logout')->latest('id')->firstOrFail();

    expect($log->user_id)->toBe($this->admin->id);
    expect($log->ip_address)->not->toBeNull();
    expect($log->description)->toBe('User super_admin logged out');
});
