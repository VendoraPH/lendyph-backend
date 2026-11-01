<?php

namespace Tests\Feature\ServerFigures;

use App\Models\AccountingJournal;
use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\LoanService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans/{loan}/extension-preview?interest_option=pay|defer: the Extend
 * Loan dialog's figures, computed by the server with the arithmetic
 * POST /loans/{loan}/extend records, so the dialog can never quote a figure
 * the extension does not charge.
 *
 * The dialog used to compute `outstanding principal × rate / 100` in the
 * browser, ignoring the rate's frequency (1% a week was quoted as 1% a month)
 * and the penalty-first allocation of the 'pay' option.
 */
class ExtensionPreviewTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-11-02 10:00:00');
    }

    private function uponMaturityLoan(array $product = [], float $principal = 60000, ?string $startDate = '2026-04-27'): Loan
    {
        return $this->createReleasedLoan([
            'product' => array_merge([
                'interest_method' => 'upon_maturity',
                'term' => 1,
                'frequency' => 'monthly',
                'interest_rate' => 3.0,
            ], $product),
            'principal_amount' => $principal,
            'start_date' => $startDate,
        ]);
    }

    /**
     * The loan's own rows, its periods' paid columns and statuses, its status,
     * and the audit log, journal and share capital ledger row counts across
     * every loan (a preview writes to none of them).
     *
     * @return array<string, mixed>
     */
    private function writeSnapshot(Loan $loan): array
    {
        return [
            'adjustments' => LoanAdjustment::where('loan_id', $loan->id)->count(),
            'repayments' => Repayment::where('loan_id', $loan->id)->count(),
            'schedules' => AmortizationSchedule::where('loan_id', $loan->id)->count(),
            'ledger' => LoanLedgerEntry::where('loan_id', $loan->id)->count(),
            'periods' => AmortizationSchedule::where('loan_id', $loan->id)
                ->orderBy('id')
                ->get(['id', 'principal_paid', 'interest_paid', 'penalty_paid', 'penalty_amount', 'status'])
                ->toArray(),
            'loan_status' => Loan::whereKey($loan->id)->value('status'),
            'audit_logs' => AuditLog::count(),
            'journals' => AccountingJournal::count(),
            'share_capital_ledger' => ShareCapitalLedger::count(),
        ];
    }

    /**
     * A one-month upon-maturity loan, approved but not released.
     */
    private function approvedOneMonthLoan(): Loan
    {
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create([
                'interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0,
            ])->id,
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    public function test_it_quotes_a_month_at_the_converted_rate_not_the_raw_rate(): void
    {
        // 70,000 at 1% a week: one 30-day month is 70,000 × 1% × 30/7 = 3,000,
        // not the 700 the browser's `principal × rate / 100` showed.
        $loan = $this->uponMaturityLoan(['interest_rate' => 1.0, 'interest_rate_frequency' => 'weekly'], 70000);

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")
            ->assertOk()
            ->json('data');

        $this->assertEquals(70000, $preview['carry_principal']);
        $this->assertEquals(3000, $preview['carry_interest']);
        $this->assertEquals(3000, $preview['fresh_interest']);
        $this->assertEquals(6000, $preview['new_interest_due']);
        $this->assertEquals(76000, $preview['new_total_due']);
        $this->assertEquals(['amount' => 0, 'penalty_part' => 0, 'interest_part' => 0], $preview['collect_now']);
    }

    public function test_the_defer_preview_equals_what_the_extension_then_records(): void
    {
        $loan = $this->uponMaturityLoan(['interest_rate' => 1.0, 'interest_rate_frequency' => 'weekly'], 70000);

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")
            ->assertOk()
            ->json('data');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $recorded = LoanAdjustment::where('loan_id', $loan->id)->firstOrFail()->new_values;
        $newPeriod = $loan->fresh()->amortizationSchedules()->reorder('period_number', 'desc')->firstOrFail();

        $this->assertEquals($recorded['carry_principal'], $preview['carry_principal']);
        $this->assertEquals($recorded['carry_interest'], $preview['carry_interest']);
        $this->assertEquals($recorded['fresh_interest'], $preview['fresh_interest']);
        $this->assertSame($recorded['new_due_date'], $preview['new_due_date']);
        $this->assertEquals((float) $newPeriod->interest_due, $preview['new_interest_due']);
        $this->assertEquals((float) $newPeriod->total_due, $preview['new_total_due']);
    }

    public function test_the_pay_preview_on_an_overdue_loan_takes_the_penalty_first_exactly_as_the_extension_does(): void
    {
        // Due 2026-05-27 and still unpaid on 2026-11-02, so a penalty is owed.
        // The 'pay' collection is the outstanding interest; allocation takes
        // the penalty out of it first and the rest of the interest carries.
        $loan = $this->uponMaturityLoan();

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")
            ->assertOk()
            ->json('data');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $repayment = Repayment::where('loan_id', $loan->id)->firstOrFail();
        $recorded = LoanAdjustment::where('loan_id', $loan->id)->firstOrFail()->new_values;
        $newPeriod = $loan->fresh()->amortizationSchedules()->reorder('period_number', 'desc')->firstOrFail();

        $this->assertGreaterThan(0, $preview['collect_now']['penalty_part'], 'an overdue loan owes a penalty');
        $this->assertEquals((float) $repayment->amount_paid, $preview['collect_now']['amount']);
        $this->assertEquals((float) $repayment->penalty_applied, $preview['collect_now']['penalty_part']);
        $this->assertEquals((float) $repayment->interest_applied, $preview['collect_now']['interest_part']);
        $this->assertEquals($recorded['carry_principal'], $preview['carry_principal']);
        $this->assertEquals($recorded['carry_interest'], $preview['carry_interest']);
        $this->assertEquals($recorded['fresh_interest'], $preview['fresh_interest']);
        $this->assertEquals((float) $newPeriod->interest_due, $preview['new_interest_due']);
        $this->assertEquals((float) $newPeriod->total_due, $preview['new_total_due']);
        $this->assertSame($recorded['new_due_date'], $preview['new_due_date']);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $loan = $this->uponMaturityLoan();
        $before = $this->writeSnapshot($loan);
        $penaltyBefore = AmortizationSchedule::where('loan_id', $loan->id)->sum('penalty_amount');

        foreach (['pay', 'defer'] as $option) {
            $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option={$option}")->assertOk();
        }

        $this->assertSame($before, $this->writeSnapshot($loan));
        $this->assertEquals($penaltyBefore, AmortizationSchedule::where('loan_id', $loan->id)->sum('penalty_amount'));
        $this->assertSame('2026-05-27', $loan->fresh()->maturity_date->toDateString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedLoans(): array
    {
        return [
            'not a one-month term' => ['three_month_term', 'term'],
            'not released or ongoing' => ['approved', 'status'],
            'no open period' => ['no_open_period', 'loan'],
        ];
    }

    #[DataProvider('refusedLoans')]
    public function test_the_preview_refuses_with_the_same_422_as_the_extension(string $case, string $errorKey): void
    {
        $loan = match ($case) {
            'three_month_term' => $this->uponMaturityLoan(['term' => 3, 'max_term' => 3]),
            'approved' => $this->approvedOneMonthLoan(),
            'no_open_period' => $this->uponMaturityLoan(),
        };

        if ($case === 'no_open_period') {
            AmortizationSchedule::where('loan_id', $loan->id)->update(['status' => 'paid']);
        }

        foreach (['pay', 'defer'] as $option) {
            $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option={$option}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorKey);

            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => $option])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorKey);
        }
    }

    public function test_the_preview_states_the_interest_option(): void
    {
        $loan = $this->uponMaturityLoan();

        $this->getJson("/api/loans/{$loan->id}/extension-preview")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('interest_option');
    }

    public function test_a_user_without_loans_extend_gets_403(): void
    {
        $loan = $this->uponMaturityLoan();

        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);

        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertForbidden();
    }
}
