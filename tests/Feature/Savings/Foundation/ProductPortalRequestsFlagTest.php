<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\Role;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Portal\Requests\OpeningRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6 (batch): a savings product's `portal_account_requests`
 * decides whether the Member Portal offers it for new-account requests
 * (OpeningRequests::openProducts()). The product create and update requests
 * take it as a boolean, under the product's own savings:settings plus
 * branches:view_all, and the product resource sends it. Only a regular
 * product can be requested from the portal.
 */
class ProductPortalRequestsFlagTest extends TestCase
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

    public function test_create_and_update_take_the_flag_and_the_resource_sends_it(): void
    {
        $id = $this->postJson('/api/savings/products', $this->regular(['portal_account_requests' => true]))
            ->assertCreated()
            ->assertJsonPath('data.portal_account_requests', true)
            ->json('data.id');
        $this->assertTrue((bool) SavingsProduct::query()->findOrFail($id)->portal_account_requests);

        $this->putJson("/api/savings/products/{$id}", ['portal_account_requests' => false])
            ->assertOk()
            ->assertJsonPath('data.portal_account_requests', false);
        $this->assertFalse((bool) SavingsProduct::query()->findOrFail($id)->portal_account_requests);

        $listed = collect($this->getJson('/api/savings/products')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertFalse($listed['portal_account_requests']);
    }

    public function test_it_defaults_to_off_and_decides_the_portals_open_products(): void
    {
        $id = $this->postJson('/api/savings/products', $this->regular())->assertCreated()
            ->assertJsonPath('data.portal_account_requests', false)
            ->json('data.id');
        SavingsProduct::query()->whereKey($id)->update(['is_active' => true]);

        $this->assertFalse(app(OpeningRequests::class)->openProducts()->whereKey($id)->exists());

        $this->putJson("/api/savings/products/{$id}", ['portal_account_requests' => true])->assertOk();
        $this->assertTrue(app(OpeningRequests::class)->openProducts()->whereKey($id)->exists());
    }

    public function test_it_must_be_a_boolean_and_only_a_regular_product_takes_it(): void
    {
        $this->postJson('/api/savings/products', $this->regular(['portal_account_requests' => 'sometimes']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.portal_account_requests.0', 'Say yes or no to new-account requests from the member portal.');

        $this->postJson('/api/savings/products', [
            'code' => 'TD-90', 'name' => 'Time Deposit', 'kind' => 'time_deposit', 'term_options_days' => [90],
            'annual_interest_rate' => '3.0000', 'portal_account_requests' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.portal_account_requests.0', 'Only a regular savings product can be requested from the member portal.');

        $timeDeposit = SavingsProduct::factory()->timeDeposit()->create();
        $this->putJson("/api/savings/products/{$timeDeposit->id}", ['portal_account_requests' => true])->assertUnprocessable()
            ->assertJsonPath('errors.portal_account_requests.0', 'Only a regular savings product can be requested from the member portal.');
        $this->putJson("/api/savings/products/{$timeDeposit->id}", ['portal_account_requests' => false])->assertOk();
    }

    public function test_it_needs_savings_settings_and_view_all(): void
    {
        $product = SavingsProduct::factory()->create();
        $role = Role::create(['name' => 'portal_flag_probe_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo(['savings:view', 'savings:settings']);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)->putJson("/api/savings/products/{$product->id}", ['portal_account_requests' => true])->assertForbidden();
        $this->assertFalse((bool) $product->refresh()->portal_account_requests);
    }

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
