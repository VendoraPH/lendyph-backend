<?php

namespace Tests\Feature\Ledger\ShareCapitalGCash;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Services\Accounting\JournalPoster;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A GCash Cash In or Cash Out journal can't be reversed from the journal
 * screen (finish run round 2, from the #368 money review). The GCash income
 * report counts the transaction's charge, so reversing its journal alone
 * would take the charge off 4090 GCash Service Income while the report still
 * counts it, and GCash has no reversal of its own. The endpoint answers 422
 * in words, and the journal reads `reversible: false` with the reason, as a
 * Savings entry does (SavingsJournalReversalTest).
 */
class GCashJournalReversalTest extends TestCase
{
    use PostsJournals, ReadsJournals, SetupLendyPH;

    private const REASON = 'This entry comes from a GCash Cash In or Cash Out and can\'t be reversed, so the GCash records and the books stay the same. To fix it, record a correcting journal entry.';

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    /** @return array<string, array{string}> */
    public static function gcashTypes(): array
    {
        return ['Cash In' => ['cash_in'], 'Cash Out' => ['cash_out']];
    }

    #[DataProvider('gcashTypes')]
    public function test_a_gcash_entry_cant_be_reversed_from_the_journal_screen(string $type): void
    {
        $journal = $this->gcashEntry($type);

        $this->postJson("/api/accounting/journals/{$journal->id}/reverse", ['reason' => 'Wrong charge'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', self::REASON);

        $journal->refresh();
        $this->assertSame('posted', $journal->status);
        $this->assertNull($journal->reversed_by_journal_id);
        $this->assertSame(0, AccountingJournal::query()->where('reverses_journal_id', $journal->id)->count());

        $this->getJson("/api/accounting/journals/{$journal->id}")->assertOk()
            ->assertJsonPath('data.reversible', false)
            ->assertJsonPath('data.not_reversible_reason', self::REASON);
    }

    /**
     * Only a hand reversal from before this guard can exist, since GCash
     * reverses nothing; reversing it puts the entry back in step with GCash.
     */
    public function test_a_hand_reversal_of_a_gcash_entry_can_still_be_undone(): void
    {
        $reversal = app(JournalPoster::class)->reverse($this->gcashEntry('cash_in'), now()->toDateString(), 'Reversed by hand', $this->admin->id);

        $this->getJson("/api/accounting/journals/{$reversal->id}")->assertOk()
            ->assertJsonPath('data.reversible', true)
            ->assertJsonPath('data.not_reversible_reason', null);

        $this->postJson("/api/accounting/journals/{$reversal->id}/reverse", ['reason' => 'Back in step with GCash'])->assertCreated();
        $this->assertSame('reversed', $reversal->refresh()->status);
    }

    private function gcashEntry(string $type): AccountingJournal
    {
        $id = $this->postJson('/api/gcash/transactions', ['type' => $type, 'amount' => 2000, 'borrower_id' => $this->member->id])
            ->assertCreated()
            ->json('data.id');

        $journal = $this->journalOn(GCashTransaction::findOrFail($id), $type);
        $this->assertNotNull($journal);

        return $journal;
    }
}
