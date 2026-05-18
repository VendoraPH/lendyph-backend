<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Locks in the GET /api/loans/{id}/amortization-schedule contract for the
 * `remaining_balance` field. The FE handoff (2026-05-18) reported every row
 * coming back as 0 — verification against the local DB showed the field is
 * actually persisted correctly at release for all three interest methods.
 * This suite ensures that behavior cannot regress silently.
 *
 * Semantics: remaining_balance is the scheduled outstanding principal AFTER
 * the period's principal_due is applied. It is set once at release and is
 * not recomputed by payments.
 */
class AmortizationScheduleRemainingBalanceTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function releaseLoan(string $interestMethod, int $term, float $principal): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => $interestMethod,
            'term' => $term,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'OK');
        $service->release($loan, $this->admin);

        return $loan->fresh('amortizationSchedules');
    }

    public function test_straight_schedule_matches_handoff_example(): void
    {
        // Handoff example: ₱10,000 / 7 periods → 8571.43, 7142.86, ..., 0.
        $loan = $this->releaseLoan('straight', 7, 10000);

        $rows = $loan->amortizationSchedules->values();
        $expected = [8571.43, 7142.86, 5714.29, 4285.72, 2857.15, 1428.58, 0.0];

        $this->assertCount(7, $rows);
        foreach ($expected as $i => $value) {
            $this->assertEqualsWithDelta(
                $value,
                (float) $rows[$i]->remaining_balance,
                0.02,
                "period {$rows[$i]->period_number} remaining_balance",
            );
        }
    }

    public function test_last_row_remaining_balance_is_zero(): void
    {
        foreach (['straight', 'diminishing'] as $method) {
            $loan = $this->releaseLoan($method, 6, 50000);
            $last = $loan->amortizationSchedules->last();

            $this->assertEquals(
                0.0,
                (float) $last->remaining_balance,
                "Last row remaining_balance should be 0 for {$method}",
            );
        }
    }

    public function test_schedule_endpoint_returns_remaining_balance_for_every_row(): void
    {
        $loan = $this->releaseLoan('straight', 6, 60000);

        $response = $this->getJson("/api/loans/{$loan->id}/amortization-schedule");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['period_number', 'principal_due', 'remaining_balance', 'balance', 'status'],
                ],
                'summary',
            ]);

        $rows = $response->json('data');
        $this->assertCount(6, $rows);

        foreach ($rows as $row) {
            $this->assertNotNull($row['remaining_balance']);
            $this->assertIsNumeric($row['remaining_balance']);
        }

        // Last row must be 0; earlier rows must be > 0.
        $this->assertEquals(0.0, (float) $rows[5]['remaining_balance']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertGreaterThan(0, (float) $rows[$i]['remaining_balance']);
        }
    }

    public function test_remaining_balance_is_set_for_diminishing_method(): void
    {
        $loan = $this->releaseLoan('diminishing', 12, 80000);
        $rows = $loan->amortizationSchedules->values();

        $this->assertCount(12, $rows);
        $this->assertEquals(0.0, (float) $rows[11]->remaining_balance);

        // Balances must be monotonically non-increasing.
        $prev = (float) $loan->principal_amount;
        foreach ($rows as $row) {
            $current = (float) $row->remaining_balance;
            $this->assertLessThanOrEqual($prev, $current);
            $prev = $current;
        }
    }
}
