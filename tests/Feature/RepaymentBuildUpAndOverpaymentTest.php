<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 3 follow-ups, item B6 (carries over part of Augustin's #451). The
 * receipt shows a "Share Capital Build-Up" row and an "Advance / Overpayment"
 * row whose lines add up to Total Paid. On a build-up loan the whole
 * overpayment becomes the build-up, so `scb_paid` and `overpayment` side by
 * side count that money twice. Each repayment row therefore carries
 * `build_up_credited` (share capital this payment credited, from its ledger
 * row) and `overpayment_kept` (what stayed as advance or overpayment), as
 * 2-decimal strings. Principal, interest, penalty, premium, build-up and
 * overpayment kept add up to the amount received. A row whose split the
 * stored data can't tell sends null, never ₱0.00 as a guess. Display only:
 * no calculation or stored data changes.
 */
class RepaymentBuildUpAndOverpaymentTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function pay(Loan $loan, float $amount): TestResponse
    {
        return $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated();
    }

    /** Every place the repayment is read: the detail, the loan's list and the payments list. */
    private function rowsFor(int $repaymentId, Loan $loan): array
    {
        return [
            'detail' => $this->getJson("/api/repayments/{$repaymentId}")->assertOk()->json('data'),
            'loan list' => collect($this->getJson("/api/loans/{$loan->id}/repayments")->assertOk()->json('data'))->firstWhere('id', $repaymentId),
            'list' => collect($this->getJson('/api/repayments?per_page=100')->assertOk()->json('data'))->firstWhere('id', $repaymentId),
        ];
    }

    /** principal + interest + penalty + premium + build-up + overpayment kept, in centavos, equals the amount received. */
    private function assertAddsUp(array $row): void
    {
        $parts = ['principal_applied', 'interest_applied', 'penalty_applied', 'insurance_applied'];
        $sum = array_sum(array_map(fn (string $key): int => LoanService::toCentavos((float) $row[$key]), $parts))
            + LoanService::toCentavos((float) $row['build_up_credited'])
            + LoanService::toCentavos((float) $row['overpayment_kept']);

        $this->assertSame(LoanService::toCentavos((float) $row['amount_paid']), $sum);
    }

    /** @param  array{0: string|null, 1: string|null}  $expected */
    private function assertSplit(int $repaymentId, Loan $loan, array $expected, bool $addsUp = true): void
    {
        foreach ($this->rowsFor($repaymentId, $loan) as $where => $row) {
            $this->assertNotNull($row, "{$where} lists the repayment");
            $this->assertSame($expected, [$row['build_up_credited'], $row['overpayment_kept']], $where);

            if ($addsUp) {
                $this->assertAddsUp($row);
            }
        }
    }

    public function test_a_build_up_loan_payment_with_extra_credits_the_extra_as_build_up(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $first = $loan->amortizationSchedules->first();

        $id = $this->pay($loan, (float) $first->total_due + 750)->json('data.id');

        $this->assertSplit($id, $loan, ['750.00', '0.00']);
    }

    public function test_a_non_build_up_overpayment_is_kept_as_overpayment(): void
    {
        $loan = $this->createReleasedLoan();
        $total = (float) $loan->amortizationSchedules->sum('total_due');

        $id = $this->pay($loan, $total + 300)->json('data.id');

        $this->assertSplit($id, $loan, ['0.00', '300.00']);
    }

    public function test_an_exact_payment_has_neither(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);

        $id = $this->pay($loan, (float) $loan->amortizationSchedules->first()->total_due)->json('data.id');

        $this->assertSplit($id, $loan, ['0.00', '0.00']);
    }

    public function test_a_voided_payment_keeps_what_it_credited_and_still_adds_up(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $id = $this->pay($loan, (float) $loan->amortizationSchedules->first()->total_due + 750)->json('data.id');

        $this->patchJson("/api/repayments/{$id}/void", ['void_reason' => 'Wrong loan'])->assertOk();

        $detail = $this->getJson("/api/repayments/{$id}")->assertOk()->json('data');
        $this->assertSame('voided', $detail['status']);
        $this->assertEquals(0, $detail['scb_paid'], 'scb_paid stays net of the reversal.');
        $this->assertSplit($id, $loan, ['750.00', '0.00']);
    }

    public function test_a_build_up_loan_overpayment_with_no_ledger_row_is_unknown(): void
    {
        // A payment the stored data can't split: overpayment on a build-up loan
        // with no share capital row naming it (a payment from before the
        // automatic credit, or one by a member not yet approved).
        $loan = $this->createReleasedLoan();
        $total = (float) $loan->amortizationSchedules->sum('total_due');
        $id = $this->pay($loan, $total + 300)->json('data.id');
        DB::table('loans')->where('id', $loan->id)->update(['scb_amount' => 500]);

        $this->assertSplit($id, $loan, [null, null], addsUp: false);
    }

    public function test_the_figures_are_two_decimal_strings(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $id = $this->pay($loan, (float) $loan->amortizationSchedules->first()->total_due + 10.5)->json('data.id');

        $detail = $this->getJson("/api/repayments/{$id}")->assertOk()->json('data');
        $this->assertSame('10.50', $detail['build_up_credited']);
        $this->assertSame('0.00', $detail['overpayment_kept']);
    }
}
