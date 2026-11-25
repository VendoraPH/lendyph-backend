<?php

use App\Models\MenuVisibilitySwitch;
use App\Services\MenuVisibility\MenuVisibility;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\MenuVisibility\MenuVisibilityTestHelpers;
use Tests\TestCase;

uses(TestCase::class, MenuVisibilityTestHelpers::class);

/*
 * GET /menu-visibility/hidden (finish run round 6): the effective hidden list
 * every signed-in staff user's screens read, and the cache the middleware
 * reads it through.
 */

it('lists nothing while no menu was switched off', function () {
    $this->asToken($this->tokenFor($this->staffWithRole('viewer')), 'GET', '/api/menu-visibility/hidden')
        ->assertOk()
        ->assertExactJson(['data' => ['hidden' => []]]);
});

it('hides a menu\'s submenus with it', function () {
    $this->hideModules(['loans', 'savings']);

    $this->asToken($this->tokenFor($this->staffWithRole('viewer')), 'GET', '/api/menu-visibility/hidden')
        ->assertOk()
        ->assertExactJson(['data' => ['hidden' => ['loans', 'loans.reminders', 'loans.origination', 'savings']]]);
});

it('counts a menu with no row, or one switched back on, as shown', function () {
    MenuVisibilitySwitch::query()->create(['module_key' => 'gcash', 'hidden' => false]);
    MenuVisibilitySwitch::query()->create(['module_key' => 'cda', 'hidden' => true]);
    MenuVisibilitySwitch::query()->create(['module_key' => 'retired_module', 'hidden' => true]);
    Cache::forget(MenuVisibility::CACHE_KEY);

    expect(app(MenuVisibility::class)->hidden())->toBe(['cda']);
});

it('answers only a signed-in user', function () {
    $this->getJson('/api/menu-visibility/hidden')->assertUnauthorized();
});

it('takes a save into account at once', function () {
    $visibility = app(MenuVisibility::class);

    expect($visibility->hidden())->toBe([])
        ->and(Cache::has(MenuVisibility::CACHE_KEY))->toBeTrue();

    $this->hideModules(['gcash']);
    expect($visibility->hidden())->toBe(['gcash']);

    $this->hideModules([]);
    expect($visibility->hidden())->toBe([]);
});

it('blocks a hidden module at once after the save', function () {
    $this->actingAs($this->superAdmin());
    $this->getJson('/api/gcash/tiers')->assertOk();

    $this->hideModules(['gcash']);
    $this->getJson('/api/gcash/tiers')->assertNotFound()->assertExactJson(['message' => 'Not found.']);

    $this->hideModules([]);
    $this->getJson('/api/gcash/tiers')->assertOk();
});
