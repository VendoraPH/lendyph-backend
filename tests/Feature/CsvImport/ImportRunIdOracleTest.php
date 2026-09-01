<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportRun;
use Tests\TestCase;
use Tests\Traits\StagesCsvImportRuns;

/**
 * A caller who may not read an import must not learn which ones exist.
 *
 * `SubstituteBindings` runs before the controller, so a bound
 * `CsvImportRun $run` parameter is resolved — and 404s — before
 * `authorize('imports:process')` is ever reached. The refusal then leaks the
 * one bit the permission was meant to withhold: 404 for an id nobody has used,
 * 403 for a real run. Walk the integers and you have the cooperative's import
 * history, including how many migrations it has attempted, without holding the
 * permission to see any of them.
 *
 * The fix is ordering, not concealment: take the id as a scalar, authorize, and
 * only then resolve. An authorised caller still gets an honest 404.
 */
class ImportRunIdOracleTest extends TestCase
{
    use StagesCsvImportRuns;

    /** An id no run has ever had — `migrate:fresh` restarts the sequence. */
    private const UNUSED_ID = 9187;

    /**
     * A well-formed digest, so the chunk route's validator is not what answers.
     */
    private const DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private CsvImportRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        $this->run = $this->makeImportRun();
        $this->makeImportFile($this->run, 'loans');
    }

    /**
     * EVERY route that takes a run id. All eight of them.
     *
     * This list used to hold five, under a claim about eight. The other three —
     * the chunk PUT, assemble and destroy — were checked in
     * CsvImportUploadApiTest instead, where the 404 BODY was never asserted for
     * any of them: that file pins the body for destroy alone, and only for one
     * hard-coded id. So the helper walked five, the docblock said eight, and
     * two routes were covered by neither. A test whose name overstates what it
     * checks is how a gap survives a review of both files, which is why the
     * claim and the coverage now live in one place.
     *
     * `$payload` exists for the chunk route alone. Its FormRequest authorises
     * BEFORE it validates — deliberately, so an unprivileged caller gets a 403
     * rather than a 422 that would tell them the size limit — but an AUTHORISED
     * caller does meet the validator first, so without a well-formed digest the
     * 404 test below would be asserting on a 422 and proving nothing about the
     * lookup.
     *
     * Three of these sit behind `throttle:exports` at 5/min, and a caller who
     * will be refused gets 10/min, so each test method walks this list exactly
     * ONCE — eight calls, inside both ceilings. The cache store is `array` and
     * resets between methods, which is what keeps a 429 from masquerading as a
     * failure here.
     *
     * @return list<array{0: string, 1: string, 2: array<string, string>}>
     */
    private function routes(int|string $id): array
    {
        return [
            ['GET', "/api/imports/{$id}", []],
            ['GET', "/api/imports/{$id}/errors", []],
            ['GET', "/api/imports/{$id}/errors.csv", []],
            ['GET', "/api/imports/{$id}/product-mapping", []],
            ['PUT', "/api/imports/{$id}/product-mapping", []],
            ['PUT', "/api/imports/{$id}/files/customers/chunks/0", ['sha256' => self::DIGEST]],
            ['POST', "/api/imports/{$id}/assemble", []],
            ['DELETE', "/api/imports/{$id}", []],
        ];
    }

    public function test_the_list_covers_every_route_that_takes_a_run_id(): void
    {
        /*
         * The claim above is "all eight", and nothing else in this file would
         * notice a ninth route being added with the binding put back. Counted
         * against the router rather than against this list, so adding a route
         * and forgetting to check it fails here instead of silently narrowing
         * every assertion below.
         */
        $registered = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'imports.')
                && str_contains($route->uri(), '{run}'))
            ->count();

        $this->assertSame(
            count($this->routes(1)),
            $registered,
            'A route taking a run id was added or removed without this list following it, so the assertions '
            .'below no longer cover what their names claim.',
        );
    }

    public function test_an_unauthorised_caller_is_refused_before_a_missing_run_is_looked_up(): void
    {
        $this->actingAs($this->userWithRoleNamed('loan_officer'));

        foreach ($this->routes(self::UNUSED_ID) as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)->assertForbidden(
                "{$method} {$url} answered a caller without imports:process with a 404, "
                .'which tells them run #'.self::UNUSED_ID.' does not exist.',
            );
        }
    }

    public function test_an_unauthorised_caller_gets_the_same_answer_for_a_run_that_does_exist(): void
    {
        $this->actingAs($this->userWithRoleNamed('loan_officer'));

        foreach ($this->routes($this->run->id) as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)->assertForbidden();
        }

        // And nothing was done to it on the way past — DELETE is on that list.
        $this->assertSame($this->run->phase, CsvImportRun::findOrFail($this->run->id)->phase);
    }

    public function test_an_authorised_caller_still_gets_an_honest_404(): void
    {
        foreach ($this->routes(self::UNUSED_ID) as [$method, $url, $payload]) {
            $response = $this->json($method, $url, $payload);

            $response->assertNotFound("{$method} {$url} must still 404 for someone who may look.");
            $this->assertSame(
                'Import run #'.self::UNUSED_ID.' was not found.',
                $response->json('message'),
                "{$method} {$url} must match CsvImportUploadService::findRun() so all eight import routes read the same.",
            );
        }
    }

    public function test_a_non_numeric_run_id_is_not_a_route_match_at_all(): void
    {
        /*
         * `whereNumber('run')` on every route, so a hostile id never reaches a
         * controller and the scalar cast below it can never see anything but
         * digits.
         */
        foreach ($this->routes('not-a-number') as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)->assertNotFound();
        }
    }
}
