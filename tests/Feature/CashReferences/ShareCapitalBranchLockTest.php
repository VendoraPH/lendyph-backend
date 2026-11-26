<?php

namespace Tests\Feature\CashReferences;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\ShareCapitalLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A share capital entry posts in its member's branch, so it takes the branch
 * row lock first and refuses a branch that is closed or waiting to close, in
 * the lock's words (finish run round 2, from the #369 review): the ledger, a
 * member's pledge entry, a bulk batch and an auto-credit run. A suspended
 * branch keeps taking share capital, as it keeps taking collections.
 */
class ShareCapitalBranchLockTest extends TestCase
{
    use SetupLendyPH;

    private const CLOSING = 'Branch "Far Branch" (FAR) is waiting to close, so nothing new can be added there.';

    private const CLOSED = 'Branch "Far Branch" (FAR) is closed, so nothing new can be added there.';

    private const AUTO_CREDIT_CLOSING = 'Branch "Far Branch" (FAR) is waiting to close, so auto-credit can\'t credit its members\' share capital. Switch their auto-credit off, or transfer them, first.';

    private Branch $far;

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->member = Borrower::factory()->create(['branch_id' => $this->far->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_branch_waiting_to_close_takes_no_share_capital_on_any_route(): void
    {
        $this->closing();
        $this->member->shareCapitalPledge->update(['auto_credit' => true, 'amount' => 500]);

        $this->ledgerEntry()->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', self::CLOSING);
        $this->ledgerEntry(['method' => 'gcash', 'reference_number' => 'GC-1'])->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', self::CLOSING);
        $this->pledgeEntry()->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', self::CLOSING);
        $this->assertSame([self::CLOSING], $this->bulkEntry([$this->member])->assertUnprocessable()->json('errors')['entries.0.pledge_id']);
        $this->postJson('/api/auto-credit/process', ['cycle' => '30', 'method' => 'cash'])->assertUnprocessable()->assertJsonPath('errors.branch.0', self::AUTO_CREDIT_CLOSING);

        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_a_closed_branch_takes_none_either(): void
    {
        $this->far->forceFill(['status' => Branch::STATUS_CLOSED, 'closed_on' => '2026-11-20'])->save();

        $this->ledgerEntry()->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', self::CLOSED);
        $this->pledgeEntry()->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', self::CLOSED);
        $this->assertSame([self::CLOSED], $this->bulkEntry([$this->member])->assertUnprocessable()->json('errors')['entries.0.pledge_id']);

        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_one_row_in_a_closing_branch_stops_the_whole_bulk_batch(): void
    {
        $this->closing();
        $here = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $errors = $this->bulkEntry([$here, $this->member])->assertUnprocessable()->json('errors');

        $this->assertSame([self::CLOSING], $errors['entries.1.pledge_id']);
        $this->assertArrayNotHasKey('entries.0.pledge_id', $errors);
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_a_suspended_branch_still_takes_share_capital_and_a_cancelled_closure_lifts_the_refusal(): void
    {
        $this->far->forceFill(['status' => Branch::STATUS_SUSPENDED])->save();
        $this->ledgerEntry()->assertCreated();

        $this->closing()->update(['status' => BranchClosure::STATUS_CANCELLED, 'decided_by' => $this->admin->id, 'decided_at' => now()]);
        $this->pledgeEntry()->assertCreated();

        $this->assertSame([$this->far->id], ShareCapitalLedger::query()->pluck('branch_id')->unique()->values()->all());
    }

    private function closing(): BranchClosure
    {
        return BranchClosure::create([
            'branch_id' => $this->far->id, 'status' => BranchClosure::STATUS_PENDING, 'effective_on' => now()->toDateString(),
            'reason' => 'Merged into Main', 'requested_by' => $this->admin->id,
        ]);
    }

    private function ledgerEntry(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id,
            'date' => '2026-11-24',
            'description' => 'Contribution',
            'type' => 'credit',
            'amount' => 1000,
            ...$overrides,
        ]);
    }

    private function pledgeEntry(): TestResponse
    {
        return $this->postJson("/api/pledges/{$this->member->shareCapitalPledge->id}/entries", ['amount' => 250, 'type' => 'credit', 'date' => '2026-11-24']);
    }

    /** @param  list<Borrower>  $members */
    private function bulkEntry(array $members): TestResponse
    {
        return $this->postJson('/api/pledges/bulk-entries', [
            'entries' => array_map(fn (Borrower $member) => ['pledge_id' => $member->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'], $members),
        ]);
    }
}
