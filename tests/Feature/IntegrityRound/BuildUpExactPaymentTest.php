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
 * Advance wins (lead, 2026-11-02): a payment that reaches any period past
 * what is owed now (the periods due, or the first open one when none is due)
 * is advance, whatever the build-up. Otherwise "exact" counts the build-up of
 * every owed period the payment settles, as `amount_due_now` does. The stored
 * `payment_type` and the preview badge agree on exact and advance.
 *
 * The loan: ₱30,000.00 over 6 monthly periods at 3% straight (₱5,000.00
 * principal and ₱900.00 interest a period), ₱250.00 build-up a period, 2%
 * penalty after a 3-day grace.
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

    /**
     * Released on `$startDate` (today by default); the clock is then put back
     * on `$payOn`.
     */
    private function buildUpLoan(?Borrower $borrower = null, ?string $startDate = null, int $term = 6, string $payOn = '2026-11-02'): Loan
    {
        $this->travelTo(($startDate ?? $payOn).' 09:00:00');

        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => $term,
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
        $this->travelTo($payOn.' 09:00:00');

        return $loan->fresh();
    }

    /** @return array<string, mixed> */
    private function amountDueNow(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.amount_due_now');
    }

    /**
     * The preview and the payment it previews, with the badge and both
     * payment types saying `$type`.
     */
    private function assertPreviewAndPaymentSay(string $type, Loan $loan, float $amount): Repayment
    {
        $preview = $this->preview($loan, $amount);
        $this->assertSame($type, $preview['payment_badge']['type'], 'the preview badge');
        $this->assertSame($type, $preview['payment_type'], 'the previewed payment_type');

        $repayment = $this->pay($loan, $amount);
        $this->assertSame($type, $repayment->payment_type, 'the stored payment_type');

        return $repayment;
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

    public function test_a_payment_reaching_a_period_not_yet_due_is_advance_whatever_the_build_up(): void
    {
        // Released 22 Sep: period 1 fell due 22 Oct and is past grace on
        // 2 Nov, period 2 falls due 22 Nov. Paying both plus ₱500.00 (two
        // build-ups' worth) still pays period 2 before it is due.
        $loan = $this->buildUpLoan(startDate: '2026-09-22');
        $due = $this->amountDueNow($loan);
        $this->assertSame(1, $due['period_number'], 'setup: only period 1 is owed');
        $this->assertGreaterThan(0, (float) $due['penalty'], 'setup: period 1 is past grace');
        $this->assertEqualsWithDelta(0.0, (float) $due['share_capital_build_up'], 0.001, 'setup: no build-up on an overdue period before the current one');

        $amount = round((float) $due['amount'] + 5900.00 + 500.00, 2);
        $repayment = $this->assertPreviewAndPaymentSay('advance', $loan, $amount);

        $this->assertEqualsWithDelta(500.00, (float) $repayment->overpayment, 0.001);
        $this->assertSame([1, 2], $repayment->allocations()->orderBy('period_number')->pluck('period_number')->all());
    }

    public function test_every_period_overdue_paid_with_one_build_up_each_is_exact(): void
    {
        // Two periods, due 15 Sep and 15 Oct, both past grace on 2 Nov: the
        // amount due now is both periods, their penalties and two build-ups.
        $loan = $this->buildUpLoan(startDate: '2026-08-15', term: 2);
        $due = $this->amountDueNow($loan);
        $this->assertSame([1, 2], [$due['period_number'], $due['last_period_number']], 'setup: both periods are owed');
        $this->assertEqualsWithDelta(500.00, (float) $due['share_capital_build_up'], 0.001, 'setup: one build-up per period');

        $repayment = $this->assertPreviewAndPaymentSay('exact', $loan, (float) $due['amount']);

        $this->assertGreaterThan(0, (float) $repayment->penalty_applied);
        $this->assertEqualsWithDelta(500.00, (float) $repayment->overpayment, 0.001);
        $this->assertEqualsWithDelta(500.00, (float) ShareCapitalLedger::where('repayment_id', $repayment->id)->sum('credit'), 0.001);
    }

    public function test_every_period_overdue_paid_with_one_build_up_less_is_not_exact(): void
    {
        $loan = $this->buildUpLoan(startDate: '2026-08-15', term: 2);
        $amount = round((float) $this->amountDueNow($loan)['amount'] - 250.00, 2);

        $preview = $this->preview($loan, $amount);

        $this->assertSame('advance', $preview['payment_badge']['type']);
        $this->assertNotSame('exact', $preview['payment_type']);
    }

    public function test_paying_the_period_plus_its_build_up_on_its_due_date_is_exact(): void
    {
        // Released 2 Oct: period 1 falls due today, 2 Nov.
        $loan = $this->buildUpLoan(startDate: '2026-10-02');
        $due = $this->amountDueNow($loan);
        $this->assertSame(1, $due['period_number']);
        $this->assertEqualsWithDelta(6150.00, (float) $due['amount'], 0.001, 'setup: ₱5,900.00 due plus the ₱250.00 build-up');

        $repayment = $this->assertPreviewAndPaymentSay('exact', $loan, 6150.00);

        $this->assertEqualsWithDelta(250.00, (float) $repayment->overpayment, 0.001);
    }

    public function test_past_grace_the_penalty_comes_first_and_only_the_full_amount_is_exact(): void
    {
        // A one-period loan (₱30,000.00 principal and ₱900.00 interest), due
        // 22 Oct and past grace on 2 Nov: it is the current period, so its
        // build-up is owed with it.
        $loan = $this->buildUpLoan(startDate: '2026-09-22', term: 1);
        $due = $this->amountDueNow($loan);
        $penalty = (float) $due['penalty'];
        $this->assertGreaterThan(0, $penalty, 'setup: past grace');
        $this->assertEqualsWithDelta(30900.00 + $penalty + 250.00, (float) $due['amount'], 0.001);

        // ₱31,150.00, the period and its build-up without the penalty: the
        // penalty takes its part first, so the period is left short.
        $short = $this->preview($loan, 31150.00);
        $this->assertSame('partial', $short['payment_badge']['type']);
        $this->assertSame('partial', $short['payment_type']);
        $this->assertEqualsWithDelta($penalty, (float) $short['total_penalty'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $short['excess'], 0.001);

        $repayment = $this->assertPreviewAndPaymentSay('exact', $loan, (float) $due['amount']);

        $this->assertEqualsWithDelta($penalty, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(250.00, (float) $repayment->overpayment, 0.001);
    }
}
