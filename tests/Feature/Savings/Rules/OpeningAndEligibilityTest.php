<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Opening an account (gap round S1): the product's eligible member types and
 * branches (422 on `product_id`), the new opening details (account officer,
 * source of funds, beneficiary) and the member check panel
 * (`GET /savings/opening-check`), every figure from the server, with loan and
 * share capital figures withheld (null) from callers without their view and
 * another branch's member answering 404.
 */
class OpeningAndEligibilityTest extends TestCase
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

    private function open(array $overrides = [])
    {
        return $this->postJson('/api/savings/accounts', [
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'opened_at' => '2026-11-10',
            'initial_deposit' => '500.00',
            'payment_method' => 'cash',
            ...$overrides,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_product_for_other_member_types_is_refused_on_opening(): void
    {
        $this->product->forceFill(['eligible_member_types' => ['associate']])->save();

        $this->open()->assertUnprocessable()
            ->assertJsonPath('errors.product_id.0', 'Regular Savings is open only to associate members, and Maria Santos is a regular member.');

        $this->product->forceFill(['eligible_member_types' => ['regular', 'associate']])->save();
        $this->open()->assertCreated();
    }

    public function test_a_product_for_other_branches_is_refused_on_opening(): void
    {
        $other = Branch::factory()->create();
        $this->product->forceFill(['eligible_branch_ids' => [$other->id]])->save();

        $this->open()->assertUnprocessable()
            ->assertJsonPath('errors.product_id.0', "Regular Savings is not offered in {$this->branch->name}.");

        $this->product->forceFill(['eligible_branch_ids' => [$other->id, $this->branch->id]])->save();
        $this->open()->assertCreated();
    }

    public function test_the_opening_deposit_follows_the_maximum_deposit_and_balance(): void
    {
        $this->product->forceFill(['max_deposit' => '499.99'])->save();

        $this->open()->assertUnprocessable()
            ->assertJsonPath('errors.initial_deposit.0', 'A deposit to Regular Savings is at most ₱499.99.');
    }

    public function test_the_opening_details_are_saved_and_returned(): void
    {
        $officer = User::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ana', 'last_name' => 'Reyes']);

        $response = $this->open([
            'account_officer_id' => $officer->id,
            'source_of_funds' => 'other',
            'source_of_funds_other' => 'Rental income',
            'beneficiary' => ['name' => 'Jose Santos', 'relationship' => 'Son', 'contact_number' => '09181234567'],
        ])->assertCreated()
            ->assertJsonPath('data.account_officer', ['id' => $officer->id, 'name' => $officer->full_name])
            ->assertJsonPath('data.source_of_funds', 'other')
            ->assertJsonPath('data.source_of_funds_label', 'Other: Rental income')
            ->assertJsonPath('data.beneficiary', ['name' => 'Jose Santos', 'relationship' => 'Son', 'contact_number' => '09181234567']);

        $account = SavingsAccount::query()->findOrFail($response->json('data.id'));
        $this->assertSame(['other', 'Rental income'], [$account->source_of_funds, $account->source_of_funds_other]);

        // Without borrowers:view the beneficiary's contact number is withheld.
        $this->actingAs($this->userWith(['savings:view']));
        $this->getJson("/api/savings/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.beneficiary', ['name' => 'Jose Santos', 'relationship' => 'Son', 'contact_number' => null]);
    }

    public function test_the_beneficiarys_contact_needs_borrowers_view_and_the_member_in_the_callers_branches(): void
    {
        $account = SavingsAccount::query()->findOrFail($this->open([
            'beneficiary' => ['name' => 'Jose Santos', 'relationship' => 'Son', 'contact_number' => '09181234567'],
        ])->assertCreated()->json('data.id'));

        $reader = $this->userWith(['savings:view', 'borrowers:view']);
        $this->actingAs($reader)->getJson("/api/savings/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.beneficiary.contact_number', '09181234567');

        // The member moves to a branch the reader can't see; the account stays in its branch.
        $this->member->forceFill(['branch_id' => Branch::factory()->create()->id])->save();
        $this->actingAs($reader)->getJson("/api/savings/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.beneficiary.contact_number', null);
        $this->actingAs($this->admin)->getJson("/api/savings/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.beneficiary.contact_number', '09181234567');
    }

    public function test_bad_opening_details_are_refused_in_words(): void
    {
        $inactive = User::factory()->create(['branch_id' => $this->branch->id, 'status' => 'inactive']);
        $elsewhere = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($this->userWith(['savings:view', 'savings:create']));
        $this->open(['account_officer_id' => $inactive->id, 'source_of_funds' => 'lottery'])->assertUnprocessable()
            ->assertJsonPath('errors.account_officer_id.0', 'Choose an active staff member in your branches as the account officer.')
            ->assertJsonPath('errors.source_of_funds.0', 'The source of funds is salary, business, remittance, pension, savings or other.');
        $this->open(['account_officer_id' => $elsewhere->id])->assertUnprocessable()->assertJsonValidationErrors('account_officer_id');
        $this->open(['source_of_funds' => 'other'])->assertUnprocessable()
            ->assertJsonPath('errors.source_of_funds_other.0', 'Say where the money comes from.');
    }

    public function test_the_member_check_shows_the_members_standing_from_the_server(): void
    {
        $this->member->forceFill(['membership_date' => '2024-01-15', 'member_type' => 'regular'])->save();
        $this->account(['ledger_balance' => '1200.00']);
        $special = $this->product->replicate(['code'])->fill(['code' => 'SS-02', 'name' => 'Special Savings', 'eligible_member_types' => ['associate']]);
        $special->save();
        ShareCapitalLedger::query()->create(['borrower_id' => $this->member->id, 'date' => '2026-11-01', 'credit' => 1500, 'debit' => 0, 'reference' => 'SC-T1', 'description' => 'Paid in']);

        $this->getJson("/api/savings/opening-check?borrower_id={$this->member->id}")->assertOk()
            ->assertJsonPath('data.member.name', 'Maria Santos')
            ->assertJsonPath('data.member.status', 'active')
            ->assertJsonPath('data.member.branch_name', $this->branch->name)
            ->assertJsonPath('data.member.membership_date', '2024-01-15')
            ->assertJsonPath('data.member.member_type', 'regular')
            ->assertJsonPath('data.loans.collectible_count', 0)
            ->assertJsonPath('data.loans.total_outstanding', '0.00')
            ->assertJsonPath('data.share_capital_balance', '1500.00')
            ->assertJsonPath('data.savings_accounts.0.product_name', 'Regular Savings')
            ->assertJsonPath('data.savings_accounts.0.balance', '1200.00')
            ->assertJsonPath('data.savings_accounts.0.status', 'active')
            ->assertJsonCount(0, 'data.eligible_products');
    }

    public function test_the_member_check_lists_eligible_products_without_an_open_account(): void
    {
        $this->getJson("/api/savings/opening-check?borrower_id={$this->member->id}")->assertOk()
            ->assertJsonPath('data.eligible_products.0.id', $this->product->id)
            ->assertJsonPath('data.eligible_products.0.name', 'Regular Savings')
            ->assertJsonPath('data.eligible_products.0.minimum_opening_balance', '500.00');
    }

    public function test_the_member_check_withholds_loans_and_share_capital_without_their_views(): void
    {
        $this->actingAs($this->userWith(['savings:view']));

        $this->getJson("/api/savings/opening-check?borrower_id={$this->member->id}")->assertOk()
            ->assertJsonPath('data.loans.collectible_count', null)
            ->assertJsonPath('data.loans.total_outstanding', null)
            ->assertJsonPath('data.share_capital_balance', null);

        $this->actingAs($this->userWith(['savings:view', 'loans:view', 'share_capital:view']));
        $this->getJson("/api/savings/opening-check?borrower_id={$this->member->id}")->assertOk()
            ->assertJsonPath('data.loans.collectible_count', 0)
            ->assertJsonPath('data.share_capital_balance', '0.00');
    }

    public function test_the_member_check_counts_collectible_loans(): void
    {
        $loan = $this->createReleasedLoan();
        $this->assertContains($loan->status, Loan::COLLECTIBLE_STATUSES);
        DB::table('loans')->where('id', $loan->id)->update(['borrower_id' => $this->member->id]);

        $response = $this->getJson("/api/savings/opening-check?borrower_id={$this->member->id}")->assertOk()
            ->assertJsonPath('data.loans.collectible_count', 1);
        $this->assertNotSame('0.00', $response->json('data.loans.total_outstanding'));
    }

    public function test_the_member_check_needs_savings_view_and_hides_another_branchs_member(): void
    {
        $elsewhere = Borrower::factory()->create(['branch_id' => Branch::factory()->create()->id, 'status' => 'active']);

        $this->actingAs($this->userWith(['loans:view']));
        $this->getJson("/api/savings/opening-check?borrower_id={$this->member->id}")->assertForbidden();

        $this->actingAs($this->userWith(['savings:view']));
        $this->getJson("/api/savings/opening-check?borrower_id={$elsewhere->id}")->assertNotFound();
        $this->getJson('/api/savings/opening-check')->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
    }
}
