<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BorrowerRegistrationReviewTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_approve_registration_flips_pending_to_active_and_records_approver(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.approved_by', $this->admin->id);

        $this->assertDatabaseHas('borrowers', [
            'id' => $borrower->id,
            'status' => 'active',
            'approved_by' => $this->admin->id,
        ]);
        $this->assertNotNull($borrower->fresh()->approved_at);
    }

    public function test_approve_registration_rejects_non_pending_borrower(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('borrowers', [
            'id' => $borrower->id,
            'status' => 'active',
            'approved_by' => null,
        ]);
    }

    public function test_reject_registration_sets_rejected_status_and_reason_without_deleting(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/reject", [
            'reason' => 'ID photo is unreadable.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'ID photo is unreadable.')
            ->assertJsonPath('data.rejected_by', $this->admin->id);

        // The row must NOT be hard-deleted — the audit trail is preserved.
        $this->assertDatabaseHas('borrowers', [
            'id' => $borrower->id,
            'status' => 'rejected',
            'rejection_reason' => 'ID photo is unreadable.',
            'rejected_by' => $this->admin->id,
        ]);
        $this->assertNotNull($borrower->fresh()->rejected_at);
    }

    public function test_reject_registration_requires_a_reason(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id, 'status' => 'pending']);
    }

    public function test_reject_registration_rejects_non_pending_borrower(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/reject", ['reason' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id, 'status' => 'active']);
    }

    public function test_status_pending_filter_returns_only_pending_and_stats_expose_pending_and_rejected(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        Borrower::factory()->count(2)->create(['branch_id' => $this->branch->id, 'status' => 'pending']);
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'rejected']);

        $response = $this->getJson('/api/borrowers?status=pending')->assertOk();

        $response->assertJsonCount(2, 'data');
        foreach ($response->json('data') as $row) {
            $this->assertSame('pending', $row['status']);
        }

        $response->assertJsonPath('meta.stats.active', 1)
            ->assertJsonPath('meta.stats.pending', 2)
            ->assertJsonPath('meta.stats.rejected', 1);
    }

    public function test_authenticated_create_still_requires_branch_id(): void
    {
        $this->postJson('/api/borrowers', [
            'first_name' => 'NoBranch',
            'last_name' => 'Operator',
            'contact_number' => '09171234567',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);
    }

    public function test_borrowers_index_returns_paginated_envelope(): void
    {
        Borrower::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        $this->getJson('/api/borrowers')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'borrower_code', 'status']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'last_page', 'stats'],
            ]);
    }
}
