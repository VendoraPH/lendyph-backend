<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\SavingsProduct;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The product rule fields (gap round S1): saved and returned as two-decimal
 * strings, validated in words (money, min ≤ max, known member types,
 * existing branches), audited like any product edit, and limited to the
 * eligibility fields on a time deposit product.
 */
class ProductRulesSettingsTest extends TestCase
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

    private function regular(array $overrides = []): array
    {
        return ['code' => 'RS-01', 'name' => 'Regular Savings', 'kind' => 'regular', 'annual_interest_rate' => '1.0000', ...$overrides];
    }

    public function test_the_rules_are_saved_and_returned(): void
    {
        $this->postJson('/api/savings/products', $this->regular([
            'max_balance' => '100000',
            'min_deposit' => '100.00',
            'max_deposit' => '50000.00',
            'min_withdrawal' => '200',
            'max_withdrawal' => '20000.00',
            'free_withdrawals_per_month' => 4,
            'excess_withdrawal_fee' => '25.5',
            'allow_deposits' => true,
            'allow_withdrawals' => true,
            'allow_transfers' => false,
            'eligible_member_types' => ['regular'],
            'eligible_branch_ids' => [$this->branch->id],
        ]))->assertCreated()
            ->assertJsonPath('data.max_balance', '100000.00')
            ->assertJsonPath('data.min_deposit', '100.00')
            ->assertJsonPath('data.max_deposit', '50000.00')
            ->assertJsonPath('data.min_withdrawal', '200.00')
            ->assertJsonPath('data.max_withdrawal', '20000.00')
            ->assertJsonPath('data.free_withdrawals_per_month', 4)
            ->assertJsonPath('data.excess_withdrawal_fee', '25.50')
            ->assertJsonPath('data.allow_deposits', true)
            ->assertJsonPath('data.allow_transfers', false)
            ->assertJsonPath('data.eligible_member_types', ['regular'])
            ->assertJsonPath('data.eligible_branch_ids', [$this->branch->id]);
    }

    public function test_a_product_without_rules_keeps_todays_behaviour(): void
    {
        $this->postJson('/api/savings/products', $this->regular())->assertCreated()
            ->assertJsonPath('data.max_balance', null)
            ->assertJsonPath('data.free_withdrawals_per_month', null)
            ->assertJsonPath('data.excess_withdrawal_fee', null)
            ->assertJsonPath('data.allow_deposits', true)
            ->assertJsonPath('data.allow_withdrawals', true)
            ->assertJsonPath('data.allow_transfers', true)
            ->assertJsonPath('data.eligible_member_types', null)
            ->assertJsonPath('data.eligible_branch_ids', null);
    }

    public function test_bad_rules_are_refused_in_words(): void
    {
        $response = $this->postJson('/api/savings/products', $this->regular([
            'min_deposit' => '500.00', 'max_deposit' => '100.00',
            'min_withdrawal' => '10.001',
            'eligible_member_types' => ['regular', 'founder'],
            'eligible_branch_ids' => [999_999],
            'free_withdrawals_per_month' => -1,
        ]))->assertUnprocessable()
            ->assertJsonPath('errors.max_deposit.0', 'The maximum deposit can\'t be below the minimum deposit.')
            ->assertJsonPath('errors.min_withdrawal.0', 'The minimum withdrawal can have at most two decimals (centavos).')
            ->assertJsonPath('errors.free_withdrawals_per_month.0', 'The free withdrawals a month are a whole number, 0 or more (leave it empty for no limit).');
        $this->assertSame('A member type is regular or associate.', $response->json('errors')['eligible_member_types.1'][0]);
        $this->assertSame('That branch does not exist.', $response->json('errors')['eligible_branch_ids.0'][0]);
    }

    public function test_min_and_max_are_checked_against_the_stored_value_on_update(): void
    {
        $product = SavingsProduct::factory()->create(['max_withdrawal' => '1000.00']);

        $this->putJson("/api/savings/products/{$product->id}", ['min_withdrawal' => '1000.01'])->assertUnprocessable()
            ->assertJsonPath('errors.min_withdrawal.0', 'The minimum withdrawal can\'t be above the maximum withdrawal.');
        $this->putJson("/api/savings/products/{$product->id}", ['min_withdrawal' => '1000.00'])->assertOk();
    }

    public function test_a_change_is_audited_with_its_old_and_new_values(): void
    {
        $product = SavingsProduct::factory()->create();
        $other = Branch::factory()->create();

        $this->putJson("/api/savings/products/{$product->id}", ['max_balance' => '5000.00', 'eligible_branch_ids' => [$other->id]])->assertOk();

        $row = AuditLog::query()->where('auditable_type', SavingsProduct::class)->where('auditable_id', $product->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('5000.00', (string) $row->new_values['max_balance']);
        $this->assertNull($row->old_values['max_balance']);
    }

    public function test_a_time_deposit_product_takes_only_the_eligibility_fields(): void
    {
        $product = SavingsProduct::factory()->timeDeposit()->create();

        $this->putJson("/api/savings/products/{$product->id}", ['max_balance' => '5000.00', 'excess_withdrawal_fee' => '10.00'])->assertUnprocessable()
            ->assertJsonPath('errors.max_balance.0', 'A time deposit product takes no deposit, withdrawal or balance limits; only who may place one.')
            ->assertJsonValidationErrors('excess_withdrawal_fee');

        $this->putJson("/api/savings/products/{$product->id}", ['eligible_member_types' => ['associate']])->assertOk()
            ->assertJsonPath('data.eligible_member_types', ['associate']);
    }
}
