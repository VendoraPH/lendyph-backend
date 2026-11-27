<?php

namespace Tests\Feature\Ledger;

use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Repayment;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A Share Capital Build-Up payment in the books (Phase 2, decision 5).
 *
 * On a loan with `scb_amount`, the part of a payment past the instalment is
 * credited to the member's share capital ledger (a row carrying the
 * payment's `repayment_id`). That money is the member's equity, so the
 * collection journal credits 3060 Share Capital with it, not 2300 Other
 * Liabilities as an overpayment. Any other overpayment stays on 2300. A void
 * reverses the whole journal, 3060 included. New payments only.
 */
class BuildUpJournalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        // ₱60,000 at 3% flat for 6 months: ₱11,800 a month.
        $this->loan = $this->createReleasedLoan();
        $this->loan->update(['scb_amount' => 500]);
        $this->seedChartOfAccounts();
    }

    /** ₱11,800 for the instalment plus ₱750 credited to share capital. */
    private function payWithBuildUp(): Repayment
    {
        $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 12550,
            'method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.overpayment', 750)->json('data.id');

        return Repayment::findOrFail($id);
    }

    private function collectionOf(Repayment $repayment): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', Repayment::class)
            ->where('postable_id', $repayment->id)
            ->where('source', 'loan_collection')
            ->firstOrFail();
    }

    private function lineOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    public function test_a_build_up_payment_credits_share_capital_not_other_liabilities(): void
    {
        $repayment = $this->payWithBuildUp();
        $this->assertDatabaseHas('share_capital_ledger', ['repayment_id' => $repayment->id, 'credit' => 750]);

        $journal = $this->collectionOf($repayment);

        $this->assertSame(1_255_000, $this->lineOn($journal, '1010', 'debit'));
        $this->assertSame(75_000, $this->lineOn($journal, '3060', 'credit'));
        $this->assertSame(0, $this->lineOn($journal, '2300', 'credit'));
        $this->assertSame($journal->total_debit, $journal->total_credit);
    }

    public function test_voiding_a_build_up_payment_reverses_share_capital(): void
    {
        $repayment = $this->payWithBuildUp();

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();

        $journal = $this->collectionOf($repayment)->fresh();
        $this->assertSame('reversed', $journal->status);

        $reversal = AccountingJournal::findOrFail($journal->reversed_by_journal_id);
        $this->assertSame(75_000, $this->lineOn($reversal, '3060', 'debit'));
        $this->assertSame(0, $this->lineOn($reversal, '2300', 'debit'));
    }

    /**
     * A member who is not a member yet holds no share capital, so the same
     * extra stays an ordinary overpayment, held on 2300.
     */
    public function test_an_overpayment_that_credited_no_share_capital_stays_on_other_liabilities(): void
    {
        $this->loan->borrower->update(['status' => 'pending']);

        $repayment = $this->payWithBuildUp();
        $this->assertDatabaseMissing('share_capital_ledger', ['repayment_id' => $repayment->id]);

        $journal = $this->collectionOf($repayment);

        $this->assertSame(75_000, $this->lineOn($journal, '2300', 'credit'));
        $this->assertSame(0, $this->lineOn($journal, '3060', 'credit'));
    }
}
