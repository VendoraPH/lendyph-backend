<?php

namespace Tests\Feature\Ledger\Release;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A release takes the method the money went out by and its reference, in the
 * shape Record Payment takes them (Phase 2, Accounting 4): the journal
 * credits that method's account, both are stored on the loan, and old loans
 * read null.
 */
class ReleaseMethodTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_gcash_release_pays_the_net_proceeds_out_of_the_gcash_wallet(): void
    {
        $loan = $this->approvedLoan();

        $this->patchJson("/api/loans/{$loan->id}/release", ['method' => 'gcash', 'reference_number' => 'GC-778899'])
            ->assertOk()
            ->assertJsonPath('data.release_method', 'gcash')
            ->assertJsonPath('data.release_reference', 'GC-778899');

        $loan->refresh();
        $this->assertSame('gcash', $loan->release_method);
        $this->assertSame('GC-778899', $loan->release_reference);

        $journal = $this->journalFor($loan, 'loan_release');
        $this->assertSame(6_000_000, $this->sumOn($journal, '1110', 'debit'));
        $this->assertSame($this->centavos($loan->net_proceeds), $this->sumOn($journal, '1020', 'credit'));
        $this->assertSame(0, $this->sumOn($journal, '1010', 'credit'));
        $this->assertBalances($journal);
    }

    public function test_a_release_with_no_method_is_a_cash_release_as_before(): void
    {
        $loan = $this->approvedLoan();

        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertOk()
            ->assertJsonPath('data.release_method', 'cash')
            ->assertJsonPath('data.release_reference', null);

        $loan->refresh();
        $journal = $this->journalFor($loan, 'loan_release');
        $this->assertSame($this->centavos($loan->net_proceeds), $this->sumOn($journal, '1010', 'credit'));
        $this->assertSame(0, $this->sumOn($journal, '1020', 'credit'));
        $this->assertSame(6_000_000, $this->assertBalances($journal));
    }

    public function test_a_non_cash_release_needs_a_reference_and_a_known_method(): void
    {
        $loan = $this->approvedLoan();

        foreach (['bank_transfer', 'gcash', 'maya', 'online'] as $method) {
            $this->patchJson("/api/loans/{$loan->id}/release", ['method' => $method])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reference_number']);
        }

        $this->patchJson("/api/loans/{$loan->id}/release", ['method' => 'auto_pay', 'reference_number' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->patchJson("/api/loans/{$loan->id}/release", ['method' => 'cash', 'reference_number' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_number']);

        $this->assertSame('approved', $loan->fresh()->status);
        $this->assertNull($this->journalFor($loan, 'loan_release'));
    }

    public function test_a_loan_released_before_the_method_was_recorded_reads_null(): void
    {
        $loan = $this->approvedLoan();
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
        DB::table('loans')->where('id', $loan->id)->update(['release_method' => null, 'release_reference' => null]);

        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('release_method', $data);
        $this->assertArrayHasKey('release_reference', $data);
        $this->assertNull($data['release_method']);
        $this->assertNull($data['release_reference']);
    }
}
