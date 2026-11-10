<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\AccountingJournal;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The shapes the savings screens (frontend slice F, `src/types/savings.ts`)
 * read and send: users as `{id, name}` (null for the system), money as
 * two-decimal strings, rates as four-decimal strings, `account_count`
 * counting accounts and time deposits, the bodies the product form sends
 * (nulls for a regular product's time deposit fields), and the journal
 * register's postable names for savings documents.
 */
class SavingsFrontendContractTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function me(): array
    {
        return ['id' => $this->admin->id, 'name' => 'Super Admin'];
    }

    public function test_settings_and_tax_rules_name_users_as_id_and_name(): void
    {
        $this->putJson('/api/savings/settings', [
            'tier1_limit' => '10000.00', 'tier2_limit' => '50000.00', 'tier3_checkers' => 2,
            'dormancy_months' => 36, 'large_transaction_alert' => '500000.00',
        ])->assertOk()->assertJsonPath('data.updated_by', $this->me());

        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'non_member', 'rate' => '15', 'effective_from' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('data.rate', '15.0000')
            ->assertJsonPath('data.created_by', $this->me());

        $settings = $this->getJson('/api/savings/settings')->assertOk();
        $settings->assertJsonPath('data.current_tax_rates', ['member' => '0.0000', 'non_member' => '20.0000']);
        $seeded = collect($settings->json('data.tax_rules'))->firstWhere('holder_type', 'member');
        $this->assertNull($seeded['created_by'], 'A seeded rule reads as the system.');
        $this->assertIsString($settings->json('data.tier1_limit'));
    }

    public function test_the_product_form_bodies_are_accepted_as_sent(): void
    {
        $created = $this->postJson('/api/savings/products', [
            'code' => 'RS-02', 'name' => 'Passbook', 'kind' => 'regular', 'interest_crediting' => 'quarterly',
            'minimum_opening_balance' => '500.00', 'maintaining_balance' => '0.00', 'minimum_adb_for_interest' => '0.00',
            'term_options_days' => null, 'pretermination_product_id' => null, 'is_active' => false,
            'annual_interest_rate' => '1.5',
        ])->assertCreated()
            ->assertJsonPath('data.term_options_days', null)
            ->assertJsonPath('data.current_rates.0.annual_rate', '1.5000');

        $id = $created->json('data.id');
        $this->assertIsInt($created->json('data.current_rates.0.id'));

        $this->putJson("/api/savings/products/{$id}", [
            'code' => 'RS-02', 'name' => 'Passbook Savings', 'interest_crediting' => 'monthly',
            'minimum_opening_balance' => '1000.00', 'maintaining_balance' => '100.00', 'minimum_adb_for_interest' => '0.00',
            'term_options_days' => null, 'pretermination_product_id' => null, 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.interest_crediting', 'monthly');

        $this->postJson('/api/savings/products', [
            'code' => 'TD-02', 'name' => 'Time Deposit', 'kind' => 'time_deposit', 'interest_crediting' => 'annual',
            'minimum_opening_balance' => '10000.00', 'maintaining_balance' => '0.00', 'minimum_adb_for_interest' => '0.00',
            'term_options_days' => [90, 180], 'pretermination_product_id' => $id, 'is_active' => false,
            'annual_interest_rate' => '3.25',
        ])->assertCreated()->assertJsonPath('data.current_rates.1', fn (array $rate) => $rate['term_days'] === 180 && $rate['annual_rate'] === '3.2500');

        $this->postJson("/api/savings/products/{$id}/rates", ['annual_rate' => '2', 'term_days' => null, 'effective_from' => '2026-11-10'])
            ->assertCreated()
            ->assertJsonPath('data.annual_rate', '2.0000')
            ->assertJsonPath('data.created_by', $this->me());

        $this->getJson("/api/savings/products/{$id}/rates")->assertOk()->assertJsonPath('data.0.created_by', $this->me());
    }

    public function test_account_count_counts_accounts_and_time_deposits(): void
    {
        $product = SavingsProduct::factory()->timeDeposit()->create();
        TimeDeposit::factory()->count(2)->for($product, 'product')->create();

        $row = collect($this->getJson('/api/savings/products')->assertOk()->json('data'))->firstWhere('id', $product->id);

        $this->assertSame(2, $row['account_count']);
        $this->assertFalse($row['can_delete']);
    }

    public function test_the_journal_register_names_savings_documents(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        $account = SavingsAccount::factory()->create(['opened_on' => '2026-11-01']);
        $deposit = DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'deposit', 10_000, ['method' => 'cash']));
        $poster = app(AutomaticPoster::class);
        $poster->savingsTransaction($deposit, $this->admin->id);
        $run = SavingsInterestRun::factory()->create(['product_id' => $account->product_id, 'branch_id' => $account->branch_id, 'total_gross' => '10.00', 'total_net' => '10.00']);
        DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'interest', 1_000, ['value_date' => '2026-11-09', 'interest_run_id' => $run->id, 'created_by' => null]));
        $poster->savingsInterestRun($run, $this->admin->id);
        $poster->timeDepositPlacement(TimeDeposit::factory()->create(['placement_date' => '2026-11-10']), $this->admin->id);

        $types = collect($this->getJson('/api/accounting/journals?per_page=100')->assertOk()->json('data'))
            ->pluck('postable_type', 'source')
            ->only(['savings', 'savings_interest', 'time_deposit'])
            ->all();

        $this->assertEquals(['savings' => 'savings_transaction', 'savings_interest' => 'savings_interest_run', 'time_deposit' => 'time_deposit'], $types);
        $this->assertSame(SavingsTransaction::class, AccountingJournal::query()->where('source', 'savings')->value('postable_type'));
    }
}
