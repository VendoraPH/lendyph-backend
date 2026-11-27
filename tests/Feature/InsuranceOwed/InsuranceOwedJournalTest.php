<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AccountingJournal;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The collection journal books the premium a payment collected to the
 * `insurance_premium_payable` role (default 2040 Insurance Premium Payable),
 * the same account the release booked the part it withheld to. Never income.
 * A void's reversal mirrors it.
 */
class InsuranceOwedJournalTest extends TestCase
{
    use OwesInsurancePremium, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function collectionJournal(Repayment $repayment): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $repayment->getMorphClass())
            ->where('postable_id', $repayment->getKey())
            ->where('source', 'loan_collection')
            ->firstOrFail();
    }

    private function lineOn(AccountingJournal $journal, string $code, string $side): int
    {
        $line = $journal->lines()->where('accounting_account_id', $this->account($code))->first();

        return $line === null ? 0 : (int) $line->{$side};
    }

    public function test_the_collected_premium_is_credited_to_insurance_premium_payable(): void
    {
        $loan = $this->loanOwingPremium();

        $journal = $this->collectionJournal($this->pay($loan, 1000));

        $this->assertSame(100_000, $this->lineOn($journal, '1010', 'debit'));
        $this->assertSame(40_000, $this->lineOn($journal, '2040', 'credit'));
        $this->assertSame(60_000, $this->lineOn($journal, '4010', 'credit'));
        $this->assertSame(0, $this->lineOn($journal, '1110', 'credit'));
        $this->assertSame((int) $journal->total_debit, (int) $journal->total_credit);
    }

    public function test_voiding_the_payment_reverses_the_premium_credit(): void
    {
        $loan = $this->loanOwingPremium();
        $repayment = $this->pay($loan, 1000);
        $original = $this->collectionJournal($repayment);

        app(RepaymentService::class)->voidRepayment($repayment->fresh(), 'Keyed twice', $this->admin);

        $mirror = AccountingJournal::findOrFail($original->fresh()->reversed_by_journal_id);

        $this->assertSame(40_000, $this->lineOn($mirror, '2040', 'debit'));
        $this->assertSame(100_000, $this->lineOn($mirror, '1010', 'credit'));
    }
}
