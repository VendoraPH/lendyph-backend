<?php

use App\Services\DeploymentIdentity;
use Tests\TestCase;

uses(TestCase::class);

/**
 * /api/health is how the fleet check answers "is this box current?". If it stops
 * reporting the commit, drift silently becomes invisible again.
 */
it('reports which commit and branch the deployment is serving', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJsonStructure(['status', 'timestamp', 'commit', 'branch', 'env', 'gd']);

    expect($response->json('status'))->toBe('ok')
        ->and($response->json('env'))->toBe('testing');
});

/**
 * The CDA report logo needs GD, so the health check says whether it is
 * loaded, and staging and production can be checked without server access.
 */
it('reports whether the GD extension is loaded', function () {
    $response = $this->getJson('/api/health')->assertOk();

    expect($response->json('gd'))->toBe(extension_loaded('gd'));
});

it('reports a short commit sha', function () {
    $commit = $this->getJson('/api/health')->json('commit');

    // Null is acceptable (a deployment without a readable .git); a malformed
    // sha is not — that would mean the ref resolver returned something odd.
    expect($commit === null || preg_match('/^[0-9a-f]{7}$/', $commit) === 1)
        ->toBeTrue('commit was neither null nor a 7-char sha: '.var_export($commit, true));
});

it('degrades to nulls instead of throwing when there is no git directory', function () {
    // A health endpoint that 500s because it cannot find a ref is worse than
    // one that admits it does not know.
    $identity = (new DeploymentIdentity('/nonexistent/path'))->toArray();

    expect($identity['commit'])->toBeNull()
        ->and($identity['branch'])->toBeNull()
        ->and($identity['env'])->toBe('testing');
});
