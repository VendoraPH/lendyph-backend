<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans/{id}/extension-preview quotes the premium owed that an
 * extension on "pay" collects first (`collect_now.insurance_part`, inside
 * `collect_now.amount`), and quotes exactly what POST /extend then records.
 */
class InsuranceOwedExtensionPreviewTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** One-month upon-maturity loan owing ₱400 premium; period current. */
    private function extendableLoanOwingPremium(?string $dueDate = null): Loan
    {
        $loan = $this->loanOwingPremium(['interest_method' => 'upon_maturity', 'term' => 1]);
        $loan->amortizationSchedules()->update([
            'due_date' => $dueDate ?? now()->addWeek()->toDateString(),
            'status' => 'pending',
        ]);

        return $loan->fresh();
    }

    /** @return array<string, mixed> */
    private function preview(Loan $loan, string $option): array
    {
        return $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option={$option}")
            ->assertOk()
            ->json('data');
    }

    public function test_pay_quotes_the_premium_first_then_the_interest(): void
    {
        $loan = $this->extendableLoanOwingPremium();

        $collect = $this->preview($loan, 'pay')['collect_now'];

        $this->assertEqualsWithDelta(self::OWED, (float) ($collect['insurance_part'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(1800.00, (float) $collect['interest_part'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $collect['penalty_part'], 0.001);
        $this->assertEqualsWithDelta(1800.00 + self::OWED, (float) $collect['amount'], 0.001);

        // Writes nothing.
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertEqualsWithDelta(self::OWED, $this->owed($loan), 0.001);
    }

    public function test_defer_collects_nothing_and_leaves_the_premium_owed(): void
    {
        $loan = $this->extendableLoanOwingPremium();

        $this->assertEquals(
            ['amount' => 0, 'insurance_part' => 0, 'penalty_part' => 0, 'interest_part' => 0],
            $this->preview($loan, 'defer')['collect_now'],
        );

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $this->assertEqualsWithDelta(self::OWED, $this->owed($loan), 0.001);
    }

    public function test_the_preview_equals_what_the_extension_records_on_an_overdue_loan(): void
    {
        // Past due and past grace: the payment charges a penalty, after the premium.
        $loan = $this->extendableLoanOwingPremium(now()->subDays(10)->toDateString());

        $preview = $this->preview($loan, 'pay');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $repayment = Repayment::where('loan_id', $loan->id)->firstOrFail();
        $adjustment = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->firstOrFail();

        $this->assertGreaterThan(0, (float) $repayment->penalty_applied, 'setup: an overdue loan owes a penalty');
        $this->assertEquals((float) $repayment->amount_paid, $preview['collect_now']['amount']);
        $this->assertEquals((float) $repayment->insurance_applied, $preview['collect_now']['insurance_part'] ?? null);
        $this->assertEquals((float) $repayment->penalty_applied, $preview['collect_now']['penalty_part']);
        $this->assertEquals((float) $repayment->interest_applied, $preview['collect_now']['interest_part']);
        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->insurance_applied, 0.001);
        $this->assertEquals($adjustment->new_values['carry_interest'], $preview['carry_interest']);
        $this->assertEquals($adjustment->new_values['fresh_interest'], $preview['fresh_interest']);
        $this->assertEqualsWithDelta(self::OWED, (float) ($adjustment->new_values['insurance_paid'] ?? 0), 0.001);
    }
}
