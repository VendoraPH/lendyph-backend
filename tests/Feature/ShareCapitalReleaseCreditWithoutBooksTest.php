<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function test_share_capital_above_what_the_loan_withheld_is_not_credited_and_the_release_still_goes_ahead(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed'],
        ]);
        // Items above the stated total: only ₱400 was withheld, so ₱500 of
        // share capital is not a figure the loan proves. The release journal
        // would refuse this, but there is no journal here, and the release
        // succeeded before the share capital credit existed.
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 400, 'net_proceeds' => 59600]);
        Log::spy();

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

        $loan->refresh();
        $this->assertSame('released', $loan->status);
        $this->assertSame(0, ShareCapitalLedger::query()->count());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Share capital not credited at release')
            && $context === [
                'loan_id' => $loan->id,
                'loan_account_number' => $loan->loan_account_number,
                'share_capital_centavos' => 50000,
                'total_deductions_centavos' => 40000,
            ]);
    }

    public function test_share_capital_equal_to_everything_withheld_is_still_credited(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed'],
        ]);

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

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
