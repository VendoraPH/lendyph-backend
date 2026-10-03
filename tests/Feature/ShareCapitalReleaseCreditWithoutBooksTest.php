<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The share capital credit at release in an organisation that keeps no books
 * (no chart of accounts, so no release journal is posted). It still credits
 * the member, and it adds no refusal the release did not have before: the
 * journal's consistency guard has no journal to protect there.
 */
class ShareCapitalReleaseCreditWithoutBooksTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_release_without_books_still_credits_the_member(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
            ['name' => 'Share Capital', 'amount' => 750.25, 'type' => 'fixed'],
        ]);

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

        $row = ShareCapitalLedger::query()->where('loan_id', $loan->id)->sole();
        $this->assertSame('750.25', $row->credit);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_release_whose_items_the_journal_guard_would_refuse_still_releases_without_books(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed'],
        ]);
        // Items above the stated total: the release journal refuses this, but
        // there is no journal here, and the release succeeded before the
        // share capital credit existed.
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 400, 'net_proceeds' => 59600]);

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

        $this->assertSame('released', $loan->fresh()->status);
        $this->assertSame('500.00', ShareCapitalLedger::query()->where('loan_id', $loan->id)->sole()->credit);
    }

    /**
     * @param  list<array{name: string, amount: float|int, type: string}>  $deductions
     */
    private function approvedLoan(array $deductions): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'deductions' => $deductions,
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }
}
