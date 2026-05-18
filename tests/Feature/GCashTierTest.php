<?php

namespace Tests\Feature;

use App\Models\GCashTier;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class GCashTierTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private array $sampleTiers = [
        ['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1],
        ['min_amount' => 1501, 'max_amount' => 5000, 'cash_in_rate' => 30, 'cash_out_rate' => 25, 'display_order' => 2],
    ];

    public function test_index_returns_tiers_ordered_by_display_order(): void
    {
        GCashTier::create(['min_amount' => 1501, 'max_amount' => 5000, 'cash_in_rate' => 30, 'cash_out_rate' => 25, 'display_order' => 2]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);

        $response = $this->getJson('/api/gcash/tiers');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.display_order', 1)
            ->assertJsonPath('data.1.display_order', 2);
    }

    public function test_replace_persists_new_tiers_and_wipes_old(): void
    {
        GCashTier::create(['min_amount' => 1, 'max_amount' => 100, 'cash_in_rate' => 5, 'cash_out_rate' => 5, 'display_order' => 1]);

        $response = $this->putJson('/api/gcash/tiers', ['tiers' => $this->sampleTiers]);

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEquals(2, GCashTier::count());
        $this->assertEquals(20, (float) GCashTier::orderBy('display_order')->first()->cash_in_rate);
    }

    public function test_replace_rejects_overlapping_ranges(): void
    {
        $overlap = [
            ['min_amount' => 1, 'max_amount' => 2000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1],
            ['min_amount' => 1500, 'max_amount' => 5000, 'cash_in_rate' => 30, 'cash_out_rate' => 25, 'display_order' => 2],
        ];

        $this->putJson('/api/gcash/tiers', ['tiers' => $overlap])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tiers']);
    }

    public function test_replace_rejects_max_not_greater_than_min(): void
    {
        $bad = [
            ['min_amount' => 1000, 'max_amount' => 500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1],
        ];

        $this->putJson('/api/gcash/tiers', ['tiers' => $bad])
            ->assertUnprocessable();
    }

    public function test_cashier_cannot_replace_tiers(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $this->actingAs($cashier);

        $this->putJson('/api/gcash/tiers', ['tiers' => $this->sampleTiers])
            ->assertForbidden();
    }

    public function test_cashier_can_view_tiers(): void
    {
        GCashTier::create($this->sampleTiers[0]);
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $this->actingAs($cashier);

        $this->getJson('/api/gcash/tiers')->assertOk()->assertJsonCount(1, 'data');
    }
}
