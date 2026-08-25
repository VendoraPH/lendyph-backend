<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\BorrowerSubmissionToken;
use App\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneAbandonedRegistrationsTest extends TestCase
{
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        $this->branch = Branch::first();
        Storage::fake('private');
    }

    private function pendingBorrower(string $lastName, ?string $updatedAt = null): Borrower
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'last_name' => $lastName,
            'status' => 'pending',
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        if ($updatedAt) {
            // Timestamps are managed, so bypass the model to backdate.
            Borrower::withoutTimestamps(fn () => $borrower->forceFill(['updated_at' => $updatedAt])->saveQuietly());
            $borrower->refresh();
        }

        return $borrower;
    }

    public function test_prunes_an_incomplete_pending_registration_past_the_window(): void
    {
        $borrower = $this->pendingBorrower('Abandoned', now()->subDays(30));

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseMissing('borrowers', ['id' => $borrower->id]);
        // Borrower::booted() creates this for every borrower and the FK is
        // restrictOnDelete, so its absence proves the cascade ran in order.
        $this->assertDatabaseMissing('share_capital_pledges', ['borrower_id' => $borrower->id]);
    }

    /**
     * The regression guard that matters most. `Borrower::booted()` creates a
     * ShareCapitalPledge for every borrower and that FK is restrictOnDelete, so
     * a naive $borrower->delete() throws a QueryException on every single row.
     */
    public function test_does_not_throw_on_the_restrict_on_delete_pledge(): void
    {
        $borrower = $this->pendingBorrower('Pledged', now()->subDays(30));
        $this->assertDatabaseHas('share_capital_pledges', ['borrower_id' => $borrower->id]);

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseMissing('borrowers', ['id' => $borrower->id]);
    }

    /**
     * binhs-coop production holds 30 pending applications carrying 44 documents,
     * the oldest three months old. That is the operator review queue. A rule
     * based on age alone would delete real applications and their KYC files.
     */
    public function test_never_prunes_a_pending_registration_that_has_a_valid_id(): void
    {
        $borrower = $this->pendingBorrower('HasValidId', now()->subDays(365));
        $document = $borrower->documents()->create([
            'type' => 'valid_id',
            'file_path' => 'documents/valid_id/borrower/'.$borrower->id.'/x.jpg',
            'original_filename' => 'x.jpg',
        ]);

        // The document must be OLD too, or the recency clause protects this row
        // and the test passes without exercising the valid_id exclusion at all.
        // This is the real binhs-coop shape: a months-old application that has
        // its IDs attached and is sitting in the review queue.
        $document->forceFill(['created_at' => now()->subDays(300)])->saveQuietly();

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    public function test_does_not_prune_inside_the_window(): void
    {
        $borrower = $this->pendingBorrower('Recent', now()->subDays(2));

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    public function test_does_not_prune_non_pending_borrowers(): void
    {
        $kept = [];
        foreach (['active', 'inactive', 'rejected'] as $status) {
            $b = $this->pendingBorrower('Status'.ucfirst($status), now()->subDays(365));
            Borrower::withoutTimestamps(fn () => $b->forceFill(['status' => $status])->saveQuietly());
            $kept[] = $b->id;
        }

        $this->artisan('registrations:prune')->assertSuccessful();

        foreach ($kept as $id) {
            $this->assertDatabaseHas('borrowers', ['id' => $id]);
        }
    }

    /**
     * A valid-ID upload writes to `documents` via morphMany with no $touches, so
     * it never bumps borrowers.updated_at. Without treating a recent document as
     * activity in its own right, an applicant who just uploaded would look idle.
     */
    public function test_a_recent_document_counts_as_activity_despite_a_stale_updated_at(): void
    {
        $borrower = $this->pendingBorrower('RecentUpload', now()->subDays(60));
        $borrower->documents()->create([
            'type' => 'proof_of_income',
            'file_path' => 'documents/other/'.$borrower->id.'/y.jpg',
            'original_filename' => 'y.jpg',
        ]);

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $borrower = $this->pendingBorrower('DryRun', now()->subDays(30));

        $this->artisan('registrations:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    public function test_drops_expired_tokens_without_touching_their_borrower(): void
    {
        $borrower = $this->pendingBorrower('TokenOwner');
        Borrower::withoutTimestamps(fn () => $borrower->forceFill(['status' => 'active'])->saveQuietly());

        $token = BorrowerSubmissionToken::create([
            'borrower_id' => $borrower->id,
            'token' => hash('sha256', 'stk_old'),
            'expires_at' => now()->subDays(5),
        ]);

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseMissing('borrower_submission_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    /**
     * The Auditable trait would otherwise write the borrower's full attributes —
     * name, birthdate, address, contact number, income — into old_values and
     * keep them forever, defeating the point of a retention prune.
     */
    public function test_prune_records_only_the_borrower_code_not_the_personal_data(): void
    {
        $borrower = $this->pendingBorrower('Privacy', now()->subDays(30));
        $surname = $borrower->last_name;

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pruned',
            'auditable_id' => $borrower->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'deleted',
            'auditable_id' => $borrower->id,
        ]);

        $pruned = AuditLog::where('action', 'pruned')
            ->where('auditable_id', $borrower->id)
            ->firstOrFail();

        expect(json_encode($pruned->new_values))->not->toContain($surname)
            ->and($pruned->old_values)->toBeNull();
    }

    public function test_is_idempotent(): void
    {
        $this->pendingBorrower('Twice', now()->subDays(30));

        $this->artisan('registrations:prune')->assertSuccessful();
        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertSame(0, Borrower::where('last_name', 'Twice')->count());
    }
}
