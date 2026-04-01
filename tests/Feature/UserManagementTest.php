<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class UserManagementTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_list_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_create_user(): void
    {
        $response = $this->postJson('/api/users', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'johndoe',
            'email' => 'john@test.com',
            'mobile_number' => '09171234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $this->branch->id,
            'role' => 'loan-officer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.username', 'johndoe');
    }

    public function test_update_user(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('viewer');

        $response = $this->putJson("/api/users/{$user->id}", [
            'first_name' => 'Updated',
            'last_name' => $user->last_name,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.first_name', 'Updated');
    }

    public function test_deactivate_and_reactivate_user(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('viewer');

        $this->patchJson("/api/users/{$user->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'deactivated']);

        $this->patchJson("/api/users/{$user->id}/reactivate")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $viewer = User::factory()->create(['branch_id' => $this->branch->id]);
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);

        $response = $this->postJson('/api/users', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'username' => 'janedoe',
            'email' => 'jane@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $this->branch->id,
            'role' => 'viewer',
        ]);

        $response->assertForbidden();
    }
}
