<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Cash Flow counts a payment's share capital build-up once.
 *
 * On a loan with `scb_amount`, RepaymentService turns the part of a payment
 * past the current instalment into a share capital credit carrying the
 * payment's `repayment_id`. That money is already inside the repayment line
 * (`inflows.repayments.overpayment`), so counting the ledger row again under
 * `inflows.share_capital_credit` reported more cash than crossed the counter,
 * and a void's reversal debit (which carries the same `repayment_id`) showed
 * as a cash outflow that never happened. Those rows now sit outside every cash
 * figure, under `share_capital.build_up_from_payments`, for reconciliation
 * with the Share Capital report.
 */
class CashFlowShareCapitalBuildUpTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->today = now()->toDateString();

        // ₱60,000 at 3% flat for 6 months: ₱11,800 a month, ₱1,800 withheld.
        $this->loan = $this->createReleasedLoan();
        $this->loan->update(['scb_amount' => 500]);

        // Share capital paid in and out over the counter, on its own.
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => $this->today, 'credit' => 4000, 'debit' => 0]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => $this->today, 'credit' => 0, 'debit' => 1500]);
    }

    /** ₱11,800 for the instalment plus ₱750 credited to share capital. */
    private function payWithBuildUp(): int
    {
        return $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'payment_date' => $this->today,
            'amount_paid' => 12550,
            'method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.overpayment', 750)->json('data.id');
    }

    /** @return array<string, mixed> */
    private function cashFlow(): array
    {
        return $this->getJson("/api/reports/cash-flow?date_from={$this->today}&date_to={$this->today}")->assertOk()->json('data');
    }

    public function test_a_payments_share_capital_build_up_is_counted_once_as_cash(): void
    {
        $this->payWithBuildUp();
        $this->assertDatabaseHas('share_capital_ledger', ['borrower_id' => $this->loan->borrower_id, 'credit' => 750]);

        $data = $this->cashFlow();

        // The ₱12,550 the member handed over, with its ₱750 build-up inside.
        $this->assertEquals(12550, $data['inflows']['repayments']['total']);
        $this->assertEquals(750, $data['inflows']['repayments']['overpayment']);
        // Only the share capital paid in on its own; the ₱750 is not again.
        $this->assertEquals(4000, $data['inflows']['share_capital_credit']);
        $this->assertEquals(16550, $data['inflows']['total']);
        $this->assertEquals(1500, $data['outflows']['share_capital_debit']);
        $this->assertEquals(58200 + 1500, $data['outflows']['total']);
        $this->assertEquals(16550 - 59700, $data['net_movement']);

        $share = $data['share_capital'];
        $this->assertEquals(4000, $share['credit']);
        $this->assertEquals(1500, $share['debit']);
        $this->assertSame(2, $share['count']);
        $this->assertEquals(750, $share['build_up_from_payments']);
        $this->assertEquals(750, $share['build_up_credit']);
        $this->assertEquals(0, $share['build_up_debit']);

        // by_branch carries loan cash only and is unaffected.
        $this->assertCount(1, $data['by_branch']);
        $this->assertEquals(12550, $data['by_branch'][0]['inflow_total']);
        $this->assertEquals(750, $data['by_branch'][0]['inflow_overpayment']);
        $this->assertEquals(
            $data['inflows']['total'],
            $data['by_branch_totals']['inflow_total'] + $data['inflows']['share_capital_credit'],
        );
    }

    public function test_the_share_capital_report_reconciles_to_the_cash_flow_figures(): void
    {
        $this->payWithBuildUp();

        $data = $this->cashFlow();
        $report = $this->getJson("/api/reports/share-capital?date_from={$this->today}&date_to={$this->today}")->assertOk()->json('data');

        $share = $data['share_capital'];
        $this->assertEquals(
            $report['credits'],
            round($share['credit'] + $share['build_up_credit'] + $data['non_cash']['share_capital_at_release'], 2),
        );
        $this->assertEquals($report['debits'], round($share['debit'] + $share['build_up_debit'], 2));
    }

    public function test_a_voided_payment_and_its_reversed_build_up_move_no_cash(): void
    {
        $id = $this->payWithBuildUp();
        $this->patchJson("/api/repayments/{$id}/void", ['void_reason' => 'Keyed twice'])->assertOk();
        $this->assertDatabaseHas('share_capital_ledger', ['repayment_id' => $id, 'debit' => 750]);

        $data = $this->cashFlow();

        $this->assertEquals(0, $data['inflows']['repayments']['total']);
        $this->assertEquals(4000, $data['inflows']['share_capital_credit']);
        $this->assertEquals(4000, $data['inflows']['total']);
        // The reversal debit is not cash paid out.
        $this->assertEquals(1500, $data['outflows']['share_capital_debit']);
        $this->assertEquals(58200 + 1500, $data['outflows']['total']);

        $share = $data['share_capital'];
        $this->assertEquals(0, $share['build_up_from_payments']);
        $this->assertEquals(750, $share['build_up_credit']);
        $this->assertEquals(750, $share['build_up_debit']);

        $this->assertEquals(0, $data['by_branch'][0]['inflow_total']);
        $this->assertSame(0, $data['by_branch'][0]['repayment_count']);
    }
}
