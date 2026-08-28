<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `?branch_id=0` and `?loan_id=0` on the reports.
 *
 * The same falsy hole the loans list closed. `0` is a valid integer that no row
 * can carry, so it passed `['nullable','integer']`, and then every consumer of
 * these filters in ReportService gates on
 * `when($filters['branch_id'] ?? null, ...)` — TRUTHINESS, not presence. `0` is
 * falsy, so `when()` skipped the branch clause and the report answered a
 * question about one branch with the whole cooperative's figures. A frontend
 * that does `Number(params.id)` on a bad route segment sends exactly this.
 *
 * The fix is `min:1` in ReportController::reportFilters(), which is shared by
 * every report — so this asserts the whole surface, not one endpoint, and a
 * report added later inherits the guard without inheriting a test.
 */
class ReportFilterIdBoundsTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Every report that funnels its query string through reportFilters().
     *
     * The two CSV exports are covered separately: they sit behind
     * `throttle:exports` at 5 requests a minute, so they cannot be swept.
     *
     * @var array<int, string>
     */
    private const REPORT_ENDPOINTS = [
        '/api/reports/cash-flow',
        '/api/reports/collection-efficiency',
        '/api/reports/share-capital',
        '/api/reports/releases',
        '/api/reports/repayments',
        '/api/reports/due-past-due',
        '/api/reports/loan-balance-summary',
        '/api/reports/daily-collection',
        '/api/reports/income',
        '/api/reports/aging',
        '/api/reports/borrowers',
        '/api/reports/disbursements',
        '/api/reports/portfolio-by-product',
        '/api/reports/performance',
        '/api/reports/provisioning',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_zero_branch_id_is_rejected_rather_than_silently_ignored(): void
    {
        $response = $this->getJson('/api/reports/collection-efficiency?branch_id=0');

        $response->assertStatus(422)->assertJsonValidationErrors(['branch_id']);
    }

    public function test_a_zero_loan_id_is_rejected_rather_than_silently_ignored(): void
    {
        $response = $this->getJson('/api/reports/repayments?loan_id=0');

        $response->assertStatus(422)->assertJsonValidationErrors(['loan_id']);
    }

    public function test_a_negative_id_is_rejected_too(): void
    {
        $this->getJson('/api/reports/collection-efficiency?branch_id=-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);

        $this->getJson('/api/reports/repayments?loan_id=-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loan_id']);
    }

    /**
     * The guard lives in the shared filter validation, so it is not something
     * one endpoint remembered to do.
     */
    public function test_every_report_rejects_a_zero_branch_id(): void
    {
        foreach (self::REPORT_ENDPOINTS as $endpoint) {
            $status = $this->getJson("{$endpoint}?branch_id=0")->status();

            $this->assertSame(422, $status, "{$endpoint} must reject branch_id=0 rather than answer organisation-wide.");
        }
    }

    public function test_every_report_rejects_a_zero_loan_id(): void
    {
        foreach (self::REPORT_ENDPOINTS as $endpoint) {
            $status = $this->getJson("{$endpoint}?loan_id=0")->status();

            $this->assertSame(422, $status, "{$endpoint} must reject loan_id=0.");
        }
    }

    /**
     * The CSV exports share reportFilters() too, and a zero there would have
     * streamed the whole book rather than one branch's.
     *
     * Asserted as JSON requests because that is how the client fetches them. A
     * plain browser navigation to the same URL gets the framework's 302 back to
     * the form instead of a 422 — that is Laravel's behaviour for every
     * non-JSON request in this API, not something this guard introduces.
     */
    public function test_the_csv_exports_reject_a_zero_id(): void
    {
        $this->getJson('/api/reports/releases/export?branch_id=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);

        $this->getJson('/api/reports/repayments/export?loan_id=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loan_id']);
    }

    /**
     * Only the falsy value is newly rejected. A real id still filters, and an
     * absent filter still means "organisation-wide" — the point of `min:1` is
     * that those two answers stop being the same answer.
     */
    public function test_a_real_branch_id_and_an_absent_one_both_still_work(): void
    {
        $this->getJson("/api/reports/collection-efficiency?branch_id={$this->branch->id}")->assertOk();
        $this->getJson('/api/reports/collection-efficiency')->assertOk();
    }
}
