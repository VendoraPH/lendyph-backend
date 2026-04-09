<?php

namespace Tests\Feature;

use App\Models\Fee;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class FeeTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_list_fees(): void
    {
        Fee::factory()->count(3)->create();

        $this->getJson('/api/fees')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_create_fee_fixed(): void
    {
        $response = $this->postJson('/api/fees', [
            'name' => 'Processing Fee',
            'type' => 'fixed',
            'value' => 500,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Processing Fee')
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.value', 500);

        $this->assertDatabaseHas('fees', ['name' => 'Processing Fee', 'type' => 'fixed']);
    }

    public function test_create_fee_percentage(): void
    {
        $response = $this->postJson('/api/fees', [
            'name' => 'Service Charge',
            'type' => 'percentage',
            'value' => 2.5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'percentage');
    }

    public function test_create_fee_with_conditions(): void
    {
        $response = $this->postJson('/api/fees', [
            'name' => 'Late Payment Fee',
            'type' => 'fixed',
            'value' => 200,
            'conditions' => ['term_days_gt' => 30],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.conditions.term_days_gt', 30);
    }

    public function test_create_fee_name_must_be_unique(): void
    {
        Fee::factory()->create(['name' => 'Processing Fee']);

        $this->postJson('/api/fees', [
            'name' => 'Processing Fee',
            'type' => 'fixed',
            'value' => 500,
        ])->assertUnprocessable();
    }

    public function test_create_fee_requires_required_fields(): void
    {
        $this->postJson('/api/fees', [])->assertUnprocessable();
    }

    public function test_show_fee(): void
    {
        $fee = Fee::factory()->create();

        $this->getJson("/api/fees/{$fee->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $fee->id)
            ->assertJsonPath('data.name', $fee->name);
    }

    public function test_update_fee(): void
    {
        $fee = Fee::factory()->create(['name' => 'Old Name', 'value' => 100]);

        $response = $this->putJson("/api/fees/{$fee->id}", [
            'name' => 'New Name',
            'value' => 250,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.value', 250);

        $this->assertDatabaseHas('fees', ['id' => $fee->id, 'name' => 'New Name']);
    }

    public function test_delete_fee(): void
    {
        $fee = Fee::factory()->create();

        $this->deleteJson("/api/fees/{$fee->id}")
            ->assertOk();

        $this->assertDatabaseMissing('fees', ['id' => $fee->id]);
    }

    public function test_viewer_cannot_create_fee(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);

        $this->postJson('/api/fees', [
            'name' => 'Test Fee',
            'type' => 'fixed',
            'value' => 100,
        ])->assertForbidden();
    }

    public function test_viewer_can_list_fees(): void
    {
        Fee::factory()->count(2)->create();

        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);

        $this->getJson('/api/fees')->assertOk();
    }
}
