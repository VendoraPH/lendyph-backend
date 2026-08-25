<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\BorrowerSubmissionToken;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Services\BorrowerPurgeService;
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

    /**
     * "Pending" plus a loan is a real state in this data model, not a
     * contradiction. The portfolio database holds nine such loans across four
     * pending borrowers — 30% of its loan book — every one of them matching the
     * status-and-documents rule this command originally shipped with.
     *
     * binhs-coop happens to have none, which is the only reason that rule
     * looked safe when it was first checked against production.
     */
    public function test_never_prunes_a_pending_borrower_that_has_a_loan(): void
    {
        $borrower = $this->pendingBorrower('Borrowed', now()->subDays(300));
        Loan::factory()->create([
            'borrower_id' => $borrower->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'pruned',
            'auditable_id' => $borrower->id,
        ]);
    }

    public function test_never_prunes_a_pending_borrower_with_share_capital_movement(): void
    {
        $borrower = $this->pendingBorrower('Contributed', now()->subDays(300));
        ShareCapitalLedger::factory()->create(['borrower_id' => $borrower->id]);

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    public function test_never_prunes_a_pending_borrower_with_a_gcash_transaction(): void
    {
        $borrower = $this->pendingBorrower('Cashed', now()->subDays(300));
        GCashTransaction::factory()->create(['borrower_id' => $borrower->id]);

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    /**
     * The other half of the financial-history guard. Every borrower gets a
     * ShareCapitalPledge from Borrower::booted(), so gating on the pledge — the
     * obvious sibling of the ledger — would exclude every row and quietly turn
     * the command into a no-op that still reports success.
     */
    public function test_the_auto_created_pledge_alone_does_not_protect_a_borrower(): void
    {
        $borrower = $this->pendingBorrower('OnlyPledge', now()->subDays(30));
        $this->assertDatabaseHas('share_capital_pledges', ['borrower_id' => $borrower->id]);
        $this->assertSame(0, $borrower->loans()->count());

        $this->artisan('registrations:prune')->assertSuccessful();

        $this->assertDatabaseMissing('borrowers', ['id' => $borrower->id]);
    }

    /**
     * A failed purge must leave the borrower AND its files intact together.
     *
     * The files used to be unlinked inside the transaction, so a throw after the
     * first unlink rolled the rows back and left the files gone — the borrower
     * survived pointing at a photo that no longer existed. The audit row was
     * written outside that transaction too, so it survived as well, claiming a
     * prune that never happened.
     */
    public function test_a_failed_purge_leaves_no_audit_row_and_no_missing_files(): void
    {
        $borrower = $this->pendingBorrower('Restricted', now()->subDays(300));
        $borrower->forceFill(['photo_path' => 'borrowers/photos/'.$borrower->id.'/photo.jpg'])->saveQuietly();
        Storage::disk('private')->put($borrower->photo_path, 'photo-bytes');

        // A loan makes $borrower->delete() throw on the restrictOnDelete FK,
        // which is exactly the failure the portfolio box was about to hit.
        Loan::factory()->create([
            'borrower_id' => $borrower->id,
            'branch_id' => $this->branch->id,
        ]);

        // Bypass the command's guard to exercise the service's own crash safety.
        try {
            app(BorrowerPurgeService::class)->purge($borrower->fresh(), audit: false);
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
        Storage::disk('private')->assertExists($borrower->photo_path);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'pruned',
            'auditable_id' => $borrower->id,
        ]);
    }
}
