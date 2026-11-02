<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 2. The loan page's Ledger comes from GET /loans/{id}/ledger: the
 * release, every payment (with its own principal, interest, penalty and
 * insurance parts) and every extension charge, in order, each with the
 * running `balances` (`principal`, `interest`, `penalty`, `scb`) after it,
 * worked out by the server. The last row's balances are the summary's
 * `outstanding_principal`, `outstanding_interest` and `outstanding_penalty`.
 *
 * Today the browser solves for an interest opening from
 * `currentInterestDue` and walks the rows itself (`walkLedgerBalances`), and
 * an extension's collection row shows no penalty part.
 */
class LoanLedgerTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function ledger(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data');
    }

    /** One-month upon-maturity loan, ₱60,000 at 3%, due ten days ago: past grace, so a penalty. */
    private function overdueOneMonthLoan(): Loan
    {
        $loan = $this->releasedLoanFor(product: ['interest_method' => 'upon_maturity', 'term' => 1]);
        $loan->amortizationSchedules()->update([
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);

        return $loan->fresh();
    }

    public function test_the_last_row_lands_on_the_summary_after_payments(): void
    {
        $loan = $this->releasedLoanFor();
        $this->payLoan($loan, 11800);
        $this->payLoan($loan, 5000);

        $ledger = $this->ledger($loan);
        $summary = $this->summaryOf($loan);
        $last = end($ledger['rows']);

        $this->assertEqualsWithDelta($summary['outstanding_principal'], $last['balances']['principal'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_interest'], $last['balances']['interest'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_penalty'], $last['balances']['penalty'], 0.001);
    }

    public function test_an_extension_collection_row_carries_its_penalty_part_and_the_balances_add_up(): void
    {
        $loan = $this->overdueOneMonthLoan();
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertGreaterThan(0, (float) $repayment->penalty_applied, 'setup: the collection paid a penalty');

        $ledger = $this->ledger($loan);
        $payment = collect($ledger['rows'])->firstWhere('repayment_id', $repayment->id);

        $this->assertNotNull($payment, 'the collection has a ledger row');
        $this->assertEqualsWithDelta((float) $repayment->penalty_applied, $payment['penalty'], 0.001);
        $this->assertEqualsWithDelta((float) $repayment->interest_applied, $payment['interest'], 0.001);

        $summary = $this->summaryOf($loan);
        $last = end($ledger['rows']);
        $this->assertEqualsWithDelta($summary['outstanding_principal'], $last['balances']['principal'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_interest'], $last['balances']['interest'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_penalty'], $last['balances']['penalty'], 0.001);
    }

    public function test_the_extension_interest_credit_records_the_interest_the_payment_applied(): void
    {
        $loan = $this->overdueOneMonthLoan();
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $credit = LoanLedgerEntry::where('loan_id', $loan->id)->where('type', 'credit')->sole();

        // The penalty took part of the collection first, so less than the
        // ₱1,800 outstanding went to interest; the credit must say what did.
        $this->assertEqualsWithDelta((float) $repayment->interest_applied, (float) $credit->amount, 0.001);
    }
}
