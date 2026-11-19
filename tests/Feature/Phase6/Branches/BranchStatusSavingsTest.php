<?php

namespace Tests\Feature\Phase6\Branches;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE2 (multi-branch decision 2): a suspended branch opens no new
 * savings accounts; deposits and withdrawals on its existing accounts go on.
 */
class BranchStatusSavingsTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_suspended_branch_opens_no_new_savings_account(): void
    {
        DB::table('branches')->where('id', $this->branch->id)->update(['status' => 'suspended']);

        $this->money('/api/savings/accounts', [
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'opened_at' => '2026-11-10',
            'initial_deposit' => '1500.00',
            'payment_method' => 'cash',
            'reference' => 'OR-1001',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.borrower_id.0', 'Branch "Main Branch" (MAIN) is suspended, so no new savings accounts can be opened there.');
    }

    public function test_deposits_and_withdrawals_go_on_in_a_suspended_branch(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        DB::table('branches')->where('id', $this->branch->id)->update(['status' => 'suspended']);

        $this->money("/api/savings/accounts/{$account->id}/deposits", ['amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'cash', 'reference' => 'OR-2'])->assertSuccessful();
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'cash', 'reference' => 'OR-3'])->assertSuccessful();
    }
}
