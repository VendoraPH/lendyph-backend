<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\Feature\Ledger\Release\ReleasesLoans;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Follow-up round 3, lead's call 4. GET /loans/{id} `contract_interest` is the
 * interest half of `contract_total`, by the same rule and in the same places,
 * so the loan page's Interest Amount and Contract Total always add up with the
 * principal.
 *
 * On a restructured source it is the original contract's interest: its
 * restructure deleted the unpaid periods, so the rows left held only the
 * interest already paid (₱2,000.00 beside a ₱93,587.65 Contract Total on
 * staging). Elsewhere it is the schedule's interest total.
 *
 * createReleasedLoan(): ₱60,000.00 over 6 monthly periods at 3% straight,
 * ₱1,800.00 interest a period, ₱10,800.00 over the contract.
 */
class ContractInterestTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function show(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
    }

    private function assertAddsUp(array $data, float $interest): void
    {
        $this->assertArrayHasKey('contract_interest', $data);
        $this->assertNotNull($data['contract_interest']);
        $this->assertSame(round($interest, 2), round((float) $data['contract_interest'], 2));
        $this->assertSame(
            LoanService::toCentavos((float) $data['contract_total']),
            LoanService::toCentavos((float) $data['principal_amount']) + LoanService::toCentavos((float) $data['contract_interest']),
            'principal_amount + contract_interest == contract_total',
        );
    }

    public function test_a_restructured_source_shows_its_original_contracts_interest(): void
    {
        $source = $this->createReleasedLoan();
        $this->payOn($source, 11800);
        $new = $this->approvedRestructureOf($source, 59000);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
        $this->assertSame('restructured', $source->fresh()->status);

        $data = $this->show($source);

        $this->assertEqualsWithDelta(70800.00, (float) $data['contract_total'], 0.001, 'setup: the original contract');
        $this->assertAddsUp($data, 10800.00);
    }

    public function test_a_released_loan_shows_its_schedules_interest(): void
    {
        $loan = $this->createReleasedLoan();
        $schedule = $loan->amortizationSchedules()->get();
        $interest = $schedule->sum(fn ($row) => LoanService::toCentavos((float) $row->interest_due)) / 100;

        $this->assertSame(10800.0, round($interest, 2), 'setup');
        $this->assertAddsUp($this->show($loan), $interest);
    }

    public function test_the_new_loan_of_a_restructure_shows_its_own_schedules_interest(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 70800);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $interest = $new->amortizationSchedules()->get()->sum(fn ($row) => LoanService::toCentavos((float) $row->interest_due)) / 100;

        $this->assertGreaterThan(0, $interest);
        $this->assertAddsUp($this->show($new), $interest);
    }

    public function test_before_release_it_is_the_preview_schedules_interest(): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);
        $draft = app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $this->assertAddsUp($this->show($draft), 10800.00);
    }

    /** A list never builds a schedule per row, as with `contract_total`. */
    public function test_a_list_row_has_it_only_where_it_has_a_contract_total(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 70800);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $rows = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))->keyBy('id');

        foreach ([$source->id, $new->id] as $id) {
            $row = $rows->get($id);
            $this->assertNotNull($row);
            $this->assertArrayHasKey('contract_interest', $row);
            $this->assertSame($row['contract_total'] === null, $row['contract_interest'] === null, "loan {$id}");
        }

        $this->assertNull($rows->get($source->id)['contract_interest']);
    }
}
