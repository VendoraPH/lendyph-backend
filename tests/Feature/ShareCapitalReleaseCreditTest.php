<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\LoanService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Share capital withheld from a loan at release reaches the member's share
 * capital ledger, in the same transaction that credits the Share Capital
 * equity account in the books, so the member's ledger, the Share Capital
 * report and the books say the same thing.
 *
 * One credit row per release, carrying the loan it came from: the sum of every
 * deduction the release journal books to share capital, after the configured
 * fees and the insurance premium were added, in centavos.
 */
class ShareCapitalReleaseCreditTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_a_release_with_share_capital_deductions_writes_one_credit_for_their_sum(): void
    {
        // A catalog fee and the insurance premium ride along, and must not
        // leak into the share capital figure.
        Fee::create(['name' => 'Credit Investigation Fee', 'type' => 'fixed', 'value' => 250, 'applicable_product_ids' => null, 'conditions' => null]);

        $loan = $this->approvedLoan([
            ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
            ['name' => 'Share Capital', 'amount' => 1000.10, 'type' => 'fixed'],
            // Named the way PostingRules normalises, so still share capital.
            ['name' => 'SHARE-CAPITAL.', 'amount' => 0.5, 'type' => 'percentage'],
        ]);

        $released = app(LoanService::class)->release($loan, $this->admin, [
            'insurance_premium_percentage' => 1,
            'insurance_premium_amount' => 600,
            'insurance_payment_type' => 'full',
        ]);

        $rows = ShareCapitalLedger::query()->where('borrower_id', $this->borrower->id)->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();

        // ₱1,000.10 + 0.5% of ₱60,000 = ₱1,300.10. Not the ₱1,200 processing
        // fee, the ₱250 catalog fee or the ₱600 premium.
        $this->assertSame('1300.10', $row->credit);
        $this->assertSame('0.00', $row->debit);
        $this->assertSame($released->id, $row->loan_id);
        $this->assertNull($row->repayment_id);
        $this->assertSame($this->admin->id, $row->created_by);
        $this->assertSame($released->released_at->timezone(config('app.timezone'))->toDateString(), $row->date->toDateString());
        $this->assertSame("Share capital deducted at release of {$released->loan_account_number}", $row->description);

        // The ledger and the books agree to the centavo.
        $journal = AccountingJournal::query()->where('source', 'loan_release')->sole();
        $this->assertSame($journal->date->toDateString(), $row->date->toDateString());
        $this->assertSame(130010, (int) $journal->lines()->where('accounting_account_id', $this->account('3060'))->sum('credit'));
    }

    public function test_a_release_with_no_share_capital_deduction_writes_no_ledger_row(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
            ['name' => 'Service Fee', 'amount' => 1, 'type' => 'percentage'],
        ]);

        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame('released', $loan->fresh()->status);
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_a_share_capital_deduction_of_zero_writes_no_ledger_row(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 0, 'type' => 'fixed'],
        ]);

        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame('released', $loan->fresh()->status);
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_releasing_a_restructure_with_a_share_capital_deduction_credits_the_member(): void
    {
        $source = $this->createReleasedLoan();
        $this->assertSame(0, ShareCapitalLedger::query()->count());

        $response = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            // What createReleasedLoan() leaves owed, so no shortfall.
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
            'deductions' => [['name' => 'Share Capital', 'amount' => 708, 'type' => 'fixed']],
        ])->assertCreated();

        $restructure = Loan::findOrFail($response->json('data.id'));

        // Restructure approval is dual control, so the sign-off is a second user.
        $approver = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::where('name', 'admin')->first()));
        $this->patchJson("/api/loans/{$restructure->id}/submit")->assertOk();
        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$restructure->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);

        $this->patchJson("/api/loans/{$restructure->id}/release")->assertOk();

        $row = ShareCapitalLedger::query()->sole();
        $this->assertSame($restructure->id, $row->loan_id);
        $this->assertSame($source->borrower_id, $row->borrower_id);
        $this->assertSame('708.00', $row->credit);
        $this->assertSame('restructured', $source->fresh()->status);
    }

    public function test_a_non_member_cannot_be_released_a_loan_that_withholds_share_capital(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed'],
        ]);
        // The registration was sent back to pending after the loan was approved.
        DB::table('borrowers')->where('id', $this->borrower->id)->update(['status' => 'pending']);

        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertStatus(422)
            ->assertJsonValidationErrors('deductions');

        $loan->refresh();
        $this->assertSame('approved', $loan->status);
        $this->assertNull($loan->loan_account_number);
        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, $loan->amortizationSchedules()->count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'loan_release')->count());
    }

    public function test_a_non_member_is_still_released_a_loan_that_withholds_no_share_capital(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
        ]);
        DB::table('borrowers')->where('id', $this->borrower->id)->update(['status' => 'pending']);

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

        $this->assertSame('released', $loan->fresh()->status);
    }

    public function test_a_release_that_fails_after_the_credit_leaves_no_ledger_row(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed'],
        ]);

        // The release journal is written after the share capital credit.
        $this->failOnReleaseJournal(['22001', 1406, "Data too long for column 'description' at row 1"]);

        try {
            app(LoanService::class)->release($loan, $this->admin);
            $this->fail('The release did not fail.');
        } catch (QueryException) {
            // Expected: the injected failure.
        }

        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_a_loan_can_carry_at_most_one_release_credit(): void
    {
        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed'],
        ]);
        app(LoanService::class)->release($loan, $this->admin);

        $this->expectException(UniqueConstraintViolationException::class);

        ShareCapitalLedger::create([
            'borrower_id' => $this->borrower->id,
            'loan_id' => $loan->id,
            'date' => now()->toDateString(),
            'description' => 'A second credit for the same release',
            'debit' => 0,
            'credit' => 500,
        ]);
    }

    public function test_a_release_credit_carries_its_own_reference_so_a_same_day_ledger_row_cannot_collide_with_it(): void
    {
        // The dated sequence out of id order, so ShareCapitalLedger::booted()
        // would number the next row SC-<today>-000002, which is taken.
        $today = now()->format('Ymd');
        foreach (['000002', '000001'] as $number) {
            ShareCapitalLedger::factory()->create([
                'borrower_id' => $this->borrower->id,
                'date' => now()->toDateString(),
                'reference' => "SC-{$today}-{$number}",
            ]);
        }

        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed'],
        ]);

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

        $loan->refresh();
        $row = ShareCapitalLedger::query()->where('loan_id', $loan->id)->sole();
        $this->assertSame("SC-{$loan->loan_account_number}", $row->reference);
        $this->assertSame('500.00', $row->credit);
    }

    public function test_the_share_capital_report_and_the_member_balance_include_the_release_credit(): void
    {
        ShareCapitalLedger::factory()->create([
            'borrower_id' => $this->borrower->id,
            'date' => now()->toDateString(),
            'credit' => 1000,
            'debit' => 0,
        ]);

        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 750.25, 'type' => 'fixed'],
        ]);
        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame([$this->borrower->id => 1750.25], ShareCapitalLedger::balancesFor([$this->borrower->id]));

        $today = now()->toDateString();
        $report = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}")->assertOk()->json('data');

        $this->assertEquals(1750.25, $report['credits']);
        $this->assertEquals(1750.25, $report['closing_balance']);
        $this->assertSame(2, $report['entry_count']);

        $member = collect($report['by_member'])->firstWhere('borrower_id', $this->borrower->id);
        $this->assertEquals(1750.25, $member['closing_balance']);

        $statement = $this->getJson("/api/reports/share-capital-statement/{$this->borrower->id}")->assertOk()->json('data');
        $this->assertEquals(1750.25, $statement['closing_balance']);
    }

    public function test_cash_flow_shows_the_release_credit_as_non_cash(): void
    {
        // Money a member actually paid in: cash.
        ShareCapitalLedger::factory()->create([
            'borrower_id' => $this->borrower->id,
            'date' => now()->toDateString(),
            'credit' => 1000,
            'debit' => 0,
        ]);

        $loan = $this->approvedLoan([
            ['name' => 'Share Capital', 'amount' => 750.25, 'type' => 'fixed'],
        ]);
        $released = app(LoanService::class)->release($loan, $this->admin);

        $today = now()->toDateString();
        $flow = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk()->json('data');

        $this->assertEquals(1000, $flow['inflows']['share_capital_credit']);
        $this->assertEquals(1000, $flow['inflows']['total']);
        $this->assertEquals(1000, $flow['share_capital']['credit']);
        $this->assertEquals(1000, $flow['share_capital']['net_movement']);
        $this->assertSame(1, $flow['share_capital']['count']);
        $this->assertEquals(750.25, $flow['non_cash']['share_capital_at_release']);
        $this->assertEquals((float) $released->net_proceeds, $flow['outflows']['total']);
        $this->assertStringContainsString('share_capital_at_release', $flow['non_cash']['note']);
    }

    /**
     * An approved loan for the test's borrower carrying `$deductions`.
     *
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
            'borrower_id' => $this->borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'deductions' => $deductions,
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /**
     * Refuse the INSERT of the first `loan_release` journal with the driver
     * error MySQL raises for `$errorInfo`.
     *
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function failOnReleaseJournal(array $errorInfo): void
    {
        $fired = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $errorInfo): void {
            if ($fired || ! str_starts_with($query, 'insert into `accounting_journals`') || ! in_array('loan_release', $bindings, true)) {
                return;
            }

            $fired = true;

            [$sqlState, $driverCode, $driverMessage] = $errorInfo;
            $pdo = new PDOException("SQLSTATE[{$sqlState}]: General error: {$driverCode} {$driverMessage}");
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlState);

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });
    }
}
