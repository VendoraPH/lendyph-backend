<?php

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\OriginationApplication;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LoanOrigination\OriginationSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
 * LO-B1: the server switches (origination decision 17) and the `applicant`
 * audit actor (decision 10). LOAN_ORIGINATION_ENABLED is off by default and
 * read through filter_var in config; the public address must be one of
 * CORS_ALLOWED_ORIGINS, exactly.
 */
uses(TestCase::class);

function loSwitch(): OriginationSwitch
{
    return app(OriginationSwitch::class);
}

it('is off by default, and reads the env switch through filter_var in config only', function () {
    $config = File::get(config_path('loan_origination.php'));

    expect($config)->toContain("'enabled' => filter_var(env('LOAN_ORIGINATION_ENABLED', false), FILTER_VALIDATE_BOOL)")
        ->and(File::get(base_path('.env.example')))->toContain('LOAN_ORIGINATION_ENABLED=false');

    expect(filter_var('false', FILTER_VALIDATE_BOOL))->toBeFalse();

    config(['loan_origination.enabled' => false]);
    expect(loSwitch()->enabled())->toBeFalse();

    config(['loan_origination.enabled' => true]);
    expect(loSwitch()->enabled())->toBeTrue();
});

it('builds the public address only from an allowed origin, ignoring a trailing slash', function () {
    config(['cors.allowed_origins' => ['https://app.example.test', 'https://apply.example.test']]);

    config(['loan_origination.public_url' => 'https://apply.example.test/']);
    expect(loSwitch()->publicUrl())->toBe('https://apply.example.test')
        ->and(loSwitch()->publicUrlProblem())->toBeNull()
        ->and(loSwitch()->linkUrl('abc'))->toBe('https://apply.example.test/apply/abc');

    config(['loan_origination.public_url' => 'https://apply.example.test.evil.test']);
    expect(loSwitch()->publicUrl())->toBeNull()
        ->and(loSwitch()->publicUrlProblem())->toBe('The public application address must be one of the addresses the server accepts (CORS_ALLOWED_ORIGINS), so links have no address yet.')
        ->and(loSwitch()->linkUrl('abc'))->toBeNull();

    config(['loan_origination.public_url' => null]);
    expect(loSwitch()->publicUrlProblem())->toBe('The public application address isn\'t set on the server yet, so links have no address.');
});

it('refuses an address with a path, a query or plain http outside local runs', function (string $url) {
    config(['cors.allowed_origins' => [$url], 'loan_origination.public_url' => $url]);

    expect(loSwitch()->publicUrl())->toBeNull();
})->with(['https://apply.example.test/path', 'https://apply.example.test?x=1', 'http://apply.example.test', 'javascript:alert(1)']);

it('writes an applicant\'s own action under the applicant actor, naming nobody', function () {
    $application = OriginationApplication::query()->create(['status' => 'draft']);

    $log = AuditLogService::log('submitted', $application, null, ['status' => 'submitted'], 'Application submitted', asApplicant: true);

    expect($log->actor_type)->toBe(AuditLogService::ACTOR_APPLICANT)
        ->and($log->user_id)->toBeNull()
        ->and($log->portal_user_id)->toBeNull();

    $request = Request::create('/');
    $request->setUserResolver(fn () => User::query()->where('username', 'super_admin')->firstOrFail());
    $resource = (new AuditLogResource(AuditLog::query()->findOrFail($log->id)))->toArray($request);

    expect($resource['actor_type'])->toBe('applicant')
        ->and($resource['actor_label'])->toBe('Applicant')
        ->and($resource['module'])->toBe('loan_origination');
});
