<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/loans/{id}/disclosure states the R.A. 3765 figures correctly.
 *
 * The statement used to call every deduction a finance charge. Only interest
 * and the cooperative's own fees are: notarial fees, insurance premiums and
 * taxes are paid on to somebody else (non-finance charges), and share capital
 * is the member's own equity, deducted from the proceeds but never a charge.
 * The server now sorts each item, discloses one insurance line (the full
 * premium, what was collected at release and what is still owed), reconciles
 * the proceeds to the centavo, and states the nominal and effective interest
 * rates (BSP Circular 730: amount financed = principal less the finance
 * charges deducted; cash flows = each instalment's total due).
 */
class DisclosureFinanceChargesTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<array{name: string, amount: float|int, type: string}>  $deductions
     */
    private function draftLoan(array $product, float $principal, array $deductions): Loan
    {
        $product = LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 12,
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 1000,
        ], $product));

        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'deductions' => $deductions,
        ], $this->admin);
    }

    /** @param  array<string, mixed>  $insurance */
    private function release(Loan $loan, array $insurance = []): Loan
    {
        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        $this->patchJson("/api/loans/{$loan->id}/release", $insurance)->assertOk();

        return $loan->fresh();
    }

    /**
     * ₱50,000 at 3% a month flat for 12 months, with every kind of deduction:
     * processing ₱1,000 (2%), service ₱500, notarial ₱200, share capital
     * ₱1,000, documentary stamp tax ₱50, a ₱300 catalog fee added at release,
     * and a ₱600 insurance premium (1.2%) of which ₱400 is collected at
     * release. ₱3,450 withheld, ₱46,550 handed over.
     */
    private function sampleLoan(): Loan
    {
        Fee::create(['name' => 'Credit Investigation Fee', 'type' => 'fixed', 'value' => 300, 'applicable_product_ids' => null, 'conditions' => null]);

        $loan = $this->draftLoan([], 50000, [
            ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
            ['name' => 'Service Fee', 'amount' => 500, 'type' => 'fixed'],
            ['name' => 'Notarial Fee', 'amount' => 200, 'type' => 'fixed'],
            ['name' => 'Share Capital', 'amount' => 1000, 'type' => 'fixed'],
            ['name' => 'Documentary Stamp Tax', 'amount' => 50, 'type' => 'fixed'],
        ]);

        return $this->release($loan, [
            'insurance_premium_percentage' => 1.2,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 400,
        ]);
    }

    /** @return array<string, mixed> */
    private function disclosure(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');
    }

    public function test_finance_and_non_finance_charges_and_proceeds_are_sorted_and_reconcile(): void
    {
        $loan = $this->sampleLoan();
        $this->assertEquals(3450, (float) $loan->total_deductions);
        $this->assertEquals(46550, (float) $loan->net_proceeds);

        $data = $this->disclosure($loan);

        // Interest ₱1,500 × 12 plus the cooperative's own fees.
        $this->assertEquals([
            'interest' => 18000,
            'items' => [
                ['name' => 'Processing Fee', 'amount' => 1000],
                ['name' => 'Service Fee', 'amount' => 500],
                ['name' => 'Credit Investigation Fee', 'amount' => 300],
            ],
            'total' => 19800,
        ], $data['finance_charges']);

        $this->assertEquals([
            'items' => [
                ['name' => 'Notarial Fee', 'amount' => 200, 'deducted' => 200, 'owed' => 0],
                ['name' => 'Documentary Stamp Tax', 'amount' => 50, 'deducted' => 50, 'owed' => 0],
                ['name' => 'Insurance Premium', 'amount' => 600, 'deducted' => 400, 'owed' => 200],
            ],
            'total' => 850,
        ], $data['non_finance_charges']);

        $this->assertEquals([
            'principal' => 50000,
            'finance_charges_deducted' => 1800,
            'non_finance_charges_deducted' => 650,
            'share_capital' => 1000,
            'net_proceeds' => 46550,
        ], $data['proceeds']);

        $proceeds = $data['proceeds'];
        $this->assertSame(
            (int) round($proceeds['net_proceeds'] * 100),
            (int) round($proceeds['principal'] * 100) - (int) round($proceeds['finance_charges_deducted'] * 100)
                - (int) round($proceeds['non_finance_charges_deducted'] * 100) - (int) round($proceeds['share_capital'] * 100),
        );

        // Corrected: interest plus the cooperative's own fees, not every
        // deduction (the old figure was ₱3,450 + ₱18,000 = ₱21,450).
        $this->assertEquals(19800, $data['totals']['total_finance_charges']);
        $this->assertEquals(850, $data['totals']['total_non_finance_charges']);
        // Every key the printable already reads is still there, unchanged.
        $this->assertEquals(3450, $data['totals']['total_deductions']);
        $this->assertEquals(46550, $data['totals']['net_proceeds']);
        $this->assertEquals(18000, $data['totals']['total_interest']);
        $this->assertEquals(50000, $data['totals']['total_principal']);
        $this->assertEquals(0, $data['totals']['unitemised_deductions']);
        $this->assertCount(7, $data['deductions']['items']);
    }

    public function test_the_rates_state_the_nominal_and_effective_interest_rate(): void
    {
        $data = $this->disclosure($this->sampleLoan());

        // Amount financed ₱50,000 − ₱1,800 = ₱48,200, repaid by 11 × ₱5,666.67
        // and a last ₱5,666.63. Reference, solved independently with Newton's
        // method (Python): r = 5.737381% a month, 68.848571% a year.
        $this->assertEquals([
            'contractual_rate' => 3,
            'rate_frequency' => 'monthly',
            'nominal_annual_rate' => 36,
            'effective_rate_monthly' => 5.7374,
            'effective_rate_annual' => 68.8486,
        ], $data['rates']);
    }

    public function test_the_nominal_annual_rate_is_the_contractual_rate_times_its_periods_never_rounded(): void
    {
        // 1.1234% a month is 13.4808% a year, and 1.6667% is 20.0004%: exact,
        // to the rate's own four places, not cut to fewer.
        foreach ([[1.1234, 13.4808], [1.6667, 20.0004]] as [$monthly, $annual]) {
            $loan = $this->release($this->draftLoan(['interest_rate' => $monthly], 12000, []));

            $this->assertSame($annual, $this->disclosure($loan)['rates']['nominal_annual_rate']);
        }
    }

    public function test_the_effective_rate_of_a_textbook_add_on_loan(): void
    {
        // ₱12,000 at 1% a month add-on (flat) for 12 months, nothing withheld:
        // 12 × ₱1,120. The textbook case: a 12% add-on rate for a year is an
        // APR of about 21.46%. Excel RATE(12, -1120, 12000) = 1.788099% a
        // month; × 12 = 21.457184% a year.
        $loan = $this->release($this->draftLoan(['interest_rate' => 1.0], 12000, []));

        $rates = $this->disclosure($loan)['rates'];

        $this->assertEquals(12, $rates['nominal_annual_rate']);
        $this->assertEquals(1.7881, $rates['effective_rate_monthly']);
        $this->assertEquals(21.4572, $rates['effective_rate_annual']);
    }

    public function test_a_diminishing_loan_with_nothing_withheld_has_its_own_rate_as_the_effective_rate(): void
    {
        $loan = $this->release($this->draftLoan(['interest_method' => 'diminishing'], 50000, []));

        $data = $this->disclosure($loan);

        $this->assertEquals([], $data['finance_charges']['items']);
        $this->assertEquals(['items' => [], 'total' => 0], $data['non_finance_charges']);
        $this->assertEquals(0, $data['totals']['total_non_finance_charges']);
        $this->assertEquals($data['totals']['total_interest'], $data['totals']['total_finance_charges']);
        // 3% a month is 36% a year, nominal and effective alike.
        $this->assertEquals(3, $data['rates']['effective_rate_monthly']);
        $this->assertEquals(36, $data['rates']['effective_rate_annual']);
        $this->assertEquals(36, $data['rates']['nominal_annual_rate']);
    }

    public function test_an_unitemised_remainder_is_a_finance_charge_named_by_its_sign(): void
    {
        $loan = $this->release($this->draftLoan([], 50000, [['name' => 'Processing Fee', 'amount' => 1000, 'type' => 'fixed']]));

        // ₱50.25 withheld that no item explains.
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 1050.25, 'net_proceeds' => 48949.75]);
        $data = $this->disclosure($loan);

        $this->assertEquals([
            ['name' => 'Processing Fee', 'amount' => 1000],
            ['name' => 'Other Charges', 'amount' => 50.25],
        ], $data['finance_charges']['items']);
        $this->assertEquals(18000 + 1050.25, $data['finance_charges']['total']);
        $this->assertEquals(18000 + 1050.25, $data['totals']['total_finance_charges']);
        $this->assertEquals(1050.25, $data['proceeds']['finance_charges_deducted']);
        $this->assertEquals(48949.75, $data['proceeds']['net_proceeds']);

        // Items ₱49.25 above the stated total.
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 950.75, 'net_proceeds' => 49049.25]);
        $data = $this->disclosure($loan);

        $this->assertEquals([
            ['name' => 'Processing Fee', 'amount' => 1000],
            ['name' => 'Adjustment', 'amount' => -49.25],
        ], $data['finance_charges']['items']);
        $this->assertEquals(18000 + 950.75, $data['totals']['total_finance_charges']);
        $this->assertEquals(950.75, $data['proceeds']['finance_charges_deducted']);
        $this->assertEquals(49049.25, $data['proceeds']['net_proceeds']);
    }

    public function test_a_hand_typed_insurance_premium_with_no_premium_on_the_loan_is_disclosed_as_deducted(): void
    {
        $loan = $this->release($this->draftLoan([], 50000, [['name' => 'Insurance Premium', 'amount' => 750.5, 'type' => 'fixed']]));

        $data = $this->disclosure($loan);

        $this->assertEquals([
            'items' => [['name' => 'Insurance Premium', 'amount' => 750.5, 'deducted' => 750.5, 'owed' => 0]],
            'total' => 750.5,
        ], $data['non_finance_charges']);
        $this->assertEquals(18000, $data['totals']['total_finance_charges']);
        $this->assertEquals(49249.5, $data['proceeds']['net_proceeds']);
    }

    public function test_a_premium_with_nothing_collected_at_release_is_disclosed_as_owed(): void
    {
        $loan = $this->release($this->draftLoan([], 50000, []), [
            'insurance_premium_percentage' => 1.2,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 0,
        ]);

        $data = $this->disclosure($loan);

        $this->assertEquals([
            'items' => [['name' => 'Insurance Premium', 'amount' => 600, 'deducted' => 0, 'owed' => 600]],
            'total' => 600,
        ], $data['non_finance_charges']);
        $this->assertEquals(0, $data['proceeds']['non_finance_charges_deducted']);
        $this->assertEquals(50000, $data['proceeds']['net_proceeds']);
    }

    public function test_an_approved_loan_is_disclosed_from_its_computed_schedule(): void
    {
        $loan = $this->draftLoan(['interest_rate' => 1.0], 12000, []);
        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        $data = $this->disclosure($loan->fresh());

        $this->assertCount(0, $loan->fresh()->amortizationSchedules);
        $this->assertEquals(21.4572, $data['rates']['effective_rate_annual']);
        $this->assertEquals(['items' => [], 'total' => 0], $data['non_finance_charges']);
    }

    public function test_a_loan_paid_at_maturity_annualises_by_its_own_term(): void
    {
        // ₱10,000 at 2% a month for 3 months, one ₱10,600 payment: 6% for a
        // period of 3 months, 4 such periods a year.
        $months = $this->release($this->draftLoan(['interest_rate' => 2.0, 'frequency' => 'upon_maturity', 'term' => 3], 10000, []));

        $this->assertEquals([
            'contractual_rate' => 2,
            'rate_frequency' => 'monthly',
            'nominal_annual_rate' => 24,
            'effective_rate_monthly' => 2,
            'effective_rate_annual' => 24,
        ], $this->disclosure($months)['rates']);

        // ₱10,000 at 0.1% a day for 45 days, one ₱10,450 payment: 4.5% for a
        // period of 45 days, 365 / 45 of them a year.
        $days = $this->release($this->draftLoan([
            'interest_rate' => 0.1,
            'interest_rate_frequency' => 'daily',
            'frequency' => 'upon_maturity',
            'term' => 45,
            'term_unit' => 'days',
        ], 10000, []));

        $this->assertEquals([
            'contractual_rate' => 0.1,
            'rate_frequency' => 'daily',
            'nominal_annual_rate' => 36.5,
            'effective_rate_monthly' => 3.0417,
            'effective_rate_annual' => 36.5,
        ], $this->disclosure($days)['rates']);
    }

    public function test_a_weekly_loan_annualises_by_its_payment_and_rate_periods(): void
    {
        // 1% a week, diminishing, paid weekly over 12 weeks: 52 periods a year.
        $loan = $this->release($this->draftLoan([
            'interest_rate' => 1.0,
            'interest_rate_frequency' => 'weekly',
            'interest_method' => 'diminishing',
            'frequency' => 'weekly',
            'term' => 84,
            'term_unit' => 'days',
        ], 12000, []));

        $rates = $this->disclosure($loan)['rates'];

        $this->assertSame('weekly', $rates['rate_frequency']);
        $this->assertEquals(52, $rates['nominal_annual_rate']);
        // 11 × ₱1,066.19 and a last ₱1,066.14: the instalments are rounded to
        // the centavo, which puts their own rate a hair above 1% a week.
        // Reference, solved independently with Newton's method (Python):
        // r = 1.000008809% a week, × 52 = 52.000458% a year.
        $this->assertEquals(52.0005, $rates['effective_rate_annual']);
        $this->assertEquals(4.3334, $rates['effective_rate_monthly']);
    }
}
