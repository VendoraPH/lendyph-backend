<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * These five deployments are API-only. The stock welcome route put Laravel's
 * splash page at the root of client-facing production APIs, and — because `/`
 * matched a route in the `web` group — issued a session cookie and wrote a
 * database session row for every scanner that hit it.
 */
it('does not serve a landing page at the API root', function () {
    $this->get('/')->assertNotFound();
});

it('sets no session cookie at the API root', function () {
    // The point of removing the route rather than replacing it with JSON: an
    // unmatched path never enters the `web` group, so StartSession never runs.
    // A 404 alone would not prove this — the cookie is the regression to guard.
    $response = $this->get('/');

    expect($response->headers->getCookies())->toBeEmpty();
});
