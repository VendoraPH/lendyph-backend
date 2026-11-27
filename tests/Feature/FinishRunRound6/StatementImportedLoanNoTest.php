<?php

namespace Tests\Feature\FinishRunRound6;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6: an imported loan has no LN- number (the importer leaves
 * `loan_account_number` empty on purpose), so the Statement of Account sends
 * the file's Loan No. as well, for its reference.
 */
class StatementImportedLoanNoTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_statement_sends_the_files_loan_no(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['loan_account_number' => null, 'external_loan_no' => 'OLD-2023-0041']);

        $this->getJson("/api/reports/statement-of-account/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.loan.loan_account_number', null)
            ->assertJsonPath('data.loan.external_loan_no', 'OLD-2023-0041');

        $native = $this->createReleasedLoan();
        $this->getJson("/api/reports/statement-of-account/{$native->id}")
            ->assertOk()
            ->assertJsonPath('data.loan.external_loan_no', null);
    }
}
