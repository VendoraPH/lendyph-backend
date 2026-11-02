<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Loan;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Items 3 and 5. One loan, one "outstanding" figure, under unambiguous names
 * on every endpoint that states it:
 *  - `total_outstanding`: principal, interest and penalty still owed plus the
 *    insurance premium still owed (today's summary `outstanding_balance`);
 *  - `outstanding_principal`: principal only.
 * GET /loans rows and GET /loans/{id}/summary both carry them, equal.
 *
 * And the payment preview states the loan's `total_outstanding_before` and
 * `total_outstanding_after`, so the "New Outstanding Balance" tile is a server
 * figure. Today the browser shows summary `outstanding_balance` (everything
 * owed) minus the preview's `total_principal`: ₱1,000 that pays ₱400 premium
 * and ₱600 interest leaves the tile at ₱71,200.00 when ₱70,200.00 is owed.
 */
class OutstandingFiguresTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function listRow(Loan $loan): array
    {
        $row = collect(
            $this->getJson("/api/loans?borrower_id={$loan->borrower_id}&per_page=100")->assertOk()->json('data'),
        )->firstWhere('id', $loan->id);

        $this->assertNotNull($row, 'setup: the loan is listed');

        return $row;
    }

    public function test_the_list_and_the_summary_state_the_same_total_outstanding(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $row = $this->listRow($loan);
        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');

        // ₱70,800 schedule + ₱400 premium − ₱1,000 paid.
        $this->assertEqualsWithDelta(70200.00, (float) ($summary['total_outstanding'] ?? 0), 0.001, 'summary.total_outstanding');
        $this->assertEqualsWithDelta(70200.00, (float) ($row['total_outstanding'] ?? 0), 0.001, 'list row total_outstanding');
        $this->assertEqualsWithDelta(60000.00, (float) ($row['outstanding_principal'] ?? 0), 0.001, 'list row outstanding_principal');
    }

    public function test_the_payment_preview_states_the_total_outstanding_before_and_after(): void
    {
        $loan = $this->loanOwingPremium();

        $preview = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => 1000,
            'payment_date' => now()->toDateString(),
        ])->assertOk()->json('data');

        $this->assertEqualsWithDelta(71200.00, (float) ($preview['total_outstanding_before'] ?? 0), 0.001, 'total_outstanding_before');
        $this->assertEqualsWithDelta(70200.00, (float) ($preview['total_outstanding_after'] ?? 0), 0.001, 'total_outstanding_after');
    }
}
