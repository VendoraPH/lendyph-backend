<?php

namespace Tests\Feature\FzReports;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\Accounting\PostingRules;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Income report counts service fee and catalog fee income, from the
 * deductions recorded at release, classified as the release journal classifies
 * them (4040 Service Fee Income, 4080 Other Fee Income). Taxes, notarial fees,
 * insurance premium and share capital are never income (round fz).
 */
class IncomeServiceAndCatalogFeesTest extends TestCase
{
    use SetupLendyPH;

    private const PERIOD = 'date_from=2026-03-01&date_to=2026-03-31';

    private Branch $reportBranch;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->reportBranch = Branch::factory()->create();
        $this->product = LoanProduct::factory()->create(['processing_fee' => 2.0]);
    }

    public function test_the_card_counts_service_and_catalog_fees_and_nothing_else(): void
    {
        $this->released(60000, $this->everyKindOfDeduction());

        $income = $this->card();

        $this->assertEquals(900, $income['processing_fees']);
        $this->assertEquals(300.50, $income['service_fee']);
        $this->assertEquals(275.25, $income['other_fees']);
        $this->assertEquals(1475.75, $income['total']);
    }

    public function test_the_row_carries_the_same_fees_and_they_are_in_its_total(): void
    {
        $this->released(60000, $this->everyKindOfDeduction());

        $row = $this->rows()->json('data.0');

        $this->assertEquals(900, $row['processing_fee']);
        $this->assertEquals(300.50, $row['service_fee']);
        $this->assertEquals(275.25, $row['other_fees']);
        $this->assertEquals(1475.75, $row['total_income']);
    }

    public function test_the_classification_is_the_release_journal_s(): void
    {
        $items = $this->everyKindOfDeduction();
        $this->released(60000, $items);

        $journalItems = array_map(fn (array $item): array => [
            'name' => $item['name'],
            'amount' => (int) round($item['amount'] * 100),
            'catalog_fee' => isset($item['fee_id']),
        ], $items);
        $total = array_sum(array_column($journalItems, 'amount'));
        $credits = PostingRules::classifyDeductions($total, $journalItems)['credits'];

        $income = $this->card();

        $this->assertEquals($credits['service_fee_income'] / 100, $income['service_fee']);
        $this->assertEquals($credits['other_fee_income'] / 100, $income['other_fees']);
    }

    public function test_a_catalog_fee_named_a_service_fee_is_a_service_fee_and_a_catalog_tax_is_no_income(): void
    {
        $this->released(60000, [
            $this->item('Service-Fee.', 100, feeId: 7),
            $this->item('Documentary Stamp Tax', 50, feeId: 8),
            $this->item('Other Taxes', 25, feeId: 9),
        ]);

        $income = $this->card();

        $this->assertEquals(100, $income['service_fee']);
        $this->assertEquals(0, $income['other_fees']);
        $this->assertEquals(100, $income['total']);
    }

    public function test_a_loan_with_only_a_service_or_catalog_fee_is_listed(): void
    {
        $service = $this->released(60000, [$this->item('Service Fee', 300)]);
        $catalog = $this->released(30000, [$this->item('Credit Investigation Fee', 150, feeId: 7)]);
        $this->released(30000, [$this->item('Notarial Fee', 150), $this->item('Share Capital', 500)]);

        $response = $this->rows();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([$service->id, $catalog->id], array_column($response->json('data'), 'loan_id'));
    }

    public function test_the_rows_add_up_to_the_totals_and_to_the_card(): void
    {
        $this->released(60000, $this->everyKindOfDeduction());
        $this->released(30000, [$this->item('Service Fee', 100.10), $this->item('Credit Investigation Fee', 33.33, feeId: 7)]);
        // No record of the deductions: the product's 2%, one centavo apart
        // when added up unrounded (₱666.6666 three times).
        $this->released(33333.33, null);
        $this->released(33333.33, null);
        $this->released(33333.33, null);

        $income = $this->card();
        $response = $this->rows();
        $rows = collect($response->json('data'));
        $totals = $response->json('meta.totals');

        foreach ([
            'processing_fee' => 'processing_fees',
            'service_fee' => 'service_fee',
            'other_fees' => 'other_fees',
            'total_income' => 'total',
        ] as $rowKey => $cardKey) {
            $this->assertEquals(round($rows->sum($rowKey), 2), $income[$cardKey], "{$rowKey} rows and the card");
            $this->assertEquals($income[$cardKey], $totals[$rowKey], "{$rowKey} totals and the card");
        }
    }

    public function test_a_fee_outside_the_period_is_not_counted(): void
    {
        $this->released(60000, [$this->item('Service Fee', 300), $this->item('Credit Investigation Fee', 150, feeId: 7)], '2026-04-01 00:10:00');

        $income = $this->card();

        $this->assertEquals(0, $income['service_fee']);
        $this->assertEquals(0, $income['other_fees']);
        $this->assertSame(0, $this->rows()->json('meta.total'));
    }

    /** @return list<array<string, mixed>> */
    private function everyKindOfDeduction(): array
    {
        return [
            $this->item('Processing Fee', 900),
            $this->item('Service Fee', 300.50),
            $this->item('Credit Investigation Fee', 200.25, feeId: 7),
            $this->item('Appraisal Fee', 75, feeId: 8),
            $this->item('Notarial Fee', 100),
            $this->item('Insurance Premium', 500),
            $this->item('Share Capital', 1000),
            $this->item('Documentary Stamp Tax', 45, feeId: 9),
            $this->item('Other Taxes', 12),
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $name, float $amount, ?int $feeId = null): array
    {
        return array_filter([
            'name' => $name,
            'amount' => $amount,
            'type' => 'fixed',
            'original_value' => $amount,
            'fee_id' => $feeId,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param list<array<string, mixed>>|null $deductions */
    private function released(float $principal, ?array $deductions, string $releasedAt = '2026-03-05 09:00:00'): Loan
    {
        $total = round(array_sum(array_column($deductions ?? [], 'amount')), 2);

        return Loan::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->reportBranch->id])->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $this->reportBranch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'deductions' => $deductions,
            'total_deductions' => $total,
            'net_proceeds' => round($principal - $total, 2),
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function card(): array
    {
        return $this->getJson('/api/reports/income?'.self::PERIOD)->assertOk()->json('data');
    }

    private function rows()
    {
        return $this->getJson('/api/reports/income/by-loan?per_page=100&'.self::PERIOD)->assertOk();
    }
}
