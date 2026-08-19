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

it('denies when the deployment has not set the flag at all', function () {
    // Deny-by-default is the whole point: ten deployments share this codebase,
    // so a box that never sets the flag must publish nothing.
    //
    // Asserting the *resolved* config value would only prove what this
    // environment's .env happens to say — CI copies .env.example, a developer
    // has their own. What matters is that an absent flag denies.
    config(['l5-swagger.enabled' => null]);

    $this->get('/docs')->assertNotFound();
    $this->get('/api/documentation')->assertNotFound();
});

it('serves the swagger ui once a deployment opts in', function () {
    config(['l5-swagger.enabled' => true]);

    // Deliberately not asserting 200: rendering the UI depends on the spec
    // having been generated, which is a deploy concern. That the gate opened
    // at all — i.e. no longer 404 — is what this pins.
    expect($this->get('/api/documentation')->status())->not->toBe(404);
});
