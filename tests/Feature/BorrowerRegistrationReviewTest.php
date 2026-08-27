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

    private function attachValidId(Borrower $borrower): void
    {
        $borrower->documents()->create([
            'type' => 'valid_id',
            'label' => 'umid',
            'file_path' => 'documents/valid_id/test.jpg',
            'original_filename' => 'test.jpg',
        ]);
    }

    public function test_approve_registration_flips_pending_to_active_and_records_approver(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);
        $this->attachValidId($borrower);

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

    /**
     * End-to-end proof that hiding pending applicants is a READ-side filter.
     *
     * The pledge row exists the whole time; approval is what makes it visible,
     * so nothing has to be backfilled when a registration is approved.
     */
    public function test_approving_a_registration_reveals_the_borrowers_pledge_in_the_pledge_list(): void
    {
        $applicant = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
            'pledge_amount' => 300,
        ]);
        $this->attachValidId($applicant);

        $before = collect($this->getJson('/api/pledges?per_page=100')->assertOk()->json('data'))
            ->pluck('borrower_id');
        $this->assertNotContains($applicant->id, $before->all());

        $this->patchJson("/api/borrowers/{$applicant->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $after = collect($this->getJson('/api/pledges?per_page=100')->assertOk()->json('data'));
        $row = $after->firstWhere('borrower_id', $applicant->id);

        $this->assertNotNull($row, 'An approved member belongs in Pledge Entry.');
        $this->assertEqualsWithDelta(300.0, $row['amount'], 0.01, 'The amount typed at registration survived the wait.');
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

    public function test_approve_registration_requires_at_least_one_valid_id(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);
        // No valid ID attached — the KYC gate must block approval.

        $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_id']);

        $this->assertDatabaseHas('borrowers', [
            'id' => $borrower->id,
            'status' => 'pending',
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

    public function test_reject_registration_deletes_the_share_capital_pledge(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        // The submission hook auto-creates a pledge for every borrower.
        $this->assertDatabaseHas('share_capital_pledges', ['borrower_id' => $borrower->id]);

        $this->patchJson("/api/borrowers/{$borrower->id}/reject", [
            'reason' => 'Incomplete requirements.',
        ])->assertOk();

        // A rejected applicant is not a member — the dangling pledge must be gone.
        $this->assertDatabaseMissing('share_capital_pledges', ['borrower_id' => $borrower->id]);
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
