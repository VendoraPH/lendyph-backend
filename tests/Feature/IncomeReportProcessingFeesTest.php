<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Income report's processing fees are the fees actually deducted at
 * release, read from each loan's recorded `deductions`, not the product's
 * current rate × principal. Only a loan with no record of its deductions
 * falls back to the rate.
 *
 * The product's rate is 2% throughout, so a ₱60,000 loan would have reported
 * ₱1,200 under the old figure whatever was deducted.
 */
class IncomeReportProcessingFeesTest extends TestCase
{
    use SetupLendyPH;

    private Branch $reportBranch;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->reportBranch = Branch::factory()->create();
        $this->product = LoanProduct::factory()->create(['processing_fee' => 2.0]);
    }

    public function test_the_fee_is_the_processing_fee_item_recorded_on_the_loan(): void
    {
        $this->releasedLoan(60000, [
            $this->item('Processing Fee', 900, 'percentage', 1.5),
            $this->item('Service Fee', 300, 'fixed', 300),
        ]);

        $this->assertProcessingFees(900);
    }

    public function test_a_percentage_item_counts_its_peso_amount_not_its_rate(): void
    {
        // 1.5% of 61,728 deducted; the product's 2% would have said 1,234.56.
        $this->releasedLoan(61728, [$this->item('Processing Fee', 925.92, 'percentage', 1.5)]);

        $this->assertProcessingFees(925.92);
    }

    public function test_names_match_the_way_the_fee_overlap_detector_normalises_them(): void
    {
        $this->releasedLoan(60000, [
            $this->item('PROCESSING-FEE.', 100.10, 'fixed', 100.10),
            $this->item('  processing   fee ', 50.25, 'fixed', 50.25),
            $this->item('Processing Fee Waiver', 999, 'fixed', 999),
            $this->item('Processing', 77, 'fixed', 77),
        ]);

        $this->assertProcessingFees(150.35);
    }

    public function test_a_loan_that_recorded_deductions_without_a_processing_fee_contributes_nothing(): void
    {
        $this->releasedLoan(60000, [$this->item('Service Fee', 300, 'fixed', 300)]);
        // A sent `[]` means no fees, on purpose.
        $this->releasedLoan(30000, [], totalDeductions: 0);

        $this->assertProcessingFees(0);
    }

    public function test_the_real_create_and_release_path_records_what_the_report_reads(): void
    {
        // A catalog fee under the same name, appended at release with its
        // `fee_id`, counts by the same name test as createLoan()'s item.
        Fee::create(['name' => 'Processing fee', 'type' => 'fixed', 'value' => 250, 'applicable_product_ids' => null]);

        $loan = $this->createReleasedLoan(['product' => ['processing_fee' => 2.0], 'principal_amount' => 60000]);
        $loan->update(['branch_id' => $this->reportBranch->id]);

        $items = collect($loan->deductions)->keyBy('name');
        $this->assertSame(1200.0, (float) $items['Processing Fee']['amount']);
        $this->assertEquals(2.0, $items['Processing Fee']['original_value']);
        $this->assertArrayHasKey('fee_id', $items['Processing fee']);

        $this->assertProcessingFees(1450, released: $loan->released_at->toDateString());
    }

    public function test_a_loan_with_no_recorded_deductions_falls_back_to_the_product_rate(): void
    {
        // SQL NULL.
        $this->releasedLoan(60000, null);
        // JSON null.
        $jsonNull = $this->releasedLoan(30000, null);
        DB::table('loans')->where('id', $jsonNull->id)->update(['deductions' => DB::raw("CAST('null' AS JSON)")]);
        // An empty list beside a non-zero total contradicts itself.
        $this->releasedLoan(10000, [], totalDeductions: 500);

        // 2% of 100,000.
        $this->assertProcessingFees(2000);
    }

    public function test_recorded_and_fallback_loans_add_up(): void
    {
        $this->releasedLoan(60000, [$this->item('Processing Fee', 900, 'percentage', 1.5)]);
        $this->releasedLoan(201, null, product: LoanProduct::factory()->create(['processing_fee' => 0.5]));

        // 900 + 1.005, rounded once at the end.
        $this->assertProcessingFees(901.01);
    }

    public function test_every_filter_still_applies(): void
    {
        $inside = $this->releasedLoan(60000, [$this->item('Processing Fee', 900, 'fixed', 900)], releasedAt: '2026-03-10 00:00:00');
        $fallback = $this->releasedLoan(10000, null, releasedAt: '2026-03-20 23:59:59');

        // Outside the period, on either side.
        $this->releasedLoan(60000, [$this->item('Processing Fee', 5000, 'fixed', 5000)], releasedAt: '2026-03-09 23:59:59');
        $this->releasedLoan(60000, null, releasedAt: '2026-03-21 00:00:00');
        // Never released.
        $this->releasedLoan(60000, [$this->item('Processing Fee', 7000, 'fixed', 7000)], status: 'approved');
        $this->releasedLoan(60000, null, status: 'draft');
        // Another branch.
        $this->releasedLoan(60000, [$this->item('Processing Fee', 3000, 'fixed', 3000)], branch: Branch::factory()->create());

        $range = 'date_from=2026-03-10&date_to=2026-03-20';

        // 900 recorded + 2% of 10,000.
        $this->getJson("/api/reports/income?{$range}&branch_id={$this->reportBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.processing_fees', 1100);

        $this->getJson("/api/reports/income?{$range}&loan_id={$inside->id}")
            ->assertOk()
            ->assertJsonPath('data.processing_fees', 900);

        $this->getJson("/api/reports/income?{$range}&loan_id={$fallback->id}")
            ->assertOk()
            ->assertJsonPath('data.processing_fees', 200);

        // Every ever-released status counts.
        foreach (Loan::EVER_RELEASED_STATUSES as $status) {
            $loan = $this->releasedLoan(1000, [$this->item('Processing Fee', 12.34, 'fixed', 12.34)], status: $status, releasedAt: '2026-04-01 09:00:00');

            $this->getJson("/api/reports/income?loan_id={$loan->id}")
                ->assertOk()
                ->assertJsonPath('data.processing_fees', 12.34);
        }
    }

    /**
     * @return array{name: string, amount: float|int, type: string, original_value: float|int}
     */
    private function item(string $name, float|int $amount, string $type, float|int $originalValue): array
    {
        return ['name' => $name, 'amount' => $amount, 'type' => $type, 'original_value' => $originalValue];
    }

    /**
     * @param  list<array<string, mixed>>|null  $deductions
     */
    private function releasedLoan(
        float $principal,
        ?array $deductions,
        ?float $totalDeductions = null,
        string $status = 'ongoing',
        string $releasedAt = '2026-03-15 09:00:00',
        ?Branch $branch = null,
        ?LoanProduct $product = null,
    ): Loan {
        $branch ??= $this->reportBranch;
        $totalDeductions ??= round(array_sum(array_column($deductions ?? [], 'amount')), 2);

        return Loan::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id])->id,
            'loan_product_id' => ($product ?? $this->product)->id,
            'branch_id' => $branch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'deductions' => $deductions,
            'total_deductions' => $totalDeductions,
            'net_proceeds' => round($principal - $totalDeductions, 2),
            'status' => $status,
            'released_at' => in_array($status, Loan::EVER_RELEASED_STATUSES, true) ? $releasedAt : null,
        ]);
    }

    private function assertProcessingFees(float|int $expected, string $released = '2026-03-15'): void
    {
        $this->getJson("/api/reports/income?date_from={$released}&date_to={$released}&branch_id={$this->reportBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.processing_fees', $expected);
    }
}
