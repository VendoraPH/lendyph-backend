<?php

namespace Tests\Feature\FzReports;

use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A draft journal's totals come from its lines, so a draft never reads ₱0.00.
 * The stored totals are only written when it posts (round fz).
 */
class DraftJournalTotalsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_draft_reads_its_lines_on_every_endpoint(): void
    {
        $id = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Electricity',
            'branch_id' => $this->branch->id,
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 350000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 350000],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_debit', 350000)
            ->assertJsonPath('data.total_credit', 350000)
            ->json('data.id');

        $this->getJson("/api/accounting/journals/{$id}")->assertOk()
            ->assertJsonPath('data.total_debit', 350000)
            ->assertJsonPath('data.total_credit', 350000);

        $row = collect($this->getJson('/api/accounting/journals?status=draft')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame(350000, $row['total_debit']);
        $this->assertSame(350000, $row['total_credit']);
    }

    public function test_an_edited_draft_reads_its_new_lines(): void
    {
        $id = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Electricity',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
            ],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/accounting/journals/{$id}", [
            'date' => '2026-09-15',
            'description' => 'Electricity',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 900, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 900],
            ],
        ])->assertOk()
            ->assertJsonPath('data.total_debit', 900)
            ->assertJsonPath('data.total_credit', 900);
    }

    public function test_a_posted_journal_still_reads_its_stored_totals(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 120000);

        $this->getJson("/api/accounting/journals/{$journal->id}")->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.total_debit', 120000)
            ->assertJsonPath('data.total_credit', 120000);
    }
}
