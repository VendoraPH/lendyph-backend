<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LindaQuestion;
use App\Models\Loan;
use App\Services\BorrowerPurgeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A member purge removes the member's id from every Linda log row that listed
 * them (Linda v1 plan, "Privacy and the log"), inside the purge's own
 * transaction, so a purge that fails leaves the log as it was.
 */
class LindaMemberPurgeTest extends TestCase
{
    public function test_a_purge_removes_the_member_from_every_list_and_leaves_other_members(): void
    {
        Storage::fake('private');
        $branch = Branch::query()->orderBy('id')->firstOrFail();
        $member = Borrower::factory()->create(['branch_id' => $branch->id]);
        $other = Borrower::factory()->create(['branch_id' => $branch->id]);

        $both = $this->question([$member->id, $other->id]);
        $alone = $this->question([$member->id]);
        $untouched = $this->question([$other->id]);
        $none = $this->question(null);
        $lookalike = $this->question([$member->id * 10 + 1]);

        app(BorrowerPurgeService::class)->purge($member);

        $this->assertSame([$other->id], $both->refresh()->member_ids);
        $this->assertSame([], $alone->refresh()->member_ids);
        $this->assertSame([$other->id], $untouched->refresh()->member_ids);
        $this->assertNull($none->refresh()->member_ids);
        $this->assertSame([$member->id * 10 + 1], $lookalike->refresh()->member_ids);
        $this->assertDatabaseMissing('borrowers', ['id' => $member->id]);
    }

    public function test_a_purge_that_fails_leaves_the_log_as_it_was(): void
    {
        Storage::fake('private');
        $branch = Branch::query()->orderBy('id')->firstOrFail();
        $member = Borrower::factory()->create(['branch_id' => $branch->id]);
        Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $branch->id]);
        $row = $this->question([$member->id]);

        try {
            app(BorrowerPurgeService::class)->purge($member->fresh());
            $this->fail('The purge should have been refused by the loan.');
        } catch (QueryException) {
            $this->assertSame([$member->id], $row->refresh()->member_ids);
        }
    }

    /** @param list<int>|null $memberIds */
    private function question(?array $memberIds): LindaQuestion
    {
        return LindaQuestion::query()->create([
            'asked_at' => now(),
            'source' => LindaQuestion::SOURCE_MATCHER,
            'intent' => 'overdue_accounts',
            'outcome' => 'answered',
            'member_ids' => $memberIds,
            'duration_ms' => 2,
        ]);
    }
}
