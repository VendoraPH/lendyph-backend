<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This codebase serves five API-only deployments. Nothing is rendered from
| here — the stock `Route::get('/', fn () => view('welcome'))` put Laravel's
| 70 KB splash page at the root of client-facing production APIs, including
| api.binhs-coop.lendyph.com.
|
| There is deliberately no route rather than a JSON pointer, for two reasons:
|
|   1. There is nothing to point at. L5_SWAGGER_ENABLED is false on every box
|      and /docs already 404s, so a pointer would advertise a dead link.
|   2. An unmatched path never enters the `web` middleware group. `/` used to
|      match one, so StartSession ran and — with SESSION_DRIVER=database — wrote
|      a row and issued a cookie for every scanner hit. A JSON response defined
|      here would still be in that group and would still churn.
|
| Nothing depends on `/`. Health checks live elsewhere and are unaffected:
|   GET /up          framework health route, registered in bootstrap/app.php
|   GET /api/health  commit identity, polled by .github/workflows/fleet-drift-check.yml
|
| Certbot uses the nginx authenticator on all five hosts, not an HTTP-01
| webroot, so it does not need a servable document root either.
|
| The withRouting(web: ...) wiring in bootstrap/app.php stays, so there is an
| obvious home for a web route if one is ever genuinely needed.
|
*/
