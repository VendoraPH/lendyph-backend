<?php

namespace Tests\Feature\ServerFigures;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The loan page's schedule shows each period's share capital build-up and
 * total payment, and their column totals. The browser added `scb_amount` to
 * every row and summed the columns itself; the persisted schedule,
 * GET /loans/{id}/amortization-schedule's `summary` and LoanResource's
 * `amortization_schedule_totals` must carry them instead, centavo-exact and
 * equal to what POST /loans/preview showed for the same loan.
 */
class PersistedScheduleScbTest extends TestCase
{
    use SetupLendyPH;

    private const PRODUCT = [
        'interest_rate' => 3, 'interest_method' => 'straight', 'frequency' => 'monthly',
        'term' => 3, 'max_term' => 3, 'processing_fee' => 0, 'service_fee' => 0,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function releasedLoanWithScb(LoanProduct $product, float $scb): Loan
    {
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => '2026-10-03',
            'scb_amount' => $scb,
            'deductions' => [],
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');
        $service->release($loan, $this->admin);

        return $loan->fresh();
    }

    public function test_each_persisted_period_carries_its_build_up_and_total_payment(): void
    {
        $loan = $this->releasedLoanWithScb(LoanProduct::factory()->create(self::PRODUCT), 100.50);

        $rows = $this->getJson("/api/loans/{$loan->id}/amortization-schedule")->assertOk()->json('data');

        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('share_capital_build_up', $row);
            $this->assertEquals(100.5, $row['share_capital_build_up']);
            $this->assertEquals(round($row['total_due'] + 100.5, 2), $row['total_payment']);
        }
    }

    public function test_the_schedule_summary_totals_equal_the_loan_form_preview(): void
    {
        $product = LoanProduct::factory()->create(self::PRODUCT);
        $loan = $this->releasedLoanWithScb($product, 100.50);

        $preview = $this->postJson('/api/loans/preview', [
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'interest_rate' => 3,
            'term' => 3,
            'frequency' => 'monthly',
            'start_date' => '2026-10-03',
            'scb_amount' => 100.50,
        ])->assertOk()->json('data.amortization.totals');

        $summary = $this->getJson("/api/loans/{$loan->id}/amortization-schedule")->assertOk()->json('summary');

        $this->assertArrayHasKey('total_share_capital_build_up', $summary);
        $this->assertEquals($preview['share_capital_build_up'], $summary['total_share_capital_build_up']);
        $this->assertEquals($preview['total_payment'], $summary['total_payment']);
        $this->assertEquals(301.5, $summary['total_share_capital_build_up']);
        $this->assertEquals(11201.5, $summary['total_payment']);
    }

    public function test_the_loan_resource_schedule_totals_carry_the_build_up(): void
    {
        $loan = $this->releasedLoanWithScb(LoanProduct::factory()->create(self::PRODUCT), 100.50);

        $totals = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.amortization_schedule_totals');

        $this->assertArrayHasKey('share_capital_build_up', $totals);
        $this->assertEquals(301.5, $totals['share_capital_build_up']);
        $this->assertEquals(11201.5, $totals['total_payment']);

        $rows = $this->getJson("/api/loans/{$loan->id}")->json('data.amortization_schedules');
        $this->assertEquals([100.5, 100.5, 100.5], array_column($rows, 'share_capital_build_up'));
    }

    public function test_a_loan_without_build_up_shows_zero_and_a_total_payment_equal_to_the_amount_due(): void
    {
        $loan = $this->releasedLoanWithScb(LoanProduct::factory()->create(self::PRODUCT), 0);

        $response = $this->getJson("/api/loans/{$loan->id}/amortization-schedule")->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertArrayHasKey('share_capital_build_up', $row);
            $this->assertEquals(0, $row['share_capital_build_up']);
            $this->assertEquals($row['total_due'], $row['total_payment']);
        }

        $this->assertEquals(0, $response->json('summary.total_share_capital_build_up'));
        $this->assertEquals($response->json('summary.total_principal') + $response->json('summary.total_interest'), $response->json('summary.total_payment'));
    }

    /**
     * An extension's options and the periods each leaves: 'pay' collects the
     * interest, so the first period is closed at what was collected and stays
     * (period 1) beside the new one (period 2); 'defer' collects nothing, so
     * the first period is deleted and only the new one is left.
     *
     * @return array<string, array{string, list<int>}>
     */
    public static function extensionOptions(): array
    {
        return [
            'pay' => ['pay', [1, 2]],
            'defer' => ['defer', [2]],
        ];
    }

    /**
     * Pins the build-up rule while it is open with Arvin: one build-up per
     * extension cycle, on the period the extension created; a period the
     * extension closed carries none.
     */
    #[DataProvider('extensionOptions')]
    public function test_an_extended_upon_maturity_loan_carries_one_build_up_and_its_closed_period_none(string $option, array $periods): void
    {
        $this->travelTo('2026-10-20 10:00:00');

        $product = LoanProduct::factory()->create([
            'interest_rate' => 3, 'interest_method' => 'upon_maturity', 'frequency' => 'monthly',
            'term' => 1, 'max_term' => 1, 'processing_fee' => 0, 'service_fee' => 0,
        ]);
        $loan = $this->releasedLoanWithScb($product, 100.50);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => $option])->assertOk();

        $response = $this->getJson("/api/loans/{$loan->id}/amortization-schedule")->assertOk();
        $rows = collect($response->json('data'))->keyBy('period_number');

        $this->assertSame($periods, $rows->keys()->all());

        if ($option === 'pay') {
            $this->assertNotNull($loan->amortizationSchedules()->where('period_number', 1)->value('closed_by_adjustment_id'));
            $this->assertEquals(0, $rows[1]['share_capital_build_up']);
            $this->assertEquals($rows[1]['total_due'], $rows[1]['total_payment']);
        }

        $this->assertEquals(100.5, $rows[2]['share_capital_build_up']);
        $this->assertEquals(round($rows[2]['total_due'] + 100.5, 2), $rows[2]['total_payment']);

        $this->assertEquals(100.5, $response->json('summary.total_share_capital_build_up'));
        $this->assertEquals(round($rows->sum('total_due') + 100.5, 2), $response->json('summary.total_payment'));

        $totals = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.amortization_schedule_totals');
        $this->assertEquals(100.5, $totals['share_capital_build_up']);
        $this->assertEquals($response->json('summary.total_payment'), $totals['total_payment']);
    }
}
