<?php

namespace Tests\Feature\Accounting\Journals;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A journal response names the entry a reversal undoes (`reverses_journal_no`),
 * so the reversal page needs no second request for it. Null on every entry
 * that isn't a reversal.
 */
class JournalReversedEntryNumberTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_reversal_names_the_entry_it_reverses_on_reverse_show_and_index(): void
    {
        $original = $this->postSimpleJournal('5030', '1010', 350_000);

        $reversalId = $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['reason' => 'Keyed twice'])
            ->assertCreated()
            ->assertJsonPath('data.reverses_journal_id', $original->id)
            ->assertJsonPath('data.reverses_journal_no', $original->journal_no)
            ->json('data.id');

        $this->getJson("/api/accounting/journals/{$reversalId}")
            ->assertOk()
            ->assertJsonPath('data.reverses_journal_no', $original->journal_no);

        $this->getJson("/api/accounting/journals/{$original->id}")
            ->assertOk()
            ->assertJsonPath('data.reverses_journal_no', null);

        $rows = collect($this->getJson('/api/accounting/journals')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame($original->journal_no, $rows[$reversalId]['reverses_journal_no']);
        $this->assertArrayHasKey('reverses_journal_no', $rows[$original->id]);
        $this->assertNull($rows[$original->id]['reverses_journal_no']);
    }

    public function test_a_draft_reads_null(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        $this->getJson("/api/accounting/journals/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.reverses_journal_no', null);
    }

    public function test_the_register_reads_every_reversed_number_in_one_query(): void
    {
        $registerQueries = function (int $reversals): array {
            for ($i = 0; $i < $reversals; $i++) {
                $original = $this->postSimpleJournal('5030', '1010', 1000 + $i);
                $this->postJson("/api/accounting/journals/{$original->id}/reverse")->assertCreated();
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->getJson('/api/accounting/journals?per_page=100')->assertOk();

            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return $queries;
        };

        // Warm the permission and user caches.
        $this->getJson('/api/accounting/journals')->assertOk();

        $one = $registerQueries(1);
        $six = $registerQueries(5);

        $this->assertCount(count($one), $six, 'Six reversals on a page cost more queries than one.');
    }
}
