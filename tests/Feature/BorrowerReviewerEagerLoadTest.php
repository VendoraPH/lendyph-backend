<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every endpoint that returns a BorrowerResource actually carries the reviewer.
 *
 * BorrowerResource exposes `approved_by_user` / `rejected_by_user` through
 * whenLoaded(), which OMITS the key rather than lazily fetching it. That is what
 * makes the field safe to add ahead of the eager loading — and also what makes a
 * half-landed version invisible: the resource looks finished, every one of its
 * own tests passes, and the Rejected-applications tab still renders
 * "Reviewer #7" because no controller ever loaded the relation.
 *
 * So the resource's tests cannot be the whole guard. These drive the real
 * endpoints and assert the reviewer arrives over HTTP, which is the only thing
 * the UI can actually consume. Each one fails if the corresponding
 * `load('approvedBy', 'rejectedBy')` is dropped from BorrowerController.
 */
class BorrowerReviewerEagerLoadTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_borrowers_list_carries_the_reviewer_not_just_the_id(): void
    {
        $this->reviewedBorrower();

        $row = $this->getJson('/api/borrowers')->assertOk()->json('data.0');

        $this->assertSame($this->admin->id, $row['approved_by'], 'The bare id stays.');
        $this->assertArrayHasKey('approved_by_user', $row, 'The list did not eager-load approvedBy.');
        $this->assertArrayHasKey('rejected_by_user', $row, 'The list did not eager-load rejectedBy.');
        $this->assertSame($this->admin->id, $row['approved_by_user']['id']);
        $this->assertSame($this->admin->id, $row['rejected_by_user']['id']);
        $this->assertNotEmpty(
            $row['approved_by_user']['full_name'] ?? $row['approved_by_user']['first_name'],
            'A name is the entire point — an id here is what the screen already had.',
        );
    }

    public function test_show_carries_the_reviewer(): void
    {
        $borrower = $this->reviewedBorrower();

        $data = $this->getJson("/api/borrowers/{$borrower->id}")->assertOk()->json('data');

        $this->assertSame($this->admin->id, $data['approved_by_user']['id']);
        $this->assertSame($this->admin->id, $data['rejected_by_user']['id']);
    }

    public function test_update_carries_the_reviewer(): void
    {
        $borrower = $this->reviewedBorrower();

        $data = $this->putJson("/api/borrowers/{$borrower->id}", ['first_name' => 'Renamed'])
            ->assertOk()
            ->json('data');

        $this->assertSame('Renamed', $data['first_name']);
        $this->assertSame($this->admin->id, $data['approved_by_user']['id']);
    }

    /**
     * The approve and reject responses are the ones the reviewer screen renders
     * immediately after acting, so they are the most visible of the six.
     */
    public function test_approving_a_registration_returns_the_approver(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);
        $this->attachValidId($borrower);

        $data = $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
            ->assertOk()
            ->json('data');

        $this->assertSame('active', $data['status']);
        $this->assertSame($this->admin->id, $data['approved_by_user']['id']);
    }

    public function test_rejecting_a_registration_returns_the_rejecter(): void
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $data = $this->patchJson("/api/borrowers/{$borrower->id}/reject", [
            'reason' => 'ID photo is unreadable.',
        ])->assertOk()->json('data');

        $this->assertSame('rejected', $data['status']);
        $this->assertSame($this->admin->id, $data['rejected_by_user']['id']);
    }

    /**
     * A borrower nobody has reviewed still answers with the key, valued null,
     * rather than omitting it — the relation IS loaded, it is simply empty. The
     * frontend can therefore read the field unconditionally.
     */
    public function test_an_unreviewed_borrower_reports_a_null_reviewer_rather_than_a_missing_key(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);

        $row = $this->getJson('/api/borrowers')->assertOk()->json('data.0');

        $this->assertArrayHasKey('approved_by_user', $row);
        $this->assertNull($row['approved_by_user']);
        $this->assertNull($row['rejected_by_user']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function reviewedBorrower(): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'rejected',
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'rejected_by' => $this->admin->id,
            'rejected_at' => now(),
        ]);
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
}
