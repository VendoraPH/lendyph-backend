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

    private CsvImportRun $run;

    /** An id no run has ever had — `migrate:fresh` restarts the sequence. */
    private const UNUSED_ID = 9187;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        $this->run = $this->makeImportRun();
        $this->makeImportFile($this->run, 'loans');
    }

    /**
     * Every route that takes a run id.
     *
     * Three of them sit behind `throttle:exports` at 5/min, so each test method
     * walks this list exactly once — the cache store is `array` and resets
     * between methods, which is what keeps a 429 from masquerading as a
     * failure here.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function routes(int $id): array
    {
        return [
            ['GET', "/api/imports/{$id}"],
            ['GET', "/api/imports/{$id}/errors"],
            ['GET', "/api/imports/{$id}/errors.csv"],
            ['GET', "/api/imports/{$id}/product-mapping"],
            ['PUT', "/api/imports/{$id}/product-mapping"],
        ];
    }

    public function test_an_unauthorised_caller_is_refused_before_a_missing_run_is_looked_up(): void
    {
        $this->actingAs($this->userWithRoleNamed('loan_officer'));

        foreach ($this->routes(self::UNUSED_ID) as [$method, $url]) {
            $this->json($method, $url)->assertForbidden(
                "{$method} {$url} answered a caller without imports:process with a 404, "
                .'which tells them run #'.self::UNUSED_ID.' does not exist.',
            );
        }
    }

    public function test_an_unauthorised_caller_gets_the_same_answer_for_a_run_that_does_exist(): void
    {
        $this->actingAs($this->userWithRoleNamed('loan_officer'));

        foreach ($this->routes($this->run->id) as [$method, $url]) {
            $this->json($method, $url)->assertForbidden();
        }
    }

    public function test_an_authorised_caller_still_gets_an_honest_404(): void
    {
        foreach ($this->routes(self::UNUSED_ID) as [$method, $url]) {
            $response = $this->json($method, $url);

            $response->assertNotFound("{$method} {$url} must still 404 for someone who may look.");
            $this->assertSame(
                'Import run #'.self::UNUSED_ID.' was not found.',
                $response->json('message'),
                'The body must match CsvImportUploadService::findRun() so all eight import routes read the same.',
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
        foreach ($this->routes(0) as [$method, $url]) {
            $this->json($method, str_replace('/0', '/not-a-number', $url))->assertNotFound();
        }
    }
}
