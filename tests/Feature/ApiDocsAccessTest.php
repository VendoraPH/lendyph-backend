<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * The docs routes are registered by l5-swagger outside any route group, so they
 * inherit no middleware of their own. They are gated instead via `group_options`
 * in config/l5-swagger.php. These tests pin that gate down: it has been open by
 * default on every deployment before now.
 */
it('does not serve the openapi spec when docs are disabled', function () {
    config(['l5-swagger.enabled' => false]);

    $this->get('/docs')->assertNotFound();
});

it('does not serve the swagger ui when docs are disabled', function () {
    config(['l5-swagger.enabled' => false]);

    $this->get('/api/documentation')->assertNotFound();
});

it('defaults to disabled when the deployment says nothing', function () {
    // The deny-by-default is the whole point: ten deployments share this
    // codebase, so a box that never sets the flag must publish nothing.
    expect(config('l5-swagger.enabled'))->toBeFalse();
});

it('serves the swagger ui once a deployment opts in', function () {
    config(['l5-swagger.enabled' => true]);

    // Deliberately not asserting 200: rendering the UI depends on the spec
    // having been generated, which is a deploy concern. That the gate opened
    // at all — i.e. no longer 404 — is what this pins.
    expect($this->get('/api/documentation')->status())->not->toBe(404);
});
