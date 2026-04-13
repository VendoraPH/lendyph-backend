<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BorrowerTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_create_borrower(): void
    {
        $response = $this->postJson('/api/borrowers', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'address' => '123 Main St, Butuan City',
            'contact_number' => '09171234567',
            'email' => 'maria@test.com',
            'birthdate' => '1990-01-15',
            'civil_status' => 'single',
            'gender' => 'female',
            'employer_or_business' => 'Sari-sari Store',
            'monthly_income' => 25000,
            'branch_id' => $this->branch->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'Maria')
            ->assertJsonFragment(['borrower_code' => 'BRW-000001']);

        // The Borrower::created hook must auto-create a share capital pledge row.
        $this->assertDatabaseHas('share_capital_pledges', [
            'borrower_id' => $response->json('data.id'),
            'amount' => 0,
            'schedule' => '15/30',
            'auto_credit' => false,
        ]);
    }

    public function test_list_and_search_borrowers(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Juan']);
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Pedro']);

        $this->getJson('/api/borrowers')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/borrowers?search=Juan')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_update_borrower(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->putJson("/api/borrowers/{$borrower->id}", [
            'first_name' => 'UpdatedName',
            'last_name' => $borrower->last_name,
            'address' => $borrower->address,
            'contact_number' => $borrower->contact_number,
            'branch_id' => $this->branch->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.first_name', 'UpdatedName');
    }

    public function test_deactivate_and_reactivate_borrower(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->patchJson("/api/borrowers/{$borrower->id}/deactivate")->assertOk();
        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id, 'status' => 'inactive']);

        $this->patchJson("/api/borrowers/{$borrower->id}/reactivate")->assertOk();
        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id, 'status' => 'active']);
    }

    public function test_create_co_maker(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson("/api/borrowers/{$borrower->id}/co-makers", [
            'first_name' => 'Carlos',
            'last_name' => 'Reyes',
            'address' => '456 Second St',
            'contact_number' => '09181234567',
            'occupation' => 'Teacher',
            'employer' => 'DepEd',
            'monthly_income' => 30000,
            'relationship_to_borrower' => 'sibling',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['co_maker_code' => 'CMK-000001']);
    }
}
