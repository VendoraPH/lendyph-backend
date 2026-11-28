<?php

use App\Http\Middleware\BlockHiddenModules;
use App\Http\Middleware\EnsureCicEnabled;
use App\Models\CicCapture;
use App\Models\CicPublicKey;
use App\Models\CicSetting;
use App\Models\User;
use App\Providers\CicServiceProvider;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicSwitch;
use App\Services\MenuVisibility\MenuModules;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\MenuVisibility\MenuVisibilityTestHelpers;
use Tests\TestCase;

uses(TestCase::class, MenuVisibilityTestHelpers::class);

/*
 * The CIC route plumbing: every /cic route answers 404 while CIC_ENABLED is
 * off or Menu Visibility hides `cic`; each needs branches:view_all plus its
 * cic:* permission, refused in words that never name a key; GET /cic/status
 * proves it; and production refuses to boot with test captures on.
 */

/** @return list<RoutingRoute> */
function cicRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/cic/') || $route->uri() === 'api/cic',
    ));
}

function cicStaff(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

beforeEach(function () {
    config(['cic.enabled' => true, 'cic.test_captures' => false]);
});

it('answers GET /cic/status to a viewer with head-office access', function () {
    CicSetting::factory()->create(['provider_code' => 'ABCD1234']);
    $key = CicPublicKey::factory()->active()->create();
    CicCapture::factory()->monthEnd('2026-10-31')->complete()->create();
    $latest = CicCapture::factory()->monthEnd('2026-11-30')->complete()->create();
    CicCapture::factory()->monthEnd('2026-12-31')->create();

    $this->actingAs(cicStaff([CicAccess::VIEW, 'branches:view_all']))
        ->getJson('/api/cic/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.format_version', '1.0')
        ->assertJsonPath('data.provider_code_set', true)
        ->assertJsonPath('data.active_key.fingerprint', $key->fingerprint)
        ->assertJsonPath('data.active_key.expired', false)
        ->assertJsonPath('data.last_capture.id', $latest->id)
        ->assertJsonPath('data.last_capture.reference_date', '2026-11-30')
        ->assertJsonPath('data.last_capture.kind', 'month_end')
        ->assertJsonPath('data.test_captures', false);
});

it('reads a new deployment as not set up', function () {
    config(['cic.test_captures' => true]);

    $this->actingAs(cicStaff([CicAccess::VIEW, 'branches:view_all']))
        ->getJson('/api/cic/status')
        ->assertOk()
        ->assertJsonPath('data.provider_code_set', false)
        ->assertJsonPath('data.active_key', null)
        ->assertJsonPath('data.last_capture', null)
        ->assertJsonPath('data.test_captures', true);

    expect(CicSetting::query()->count())->toBe(1)
        ->and(CicSetting::current()->format_version)->toBe('1.0');
});

it('answers 404 on every CIC route while CIC is off, before any id is looked up', function () {
    config(['cic.enabled' => false]);

    $this->actingAs(User::where('username', 'super_admin')->firstOrFail())
        ->getJson('/api/cic/status')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);

    foreach (cicRoutes() as $route) {
        $middleware = Route::gatherRouteMiddleware($route);

        expect($middleware)->toContain(EnsureCicEnabled::class);

        if (in_array(SubstituteBindings::class, $middleware, true)) {
            expect(array_search(EnsureCicEnabled::class, $middleware, true))
                ->toBeLessThan(array_search(SubstituteBindings::class, $middleware, true), "{$route->uri()} looks ids up before the CIC switch.");
        }
    }
});

it('needs a signed-in user even while CIC is off', function () {
    config(['cic.enabled' => false]);

    $this->getJson('/api/cic/status')->assertUnauthorized();
});

it('answers 404 while Menu Visibility hides CIC Reporting, and as before once shown', function () {
    $token = $this->tokenFor($this->superAdmin());

    $this->asToken($token, 'GET', '/api/cic/status')->assertOk();

    $this->hideModules([CicSwitch::MENU_KEY]);
    expect(app(CicSwitch::class)->hidden())->toBeTrue();
    $this->asToken($token, 'GET', '/api/cic/status')->assertNotFound()->assertExactJson(['message' => 'Not found.']);

    $this->hideModules([]);
    $this->asToken($token, 'GET', '/api/cic/status')->assertOk();
});

it('registers CIC Reporting in Menu Visibility, owning /cic and its settings page', function () {
    expect(MenuModules::has('cic'))->toBeTrue()
        ->and(MenuModules::label('cic'))->toBe('CIC Reporting')
        ->and(MenuModules::parentOf('cic'))->toBeNull()
        ->and(MenuModules::forPage('/cic/submissions'))->toBe('cic')
        ->and(MenuModules::forPage('/settings/cic'))->toBe('cic')
        ->and(MenuModules::blockingModule('GET', 'api/cic/status', ['cic']))->toBe('cic')
        ->and(MenuModules::blockingModule('GET', 'api/cic/status', ['cda']))->toBeNull()
        ->and(MenuModules::blockingModule('GET', 'api/cda/settings', ['cic']))->toBeNull();

    foreach (cicRoutes() as $route) {
        expect(MenuModules::blockingModule('GET', $route->uri(), ['cic']))->toBe('cic', $route->uri());
    }

    expect(in_array(BlockHiddenModules::class, Route::gatherRouteMiddleware(cicRoutes()[0]), true))->toBeTrue();
});

it('refuses a caller without head-office access, in words that name no permission', function () {
    $response = $this->actingAs(cicStaff([CicAccess::VIEW]))->getJson('/api/cic/status')->assertForbidden();

    expect($response->json('message'))->toBe(CicAccess::VIEW_ALL_REFUSAL)
        ->and($response->json('message'))->not->toContain('cic:')
        ->and($response->json('message'))->not->toContain('branches:');
});

it('refuses a caller without the CIC permission, in words that name no permission', function () {
    $response = $this->actingAs(cicStaff(['branches:view_all', CicAccess::SETTINGS]))->getJson('/api/cic/status')->assertForbidden();

    expect($response->json('message'))->toBe('You don\'t have access to view CIC reporting.')
        ->and($response->json('message'))->not->toContain(':');
});

it('reads every permission in words', function () {
    foreach (CicAccess::PERMISSIONS as $permission) {
        expect(CicAccess::refusal($permission))->not->toContain(':');
    }

    expect(CicAccess::refusal([CicAccess::PREPARE, CicAccess::SUBMIT]))->toBe('You don\'t have access to prepare CIC files or submit CIC files.')
        ->and(CicAccess::refusal('cic:unknown'))->toBe('You don\'t have access to use this part of CIC reporting.');
});

it('tells who may see members\' personal data', function () {
    $access = app(CicAccess::class);

    expect($access->seesPersonalData(cicStaff([CicAccess::VIEW, 'branches:view_all', 'borrowers:view'])))->toBeTrue()
        ->and($access->seesPersonalData(cicStaff([CicAccess::VIEW, 'branches:view_all'])))->toBeFalse();
});

it('refuses to boot in production with test captures on', function () {
    $app = app();
    $previous = $app['env'];
    config(['cic.test_captures' => true]);

    try {
        $app['env'] = 'production';
        expect(fn () => (new CicServiceProvider($app))->boot())
            ->toThrow(RuntimeException::class, 'CIC_TEST_CAPTURES');

        config(['cic.test_captures' => false]);
        (new CicServiceProvider($app))->boot();

        $app['env'] = 'staging';
        config(['cic.test_captures' => true]);
        (new CicServiceProvider($app))->boot();
    } finally {
        $app['env'] = $previous;
    }

    expect(true)->toBeTrue();
});

it('ships off, with GnuPG at /usr/bin/gpg and the 2016 format', function () {
    $config = require base_path('config/cic.php');

    expect($config['enabled'])->toBeFalse()
        ->and($config['test_captures'])->toBeFalse()
        ->and($config['gpg_binary'])->toBe('/usr/bin/gpg')
        ->and($config['format_version'])->toBe('1.0');
});
