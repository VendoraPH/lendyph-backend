<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Loan;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 6. The restructure page's shortfall is a server figure: POST
 * /loans/preview with `source_loan_id` answers `restructure`
 * (`restructurable_balance`, `principal`, `shortfall`, `remarks_required`),
 * by the rule POST /loans/{id}/restructure applies
 * (LoanService::assertRestructureInvariants()). Today the browser subtracts
 * the principal from `restructurable_balance` (`calcRestructureShortfall`).
 */
class RestructureShortfallPreviewTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed>|null */
    private function restructurePreview(Loan $source, float $principal): ?array
    {
        return $this->postJson('/api/loans/preview', [
            'source_loan_id' => $source->id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'interest_rate' => 3.0,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ])->assertOk()->json('data.restructure');
    }

    public function test_a_principal_below_the_restructurable_balance_states_the_shortfall(): void
    {
        // ₱60,000 straight over six: ₱70,800 of principal and interest open.
        $source = $this->releasedLoanFor();

        $restructure = $this->restructurePreview($source, 50000);

        $this->assertIsArray($restructure, 'data.restructure is missing');
        $this->assertEqualsWithDelta(70800.00, (float) $restructure['restructurable_balance'], 0.001);
        $this->assertEqualsWithDelta(50000.00, (float) $restructure['principal'], 0.001);
        $this->assertEqualsWithDelta(20800.00, (float) $restructure['shortfall'], 0.001);
        $this->assertTrue($restructure['remarks_required']);
    }

    public function test_a_principal_equal_to_the_balance_has_no_shortfall(): void
    {
        $source = $this->releasedLoanFor();
        $this->payLoan($source, 11800);

        $restructure = $this->restructurePreview($source, 59000);

        $this->assertIsArray($restructure, 'data.restructure is missing');
        $this->assertEqualsWithDelta(59000.00, (float) $restructure['restructurable_balance'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $restructure['shortfall'], 0.001);
        $this->assertFalse($restructure['remarks_required']);
    }
}
