<?php

namespace Tests\Feature\Ledger\ShareCapitalGCash;

use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Services\GCashService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * GCash in the books (Phase 2, decision 6). Amount A, charge F.
 *
 * - Cash In, once paid (at the counter, or later through mark-paid), dated
 *   `paid_at`: Dr 1010 A+F / Cr 1020 A / Cr 4090 F. A pending one posts
 *   nothing.
 * - Cash Out, at creation, dated `transaction_date`: Dr 1020 A / Cr 1010 A−F
 *   / Cr 4090 F.
 * - The branch is the member's; a walk-in's journal has none.
 */
class GCashJournalTest extends TestCase
{
    use PostsJournals, ReadsJournals, SetupLendyPH;

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
        GCashTier::create(['min_amount' => 1501, 'max_amount' => 5000, 'cash_in_rate' => 30.50, 'cash_out_rate' => 25, 'display_order' => 2]);
        GCashTier::create(['min_amount' => 5001, 'max_amount' => 10000, 'cash_in_rate' => 0, 'cash_out_rate' => 0, 'display_order' => 3]);
    }

    private function record(array $fields): GCashTransaction
    {
        $id = $this->postJson('/api/gcash/transactions', $fields + ['borrower_id' => $this->member->id])
            ->assertCreated()->json('data.id');

        return GCashTransaction::findOrFail($id);
    }

    public function test_a_pending_cash_in_posts_nothing(): void
    {
        $this->record(['type' => 'cash_in', 'amount' => 2000, 'is_pending' => true]);

        $this->assertSame(0, $this->gcashJournalCount());
    }

    public function test_marking_a_cash_in_paid_posts_it_on_its_paid_date(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 16:00:00'));
        $tx = $this->record(['type' => 'cash_in', 'amount' => 2000, 'is_pending' => true]);

        $this->travelTo(Carbon::parse('2026-11-05 09:30:00'));
        $this->patchJson("/api/gcash/transactions/{$tx->id}/paid")->assertOk()->assertJsonPath('data.status', 'paid');

        $journal = $this->journalOn($tx, 'cash_in');
        $this->assertNotNull($journal);
        $this->assertSame(['1010 Dr 203050', '1020 Cr 200000', '4090 Cr 3050'], $this->linesOf($journal));
        $this->assertSame('2026-11-05', $journal->date->toDateString());
        $this->assertSame($this->branch->id, $journal->branch_id);
        $this->assertSame($tx->reference_no, $journal->reference);
    }

    public function test_marking_it_paid_twice_posts_once(): void
    {
        $tx = $this->record(['type' => 'cash_in', 'amount' => 1000, 'is_pending' => true]);

        $this->patchJson("/api/gcash/transactions/{$tx->id}/paid")->assertOk();
        $this->patchJson("/api/gcash/transactions/{$tx->id}/paid")
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame(1, $this->gcashJournalCount());
        $this->assertSame(['1010 Dr 102000', '1020 Cr 100000', '4090 Cr 2000'], $this->linesOf($this->journalOn($tx, 'cash_in')));
    }

    /**
     * The model handed to mark-paid still reads pending, as a second request
     * that loaded the row before the first one committed would. The locked
     * re-read refuses it, so nothing posts a second time.
     */
    public function test_a_stale_copy_that_still_reads_pending_posts_nothing_more(): void
    {
        $tx = $this->record(['type' => 'cash_in', 'amount' => 1000, 'is_pending' => true]);
        $stale = GCashTransaction::findOrFail($tx->id);
        $this->patchJson("/api/gcash/transactions/{$tx->id}/paid")->assertOk();

        $this->assertSame('pending', $stale->status);
        $this->expectException(ValidationException::class);

        try {
            app(GCashService::class)->markPaid($stale, $this->admin);
        } finally {
            $this->assertSame(1, $this->gcashJournalCount());
        }
    }

    public function test_a_cash_in_paid_at_the_counter_posts_at_creation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 11:15:00'));
        $tx = $this->record(['type' => 'cash_in', 'amount' => 1000]);

        $journal = $this->journalOn($tx, 'cash_in');
        $this->assertSame(['1010 Dr 102000', '1020 Cr 100000', '4090 Cr 2000'], $this->linesOf($journal));
        $this->assertSame('2026-11-06', $journal->date->toDateString());
    }

    public function test_a_cash_out_posts_at_creation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 14:00:00'));
        $tx = $this->record(['type' => 'cash_out', 'amount' => 3000]);

        $journal = $this->journalOn($tx, 'cash_out');
        $this->assertNotNull($journal);
        $this->assertSame(['1010 Cr 297500', '1020 Dr 300000', '4090 Cr 2500'], $this->linesOf($journal));
        $this->assertSame('2026-11-07', $journal->date->toDateString());
        $this->assertSame($this->branch->id, $journal->branch_id);
    }

    public function test_a_walk_ins_journal_has_no_branch(): void
    {
        $walkIn = GCashNonMember::factory()->create();
        $id = $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $walkIn->id,
            'type' => 'cash_out',
            'amount' => 1000,
        ])->assertCreated()->json('data.id');

        $journal = $this->journalOn(GCashTransaction::findOrFail($id), 'cash_out');
        $this->assertNotNull($journal);
        $this->assertNull($journal->branch_id);
        $this->assertSame(['1010 Cr 98500', '1020 Dr 100000', '4090 Cr 1500'], $this->linesOf($journal));
    }

    public function test_a_free_transaction_has_no_service_income_line(): void
    {
        $tx = $this->record(['type' => 'cash_in', 'amount' => 6000]);

        $this->assertSame(['1010 Dr 600000', '1020 Cr 600000'], $this->linesOf($this->journalOn($tx, 'cash_in')));
    }
}
