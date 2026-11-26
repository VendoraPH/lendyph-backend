<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\Role;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7 (batch): a savings product's `portal_withdrawal_requests`
 * decides whether the Member Portal takes withdrawal requests from its
 * accounts (PortalRequestController::options() and
 * WithdrawalRequests::assertMemberCanWithdraw() read it per product). The
 * product create and update requests take it as a boolean, under the
 * product's own savings:settings plus branches:view_all, and the resource
 * sends it, as round 6 did for `portal_account_requests`. It stays on by
 * default (the column's default since the portal shipped), so existing
 * products keep taking requests. Only a regular product takes it.
 */
class ProductPortalWithdrawalFlagTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_create_and_update_take_the_flag_and_the_resource_sends_it(): void
    {
        $id = $this->postJson('/api/savings/products', $this->regular(['portal_withdrawal_requests' => false]))
            ->assertCreated()
            ->assertJsonPath('data.portal_withdrawal_requests', false)
            ->json('data.id');
        $this->assertFalse((bool) SavingsProduct::query()->findOrFail($id)->portal_withdrawal_requests);

        $this->putJson("/api/savings/products/{$id}", ['portal_withdrawal_requests' => true])
            ->assertOk()
            ->assertJsonPath('data.portal_withdrawal_requests', true);
        $this->assertTrue((bool) SavingsProduct::query()->findOrFail($id)->portal_withdrawal_requests);

        $listed = collect($this->getJson('/api/savings/products')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertTrue($listed['portal_withdrawal_requests']);
    }

    public function test_it_defaults_to_on_so_existing_products_keep_taking_requests(): void
    {
        $this->postJson('/api/savings/products', $this->regular())->assertCreated()
            ->assertJsonPath('data.portal_withdrawal_requests', true);

        $existing = SavingsProduct::factory()->create();
        $this->assertTrue((bool) $existing->refresh()->portal_withdrawal_requests);
        $listed = collect($this->getJson('/api/savings/products')->assertOk()->json('data'))->firstWhere('id', $existing->id);
        $this->assertTrue($listed['portal_withdrawal_requests']);
    }

    public function test_an_update_without_it_leaves_it_as_it_was(): void
    {
        $product = SavingsProduct::factory()->create(['portal_withdrawal_requests' => false]);

        $this->putJson("/api/savings/products/{$product->id}", ['name' => 'Renamed Savings'])->assertOk()
            ->assertJsonPath('data.portal_withdrawal_requests', false);
    }

    public function test_it_must_be_a_boolean_and_only_a_regular_product_takes_it(): void
    {
        $this->postJson('/api/savings/products', $this->regular(['portal_withdrawal_requests' => 'sometimes']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.portal_withdrawal_requests.0', 'Say yes or no to withdrawal requests from the member portal.');

        $this->postJson('/api/savings/products', [
            'code' => 'TD-90', 'name' => 'Time Deposit', 'kind' => 'time_deposit', 'term_options_days' => [90],
            'annual_interest_rate' => '3.0000', 'portal_withdrawal_requests' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.portal_withdrawal_requests.0', 'Only a regular savings product takes withdrawal requests from the member portal.');

        $timeDeposit = SavingsProduct::factory()->timeDeposit()->create();
        $this->putJson("/api/savings/products/{$timeDeposit->id}", ['portal_withdrawal_requests' => true])->assertUnprocessable()
            ->assertJsonPath('errors.portal_withdrawal_requests.0', 'Only a regular savings product takes withdrawal requests from the member portal.');
        $this->putJson("/api/savings/products/{$timeDeposit->id}", ['portal_withdrawal_requests' => false])->assertOk();
    }

    public function test_it_needs_savings_settings_and_view_all(): void
    {
        $product = SavingsProduct::factory()->create();
        $role = Role::create(['name' => 'portal_withdrawal_flag_probe_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo(['savings:view', 'savings:settings']);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)->putJson("/api/savings/products/{$product->id}", ['portal_withdrawal_requests' => false])->assertForbidden();
        $this->assertTrue((bool) $product->refresh()->portal_withdrawal_requests);
    }

    /** @return array<string, mixed> */
    private function regular(array $overrides = []): array
    {
        return [
            'code' => 'RS-01',
            'name' => 'Regular Savings',
            'kind' => 'regular',
            'interest_crediting' => 'quarterly',
            'minimum_opening_balance' => '500.00',
            'maintaining_balance' => '100.00',
            'minimum_adb_for_interest' => '1000.00',
            'annual_interest_rate' => '1.2500',
            ...$overrides,
        ];
    }
}
