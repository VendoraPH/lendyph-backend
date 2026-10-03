<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * An unauthenticated call to a protected API route answers a JSON 401 whatever
 * Accept header it sends.
 *
 * Without `Accept: application/json`, the auth middleware used to look up the
 * `login` route to redirect the guest to; this API has none, so the lookup
 * threw and the caller got a 500 instead of a 401.
 */
uses(TestCase::class);

dataset('accept headers', [
    'no Accept header' => [null],
    'Accept: text/html' => ['text/html'],
    'Accept: */*' => ['*/*'],
    'Accept: application/json' => ['application/json'],
]);

dataset('protected api routes', [
    'GET /api/loans' => ['GET', '/api/loans'],
    'GET /api/auth/me' => ['GET', '/api/auth/me'],
    'POST /api/gcash/transactions' => ['POST', '/api/gcash/transactions'],
    'GET /api/reports/income' => ['GET', '/api/reports/income'],
]);

it('answers a JSON 401 for an unauthenticated API call', function (?string $accept, string $method, string $uri) {
    $headers = $accept === null ? [] : ['Accept' => $accept];

    $response = $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers));

    $response->assertStatus(401)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with('accept headers')->with('protected api routes');

it('still answers a JSON 401 for a bearer token that does not exist', function (?string $accept) {
    $headers = ['Authorization' => 'Bearer 999|not-a-real-token'] + ($accept === null ? [] : ['Accept' => $accept]);

    $this->call('GET', '/api/loans', [], [], [], $this->transformHeadersToServerVars($headers))
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with('accept headers');

it('leaves a guest on a web route redirected to the login route', function () {
    Route::get('/__probe-login', fn () => 'sign in')->name('login');
    Route::middleware(['web', 'auth'])->get('/__probe-web-protected', fn () => 'ok');
    app('router')->getRoutes()->refreshNameLookups();

    $this->get('/__probe-web-protected', ['Accept' => 'text/html'])
        ->assertRedirect('/__probe-login');
});
