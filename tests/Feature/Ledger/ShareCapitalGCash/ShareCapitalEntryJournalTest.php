<?php

namespace Tests\Feature\Ledger\ShareCapitalGCash;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Services\GeneratedNumberWrite;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A standalone share capital entry in the books (Phase 2, decision 5).
 *
 * Single entries (`POST /pledges/{pledge}/entries`, `POST /share-capital/ledger`)
 * and bulk entries (`POST /pledges/bulk-entries`) take how the money moved,
 * `method` and `reference_number` (required unless cash), store both on the
 * row, and post in the row's own transaction: a credit is Dr the method's
 * account / Cr 3060 Share Capital, a debit the reverse. A bulk batch posts one
 * journal per row, and a batch that fails leaves no row and no journal.
 */
class ShareCapitalEntryJournalTest extends TestCase
{
    use PostsJournals, ReadsJournals, SetupLendyPH;

    private Borrower $member;

    private ShareCapitalPledge $pledge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->pledge = $this->member->shareCapitalPledge;
    }

    private function entryOnPledge(array $fields): ShareCapitalLedger
    {
        $id = $this->postJson("/api/pledges/{$this->pledge->id}/entries", $fields + [
            'date' => '2026-11-04',
        ])->assertCreated()->json('data.id');

        return ShareCapitalLedger::findOrFail($id);
    }

    public function test_a_gcash_credit_with_a_reference_posts_to_the_wallet_and_share_capital_and_stores_both(): void
    {
        $row = $this->entryOnPledge(['amount' => 1500, 'type' => 'credit', 'method' => 'gcash', 'reference_number' => 'GC-778899']);

        $this->assertSame('gcash', $row->method);
        $this->assertSame('GC-778899', $row->reference_number);

        $journal = $this->journalOn($row, 'share_capital');
        $this->assertNotNull($journal);
        $this->assertSame(['1020 Dr 150000', '3060 Cr 150000'], $this->linesOf($journal));
        $this->assertSame('2026-11-04', $journal->date->toDateString());
        $this->assertSame($this->branch->id, $journal->branch_id);
        $this->assertSame($row->reference, $journal->reference);
        $this->assertSame('posted', $journal->status);
    }

    public function test_the_entry_answers_with_its_method_and_reference(): void
    {
        $this->postJson("/api/pledges/{$this->pledge->id}/entries", [
            'amount' => 1500, 'type' => 'credit', 'date' => '2026-11-04', 'method' => 'gcash', 'reference_number' => 'GC-778899',
        ])->assertCreated()
            ->assertJsonPath('data.method', 'gcash')
            ->assertJsonPath('data.reference_number', 'GC-778899');
    }

    public function test_a_debit_reverses_it(): void
    {
        $row = $this->entryOnPledge(['amount' => 400.50, 'type' => 'debit', 'method' => 'gcash', 'reference_number' => 'GC-1']);

        $this->assertSame(['1020 Cr 40050', '3060 Dr 40050'], $this->linesOf($this->journalOn($row, 'share_capital')));
    }

    public function test_cash_is_the_default_and_needs_no_reference(): void
    {
        $row = $this->entryOnPledge(['amount' => 250, 'type' => 'credit']);

        $this->assertSame('cash', $row->method);
        $this->assertNull($row->reference_number);
        $this->assertSame(['1010 Dr 25000', '3060 Cr 25000'], $this->linesOf($this->journalOn($row, 'share_capital')));
    }

    public function test_a_method_other_than_cash_without_a_reference_is_refused_and_saves_nothing(): void
    {
        foreach (['gcash', 'maya', 'bank_transfer', 'online'] as $method) {
            $this->postJson("/api/pledges/{$this->pledge->id}/entries", [
                'amount' => 250, 'type' => 'credit', 'date' => '2026-11-04', 'method' => $method,
            ])->assertUnprocessable()->assertJsonValidationErrors('reference_number');
        }

        $this->assertSame(0, ShareCapitalLedger::count());
        $this->assertSame(0, $this->shareCapitalJournalCount());
    }

    public function test_an_unknown_method_or_a_long_reference_is_refused(): void
    {
        $this->postJson("/api/pledges/{$this->pledge->id}/entries", [
            'amount' => 250, 'type' => 'credit', 'date' => '2026-11-04', 'method' => 'auto_pay', 'reference_number' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('method');

        $this->postJson("/api/pledges/{$this->pledge->id}/entries", [
            'amount' => 250, 'type' => 'credit', 'date' => '2026-11-04', 'method' => 'gcash', 'reference_number' => str_repeat('9', 101),
        ])->assertUnprocessable()->assertJsonValidationErrors('reference_number');

        $this->assertSame(0, ShareCapitalLedger::count());
    }

    public function test_the_ledger_endpoint_posts_the_same_way(): void
    {
        $id = $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id,
            'date' => '2026-11-05',
            'description' => 'Contribution by Maya',
            'type' => 'credit',
            'amount' => 1000,
            'method' => 'maya',
            'reference_number' => 'MY-123',
        ])->assertCreated()
            ->assertJsonPath('data.method', 'maya')
            ->assertJsonPath('data.reference_number', 'MY-123')
            ->json('data.id');

        $row = ShareCapitalLedger::findOrFail($id);
        $this->assertSame('Contribution by Maya', $row->description);
        $journal = $this->journalOn($row, 'share_capital');
        $this->assertSame(['1030 Dr 100000', '3060 Cr 100000'], $this->linesOf($journal));
        $this->assertSame('2026-11-05', $journal->date->toDateString());
        $this->assertSame($this->branch->id, $journal->branch_id);
    }

    public function test_the_ledger_endpoint_defaults_to_cash_and_refuses_a_missing_reference(): void
    {
        $base = ['borrower_id' => $this->member->id, 'date' => '2026-11-05', 'description' => 'Withdrawal', 'type' => 'debit', 'amount' => 300];

        $this->postJson('/api/share-capital/ledger', $base + ['method' => 'bank_transfer'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference_number');
        $this->assertSame(0, ShareCapitalLedger::count());

        $id = $this->postJson('/api/share-capital/ledger', $base)->assertCreated()->json('data.id');
        $row = ShareCapitalLedger::findOrFail($id);
        $this->assertSame('cash', $row->method);
        $this->assertSame(['1010 Cr 30000', '3060 Dr 30000'], $this->linesOf($this->journalOn($row, 'share_capital')));
    }

    // ── Bulk ──

    /** @return list<ShareCapitalPledge> */
    private function threePledges(): array
    {
        return [
            $this->pledge,
            Borrower::factory()->create(['branch_id' => $this->branch->id])->shareCapitalPledge,
            Borrower::factory()->create(['branch_id' => $this->branch->id])->shareCapitalPledge,
        ];
    }

    private function bulk(array $pledges, array $batch): array
    {
        return $batch + ['entries' => array_map(fn (ShareCapitalPledge $pledge, int $i): array => [
            'pledge_id' => $pledge->id,
            'amount' => 100 * ($i + 1),
            'type' => $i === 2 ? 'debit' : 'credit',
            'date' => '2026-11-06',
        ], $pledges, array_keys($pledges))];
    }

    public function test_a_bulk_batch_of_three_posts_three_journals_through_the_batch_method(): void
    {
        $this->postJson('/api/pledges/bulk-entries', $this->bulk($this->threePledges(), [
            'method' => 'bank_transfer',
            'reference_number' => 'BDO-55',
        ]))->assertCreated()->assertJsonPath('count', 3);

        $rows = ShareCapitalLedger::orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['bank_transfer'], $rows->pluck('method')->unique()->values()->all());
        $this->assertSame(['BDO-55'], $rows->pluck('reference_number')->unique()->values()->all());
        $this->assertCount(3, $this->journalsFrom('share_capital'));

        $this->assertSame(['1040 Dr 10000', '3060 Cr 10000'], $this->linesOf($this->journalOn($rows[0], 'share_capital')));
        $this->assertSame(['1040 Dr 20000', '3060 Cr 20000'], $this->linesOf($this->journalOn($rows[1], 'share_capital')));
        $this->assertSame(['1040 Cr 30000', '3060 Dr 30000'], $this->linesOf($this->journalOn($rows[2], 'share_capital')));
    }

    public function test_a_bulk_batch_defaults_to_cash_and_refuses_a_missing_reference(): void
    {
        $this->postJson('/api/pledges/bulk-entries', $this->bulk($this->threePledges(), ['method' => 'gcash']))
            ->assertUnprocessable()->assertJsonValidationErrors('reference_number');
        $this->assertSame(0, ShareCapitalLedger::count());

        $this->postJson('/api/pledges/bulk-entries', $this->bulk($this->threePledges(), []))->assertCreated();
        $this->assertSame(['cash'], ShareCapitalLedger::pluck('method')->unique()->values()->all());
        $this->assertSame(3, $this->journalsFrom('share_capital')->filter(
            fn ($journal) => $journal->lines()->where('accounting_account_id', $this->account('1010'))->exists()
        )->count());
    }

    /**
     * The third row takes a reference another row already holds, so the
     * batch answers 409 at once. The two rows before it and their journals
     * are rolled back with it: nothing is left half posted, and nothing is
     * tried again.
     */
    public function test_a_bulk_batch_that_fails_on_its_third_row_leaves_no_row_and_no_journal(): void
    {
        // Seen highest-id first, SC-20261106-000001 makes the next number 2,
        // then 3, then 4, which the older row already holds.
        $older = ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'date' => '2026-11-06', 'reference' => 'SC-20261106-000004']);
        $newer = ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'date' => '2026-11-06', 'reference' => 'SC-20261106-000001']);

        $this->postJson('/api/pledges/bulk-entries', $this->bulk($this->threePledges(), [
            'method' => 'gcash',
            'reference_number' => 'GC-BATCH',
        ]))->assertStatus(409)->assertExactJson(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT]);

        $this->assertSame([$older->id, $newer->id], ShareCapitalLedger::orderBy('id')->pluck('id')->all());
        $this->assertSame(0, $this->shareCapitalJournalCount());
    }
}
