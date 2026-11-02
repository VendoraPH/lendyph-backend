<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Loan;
use App\Models\Repayment;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Decision 2c (Arvin, 2026-11-02): a payment amount has two decimals at most.
 * More is refused with a 422 on `amount_paid`, on POST repayments and on the
 * preview, never rounded silently.
 */
class PaymentAmountDecimalsTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-11-02 09:00:00');
        $this->loan = $this->createReleasedLoan();
    }

    public function test_a_payment_with_three_decimals_is_refused(): void
    {
        $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => 100.005,
            'payment_date' => '2026-11-02',
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount_paid');

        $this->assertSame(0, Repayment::where('loan_id', $this->loan->id)->count());
    }

    public function test_a_preview_with_three_decimals_is_refused(): void
    {
        $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 100.005,
            'payment_date' => '2026-11-02',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount_paid');
    }

    public function test_two_decimals_are_accepted(): void
    {
        $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 100.50,
            'payment_date' => '2026-11-02',
        ])->assertOk()->assertJsonPath('data.amount_paid', 100.5);

        $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => 100.50,
            'payment_date' => '2026-11-02',
            'method' => 'cash',
        ])->assertCreated();

        $this->assertEqualsWithDelta(100.50, (float) Repayment::where('loan_id', $this->loan->id)->sole()->amount_paid, 0.001);
    }

    public function test_a_whole_amount_sent_as_a_string_with_two_decimals_is_accepted(): void
    {
        $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => '6150.00',
            'payment_date' => '2026-11-02',
        ])->assertOk();
    }
}
