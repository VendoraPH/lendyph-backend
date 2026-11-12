<?php

namespace Tests\Feature\Penalties;

use App\Models\Borrower;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\LoanService;
use App\Services\Penalties\PenaltyTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;
use Tests\Traits\SetupLendyPH;

/**
 * A loan's penalty terms are fixed when it is created (Phase 4): a product
 * covered by an active configuration gives the loan that configuration's id,
 * name and brackets and a penalty rate of 0; any other product gives its own
 * rate, as today. Editing, deactivating or deleting the configuration later
 * changes only loans created afterwards.
 */
class PenaltyConfigSnapshotTest extends TestCase
{
    use BuildsCsvImports, SetupLendyPH;

    private const FIRST = [
        ['days_from' => 1, 'days_to' => 30, 'rate' => 20],
        ['days_from' => 31, 'days_to' => null, 'rate' => 50],
    ];

    private const SECOND = [
        ['days_from' => 1, 'days_to' => null, 'rate' => 2.5],
    ];

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));

        $this->product = LoanProduct::factory()->create([
            'name' => 'Salary Loan',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 3.0,
            'grace_period_days' => 0,
            'min_amount' => 1000,
            'max_amount' => 1000000,
        ]);
    }

    public function test_a_new_loan_copies_its_products_active_configuration(): void
    {
        $id = $this->configure(self::FIRST);

        $loan = $this->newLoan();

        $this->assertSame($id, $loan->penalty_config_id);
        $this->assertSame('Standard Loan Penalty', $loan->penalty_config_name);
        $this->assertEquals(self::FIRST, $loan->penalty_brackets);
        $this->assertEqualsWithDelta(0.0, (float) $loan->penalty_rate, 0.00001);
    }

    public function test_a_product_with_no_active_configuration_keeps_giving_its_own_rate(): void
    {
        $this->configure(self::FIRST, status: 'inactive');

        $loan = $this->newLoan();

        $this->assertSame('3.0000', $loan->penalty_rate);
        $this->assertNull($loan->penalty_config_id);
        $this->assertNull($loan->penalty_config_name);
        $this->assertNull($loan->penalty_brackets);
    }

    public function test_editing_or_deactivating_a_configuration_leaves_existing_loans_and_their_charges_unchanged(): void
    {
        $id = $this->configure(self::FIRST);
        $first = $this->releasedLoan();

        $this->putJson("/api/penalty-configs/{$id}", [
            'name' => 'Renamed Penalty',
            'applicable_product_ids' => [$this->product->id],
            'conditions' => self::SECOND,
        ])->assertOk();
        $second = $this->releasedLoan();

        $this->patchJson("/api/penalty-configs/{$id}/status", ['status' => 'inactive'])->assertOk();
        $third = $this->releasedLoan();

        $first->refresh();
        $this->assertSame('Standard Loan Penalty', $first->penalty_config_name);
        $this->assertEquals(self::FIRST, $first->penalty_brackets);
        $this->assertSame('Renamed Penalty', $second->penalty_config_name);
        $this->assertEquals(self::SECOND, $second->penalty_brackets);
        $this->assertNull($third->penalty_brackets);
        $this->assertSame('3.0000', $third->penalty_rate);

        // Period 1 falls due 15 February: day 1 then day 59 (15 April).
        $this->travelTo(Carbon::parse('2026-04-15 00:10'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        // ₱10,000 a period: 20% + 50%; 2.5% twice; 3% twice.
        $this->assertEqualsWithDelta(7000, (float) $first->amortizationSchedules()->where('period_number', 1)->value('penalty_amount'), 0.001);
        $this->assertEqualsWithDelta(500, (float) $second->amortizationSchedules()->where('period_number', 1)->value('penalty_amount'), 0.001);
        $this->assertEqualsWithDelta(600, (float) $third->amortizationSchedules()->where('period_number', 1)->value('penalty_amount'), 0.001);

        // Still in use, so it can't be deleted.
        $this->deleteJson("/api/penalty-configs/{$id}")
            ->assertConflict()
            ->assertJsonPath('message', "This configuration is on 2 loans, so it can't be deleted. Deactivate it instead.");
    }

    public function test_the_copy_reads_the_configuration_now_covering_the_product_under_a_lock(): void
    {
        $other = LoanProduct::factory()->create(['name' => 'Other Loan']);
        $first = $this->configure(self::FIRST);

        // The product moves from the first configuration to a second one.
        $this->putJson("/api/penalty-configs/{$first}", [
            'name' => 'Standard Loan Penalty',
            'applicable_product_ids' => [$other->id],
            'conditions' => self::FIRST,
        ])->assertOk();
        $second = $this->postJson('/api/penalty-configs', [
            'name' => 'Moved Penalty',
            'applicable_product_ids' => [$this->product->id],
            'conditions' => self::SECOND,
        ])->assertCreated()->json('data.id');

        foreach ([false, true] as $inTransaction) {
            $terms = $inTransaction
                ? DB::transaction(fn (): array => PenaltyTerms::forProduct($this->product->fresh()))
                : PenaltyTerms::forProduct($this->product->fresh());

            $this->assertSame($second, $terms['penalty_config_id']);
            $this->assertSame('Moved Penalty', $terms['penalty_config_name']);
            $this->assertEquals(self::SECOND, $terms['penalty_brackets']);
            $this->assertSame(0, $terms['penalty_rate']);
        }

        // Deactivated: the product's own rate, inside a transaction too.
        $this->patchJson("/api/penalty-configs/{$second}/status", ['status' => 'inactive'])->assertOk();
        $terms = DB::transaction(fn (): array => PenaltyTerms::forProduct($this->product->fresh()));
        $this->assertNull($terms['penalty_brackets']);
        $this->assertSame('3.0000', $terms['penalty_rate']);
    }

    public function test_an_imported_loan_copies_its_products_active_configuration(): void
    {
        $id = $this->configure(self::FIRST);

        $this->seedForImport();
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $this->product->id]]);
        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-0001')]);
        $this->runToIdle($run);

        $loan = Loan::where('external_loan_no', 'L-0001')->firstOrFail();

        $this->assertSame($id, $loan->penalty_config_id);
        $this->assertSame('Standard Loan Penalty', $loan->penalty_config_name);
        $this->assertEquals(self::FIRST, $loan->penalty_brackets);
        $this->assertEqualsWithDelta(0.0, (float) $loan->penalty_rate, 0.00001);
    }

    /** @param list<array{days_from: int, days_to: int|null, rate: int|float}> $brackets */
    private function configure(array $brackets, string $status = 'active'): int
    {
        return $this->postJson('/api/penalty-configs', [
            'name' => 'Standard Loan Penalty',
            'status' => $status,
            'applicable_product_ids' => [$this->product->id],
            'conditions' => $brackets,
        ])->assertCreated()->json('data.id');
    }

    private function newLoan(): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => 60000,
            'start_date' => '2026-01-15',
        ], $this->admin)->fresh();
    }

    private function releasedLoan(): Loan
    {
        $service = app(LoanService::class);
        $loan = $this->newLoan();

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');
        $service->release($loan, $this->admin);

        return $loan->fresh();
    }

    private function runToIdle(CsvImportRun $run): void
    {
        $processor = new CsvImportProcessor;

        for ($i = 0; $i < 200; $i++) {
            if ($processor->advance($run)->idle) {
                return;
            }

            $run->refresh();
        }

        $this->fail('The import did not settle.');
    }
}
