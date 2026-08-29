<?php

namespace Tests\Feature\CsvImport;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Services\CsvImport\BorrowerMatch;
use App\Services\CsvImport\BorrowerMatcher;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BorrowerMatcherTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function borrower(array $attributes = []): Borrower
    {
        return Borrower::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birthdate' => '1985-06-15',
        ], $attributes));
    }

    public function test_an_account_number_already_on_a_borrower_is_already_imported(): void
    {
        // First in the resolution order, so re-running the importer is a no-op
        // rather than a duplication.
        $existing = $this->borrower();
        DB::table('borrowers')->where('id', $existing->id)->update(['external_account_no' => 'A-001']);

        $match = (new BorrowerMatcher)->match('A-001', 'Juan', 'Dela Cruz', '1985-06-15');

        $this->assertSame(BorrowerMatch::ALREADY_IMPORTED, $match->outcome);
        $this->assertSame($existing->id, $match->borrower?->id);
    }

    public function test_an_unknown_person_is_new(): void
    {
        $this->borrower();

        $match = (new BorrowerMatcher)->match('A-002', 'Maria', 'Santos', '1990-01-01');

        $this->assertSame(BorrowerMatch::NEW, $match->outcome);
        $this->assertNull($match->borrower);
    }

    public function test_one_identity_match_with_a_blank_account_number_is_backfilled_and_nothing_else_is_touched(): void
    {
        $existing = $this->borrower();
        $originalUpdatedAt = $existing->updated_at;
        $auditRowsBefore = AuditLog::query()->count();

        // Case, padding and inner whitespace all differ — the same
        // normalisation StoreBorrowerRequest::describesSamePersonAs() applies.
        $match = (new BorrowerMatcher)->match('A-001', '  juan ', 'DELA  CRUZ', '1985-06-15');

        $this->assertSame(BorrowerMatch::BACKFILLED, $match->outcome);
        $this->assertSame($existing->id, $match->borrower?->id);

        $stored = DB::table('borrowers')->where('id', $existing->id)->first();
        $this->assertSame('A-001', $stored->external_account_no);

        // updated_at is read as "last applicant activity" by
        // registrations:prune. Moving it would reset the abandonment clock on
        // every member this import touches.
        $this->assertSame(
            $originalUpdatedAt?->toDateTimeString(),
            $existing->fresh()?->updated_at?->toDateTimeString(),
        );

        // Borrower is Auditable: a model save would copy the whole original
        // record — name, birthdate, address, contact number, income — into
        // audit_logs.old_values permanently, for a field the member never
        // changed.
        $this->assertSame($auditRowsBefore, AuditLog::query()->count());
    }

    public function test_the_backfill_guard_is_re_asserted_so_a_claimed_borrower_is_never_overwritten(): void
    {
        $existing = $this->borrower();

        $first = (new BorrowerMatcher)->match('A-001', 'Juan', 'Dela Cruz', '1985-06-15');
        $this->assertSame(BorrowerMatch::BACKFILLED, $first->outcome);

        // The same person arriving under a second account number must not
        // silently overwrite the first claim.
        $second = (new BorrowerMatcher)->match('A-999', 'Juan', 'Dela Cruz', '1985-06-15');

        $this->assertSame(BorrowerMatch::ACCOUNT_NO_CONFLICT, $second->outcome);
        $this->assertTrue($second->needsReview());
        $this->assertSame('A-001', DB::table('borrowers')->where('id', $existing->id)->value('external_account_no'));
    }

    public function test_two_people_with_the_same_name_and_birthdate_are_ambiguous(): void
    {
        // The target coop has 44 members and heavily repeated surnames. Picking
        // one here would merge two members' loan books, silently.
        $first = $this->borrower();
        $second = $this->borrower();

        $match = (new BorrowerMatcher)->match('A-001', 'Juan', 'Dela Cruz', '1985-06-15');

        $this->assertSame(BorrowerMatch::AMBIGUOUS_MATCH, $match->outcome);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $match->candidateIds);
        $this->assertNull(DB::table('borrowers')->where('id', $first->id)->value('external_account_no'));
        $this->assertNull(DB::table('borrowers')->where('id', $second->id)->value('external_account_no'));
    }

    public function test_a_name_match_without_a_birthdate_is_ambiguous_rather_than_a_merge_or_a_duplicate(): void
    {
        // A name match with no birthdate on either side is a coincidence
        // waiting to happen. Creating would duplicate the member; merging could
        // file a stranger's loans against them. A human decides.
        $existing = $this->borrower(['birthdate' => null]);

        $match = (new BorrowerMatcher)->match('A-001', 'Juan', 'Dela Cruz', null);

        $this->assertSame(BorrowerMatch::AMBIGUOUS_MATCH, $match->outcome);
        $this->assertSame([$existing->id], $match->candidateIds);
        $this->assertNull(DB::table('borrowers')->where('id', $existing->id)->value('external_account_no'));
    }

    public function test_a_different_birthdate_is_a_different_person(): void
    {
        // This is the whole reason BorrowerDuplicateDetector is not used: its
        // tier 1 matches on names alone and would call these two the same
        // member.
        $this->borrower();

        $match = (new BorrowerMatcher)->match('A-001', 'Juan', 'Dela Cruz', '1991-02-02');

        $this->assertSame(BorrowerMatch::NEW, $match->outcome);
    }
}
