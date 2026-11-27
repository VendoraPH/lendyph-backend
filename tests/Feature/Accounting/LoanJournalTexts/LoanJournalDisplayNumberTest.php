<?php

namespace Tests\Feature\Accounting\LoanJournalTexts;

use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Repayment;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Round 9: new journals name a loan by its display number
 * (Loan::displayNumber(): its LN- number, else the file's Loan No., else its
 * application number), so a payment on an imported loan reads the file's Loan
 * No. Only the words change; stored journals are never rewritten.
 */
class LoanJournalDisplayNumberTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_an_ordinary_loans_release_and_payment_journals_read_its_ln_number(): void
    {
        $loan = $this->createReleasedLoan();
        $number = $loan->fresh()->loan_account_number;
        $this->assertStringStartsWith('LN-', $number);

        $release = AccountingJournal::query()->where('postable_type', $loan->getMorphClass())->where('postable_id', $loan->id)
            ->where('source', 'loan_release')->sole();
        $this->assertSame($number, $release->reference);
        $this->assertSame("Loan release — {$number}", $release->description);

        $repayment = $this->pay($loan);
        $journal = $this->collectionJournal($repayment);
        $this->assertSame($repayment->receipt_number, $journal->reference);
        $this->assertSame("Loan collection — {$repayment->receipt_number} (Loan {$number})", $journal->description);
    }

    public function test_an_imported_loans_new_payment_journal_names_the_files_loan_no(): void
    {
        // An imported loan's shape: no LN- number, the file's own Loan No.
        $loan = $this->createReleasedLoan();
        Loan::query()->whereKey($loan->id)->update(['loan_account_number' => null, 'external_loan_no' => 'L-2019-0042']);

        $repayment = $this->pay($loan);
        $journal = $this->collectionJournal($repayment);

        $this->assertSame($repayment->receipt_number, $journal->reference);
        $this->assertSame("Loan collection — {$repayment->receipt_number} (Loan L-2019-0042)", $journal->description);
        $this->assertStringNotContainsString((string) $loan->application_number, $journal->description);
    }

    private function pay(Loan $loan): Repayment
    {
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertCreated();

        return Repayment::query()->where('loan_id', $loan->id)->latest('id')->firstOrFail();
    }

    private function collectionJournal(Repayment $repayment): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $repayment->getMorphClass())
            ->where('postable_id', $repayment->getKey())
            ->where('source', 'loan_collection')
            ->sole();
    }
}
