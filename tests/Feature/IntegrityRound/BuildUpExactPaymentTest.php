<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Decision 2a (Arvin, 2026-11-02): on a Share Capital Build-Up loan, a payment
 * whose overpayment is exactly the build-up the allocation credits to share
 * capital for the period it settles is recorded `exact` and badged exact.
 *
 * A browser check paid the 1-period preset on a build-up loan (₱5,900.00 due
 * plus the ₱250.00 build-up, ₱6,150.00) and saw "Advance Payment".
 *
 * The loan: ₱30,000.00 over 6 monthly periods at 3% straight (₱5,000.00
 * principal and ₱900.00 interest a period), ₱250.00 build-up a period.
 */
class BuildUpExactPaymentTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-11-02 09:00:00');
    }

    private function buildUpLoan(?Borrower $borrower = null): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => ($borrower ?? Borrower::factory()->create(['branch_id' => $this->branch->id]))->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 30000,
            'scb_amount' => 250,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin);

        return $loan->fresh();
    }

    /** @return array<string, mixed> */
    private function preview(Loan $loan, float $amount): array
    {
        return $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => $amount,
            'payment_date' => now()->toDateString(),
        ])->assertOk()->json('data');
    }

    private function pay(Loan $loan, float $amount): Repayment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    public function test_a_member_paying_the_period_plus_its_build_up_is_exact(): void
    {
        $loan = $this->buildUpLoan();

        $preset = (float) $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data.advance_presets.0.amount');
        $this->assertEqualsWithDelta(6150.00, $preset, 0.001, 'setup: ₱5,900.00 due plus the ₱250.00 build-up');

        $preview = $this->preview($loan, $preset);

        $this->assertSame(['type' => 'exact', 'label' => 'Exact Payment'], $preview['payment_badge']);
        $this->assertSame('exact', $preview['payment_type']);
        $this->assertSame([1], array_column($preview['allocations'], 'period'));

        $repayment = $this->pay($loan, $preset);

        $this->assertSame('exact', $repayment->payment_type);
        $this->assertEqualsWithDelta(250.00, (float) $repayment->overpayment, 0.001);
        $this->assertEqualsWithDelta(250.00, (float) ShareCapitalLedger::where('repayment_id', $repayment->id)->sum('credit'), 0.001);
    }

    public function test_a_non_members_extra_stays_an_overpayment_as_today(): void
    {
        // A pending borrower is not a member yet: nothing is credited to share
        // capital, so the ₱250.00 is an ordinary overpayment.
        $loan = $this->buildUpLoan(Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']));

        $preview = $this->preview($loan, 6150.00);

        $this->assertSame(['type' => 'advance', 'label' => 'Advance Payment'], $preview['payment_badge']);
        $this->assertSame('partial', $preview['payment_type']);
        $this->assertEqualsWithDelta(250.00, (float) $preview['excess'], 0.001);

        $repayment = $this->pay($loan, 6150.00);

        $this->assertSame('partial', $repayment->payment_type);
        $this->assertEqualsWithDelta(250.00, (float) $repayment->overpayment, 0.001);
        $this->assertSame(0, ShareCapitalLedger::where('repayment_id', $repayment->id)->count());
    }

    public function test_an_extra_beyond_the_build_up_is_advance(): void
    {
        $loan = $this->buildUpLoan();

        $preview = $this->preview($loan, 6150.01);

        $this->assertSame(['type' => 'advance', 'label' => 'Advance Payment'], $preview['payment_badge']);
        $this->assertNotSame('exact', $preview['payment_type']);

        $repayment = $this->pay($loan, 6150.01);

        $this->assertNotSame('exact', $repayment->payment_type);
        $this->assertEqualsWithDelta(250.01, (float) ShareCapitalLedger::where('repayment_id', $repayment->id)->sum('credit'), 0.001);
    }

    public function test_less_than_the_build_up_is_not_exact_either(): void
    {
        $loan = $this->buildUpLoan();

        $preview = $this->preview($loan, 6149.99);

        $this->assertSame(['type' => 'advance', 'label' => 'Advance Payment'], $preview['payment_badge']);
        $this->assertNotSame('exact', $preview['payment_type']);
    }
}
