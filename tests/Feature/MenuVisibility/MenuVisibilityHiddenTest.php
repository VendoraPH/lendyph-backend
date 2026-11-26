<?php

use App\Models\MenuVisibilitySwitch;
use App\Services\MenuVisibility\MenuVisibility;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

it('saves one at a time: a save waits for the one in progress, then refuses in words', function () {
    $held = Cache::lock(MenuVisibility::SAVE_LOCK, 30);
    expect($held->get())->toBeTrue();

    try {
        $this->hideModules(['gcash']);
        $this->fail('The save went ahead while another held the lock.');
    } catch (HttpException $refused) {
        expect($refused->getStatusCode())->toBe(409)
            ->and($refused->getMessage())->toBe('Another change to Menu Visibility is being saved. Try again in a moment.');
    } finally {
        $held->release();
    }

    expect(MenuVisibilitySwitch::query()->exists())->toBeFalse();

    $this->hideModules(['gcash']);
    expect(app(MenuVisibility::class)->hidden())->toBe(['gcash'])
        ->and(Cache::get(MenuVisibility::CACHE_KEY))->toBe(['gcash']);
});
