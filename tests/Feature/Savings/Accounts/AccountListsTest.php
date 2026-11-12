<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsProduct;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The lists (design §2): accounts with `meta.stats` per status and
 * `meta.totals` over the whole filter, an account's ledger with its opening
 * balance and totals, the register, and the approvals queue's `meta.stats`.
 */
class AccountListsTest extends TestCase
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

    public function test_accounts_list_with_stats_and_totals_over_the_whole_filter(): void
    {
        $a = $this->account();
        $this->fund($a, 120_000);
        $b = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->fund($b, 30_050);
        $this->account(['product_id' => SavingsProduct::factory()->create()->id, 'status' => 'dormant']);
        $someoneElse = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Jose', 'middle_name' => null, 'last_name' => 'Rizal', 'suffix' => null]);
        $this->account(['borrower_id' => $someoneElse->id]);

        $response = $this->getJson('/api/savings/accounts?per_page=1&status=active&borrower_id='.$this->member->id)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.stats.active', 2)
            ->assertJsonPath('meta.stats.dormant', 1)
            ->assertJsonPath('meta.totals.account_count', 2)
            ->assertJsonPath('meta.totals.total_balance', '1500.50');

        $this->assertSame(['id', 'account_number', 'borrower_id', 'borrower_name', 'borrower_code', 'branch_id', 'branch_name', 'product', 'status', 'balance', 'hold_amount', 'held_for_requests', 'pending_amount', 'available_balance', 'accrued_interest', 'interest_credited_through', 'opened_on', 'last_member_activity_on', 'dormant_since', 'closed_on', 'close_reason', 'remarks', 'account_officer', 'source_of_funds', 'source_of_funds_other', 'source_of_funds_label', 'beneficiary'], array_keys($response->json('data.0')));

        $this->getJson('/api/savings/accounts?search=rizal')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.borrower_name', 'Jose Rizal');
        $this->getJson('/api/savings/accounts?search='.$a->account_number)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/savings/accounts?status=gone')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_an_accounts_ledger_is_newest_first_with_its_opening_balance_and_totals(): void
    {
        $account = $this->account();
        $this->fund($account, 10_000, '2026-11-01');
        $this->fund($account, 20_000, '2026-11-05');
        $this->fund($account, 40_000, '2026-11-09');
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '5.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated();

        $response = $this->getJson("/api/savings/accounts/{$account->id}/transactions?date_from=2026-11-05&per_page=2")->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.opening_balance', '100.00')
            ->assertJsonPath('meta.totals.credit', '600.00')
            ->assertJsonPath('meta.totals.debit', '5.00');

        $this->assertSame(['withdrawal', 'deposit'], array_column($response->json('data'), 'type'));
        $this->getJson("/api/savings/accounts/{$account->id}/transactions?type=deposit")->assertJsonPath('meta.total', 3)->assertJsonPath('meta.opening_balance', '0.00');
    }

    public function test_the_register_and_a_receipt(): void
    {
        $account = $this->account();
        $row = $this->fund($account, 10_000);

        $this->getJson('/api/savings/transactions?type=deposit&date_from=2026-11-10&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', $row->reference)
            ->assertJsonPath('data.0.borrower_name', 'Maria Santos');
        $this->getJson('/api/savings/transactions?search='.$row->reference)->assertJsonPath('meta.total', 1);
        $this->getJson("/api/savings/transactions/{$row->id}")->assertOk()->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.created_by', ['id' => $this->admin->id, 'name' => 'Super Admin']);
    }

    public function test_the_approvals_queue_counts_each_status(): void
    {
        $account = $this->account();
        SavingsApproval::factory()->count(2)->create(['account_id' => $account->id]);
        SavingsApproval::factory()->create(['account_id' => $account->id, 'status' => 'rejected']);

        $response = $this->getJson('/api/savings/approvals?status=pending&kind=withdrawal')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.stats.pending', 2)
            ->assertJsonPath('meta.stats.rejected', 1)
            ->assertJsonPath('meta.stats.approved', 0)
            ->assertJsonPath('data.0.account_number', $account->account_number)
            ->assertJsonPath('data.0.branch_name', $this->branch->name);

        $this->assertSame(['id', 'kind', 'status', 'account_id', 'account_number', 'time_deposit_id', 'certificate_number', 'borrower_name', 'branch_name', 'target_transaction_id', 'target_reference', 'amount', 'fee_amount', 'required_checkers', 'requested_by', 'first_checker', 'decided_by', 'decision_reason', 'check_reason', 'decided_at', 'expires_at', 'result_transaction_id', 'created_at'], array_keys($response->json('data.0')));
        $this->assertSame(1, SavingsAccount::query()->count());
    }
}
