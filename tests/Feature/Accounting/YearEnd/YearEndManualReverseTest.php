<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingJournal;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * POST /accounting/journals/{id}/reverse (the journal screen's manual
 * reverse) refuses a `closing` journal and the reversal of one. A closing
 * entry is undone only by reopening its year, which reverses every entry of
 * the close on 31 December and records why; a manual reverse could date the
 * mirror into another year and leave the close reading "closed".
 */
class YearEndManualReverseTest extends TestCase
{
    use PostsJournals, SetupLendyPH, YearEndFixtures;

    private const MESSAGE = 'This entry is part of the 2025 year-end close. Reopen the year instead.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYearEnd();
        $this->postTheYear();
    }

    public function test_a_closing_journal_cannot_be_reversed_by_hand(): void
    {
        $this->closed();
        $closing = AccountingJournal::query()->where('source', 'closing')->firstOrFail();

        $this->postJson("/api/accounting/journals/{$closing->id}/reverse", ['date' => '2026-11-09', 'reason' => 'Undo'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', self::MESSAGE);

        $this->assertSame('posted', $closing->fresh()->status);
        $this->assertSame(0, AccountingJournal::query()->where('reverses_journal_id', $closing->id)->count());
    }

    public function test_the_reversal_of_a_closing_journal_cannot_be_reversed_by_hand(): void
    {
        $this->reopenYear($this->closed()['id'])->assertOk();
        $reversal = AccountingJournal::query()
            ->whereIn('reverses_journal_id', AccountingJournal::query()->where('source', 'closing')->select('id'))
            ->firstOrFail();

        $this->postJson("/api/accounting/journals/{$reversal->id}/reverse", ['date' => '2026-11-09'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', self::MESSAGE);

        $this->assertSame('posted', $reversal->fresh()->status);
    }

    public function test_an_ordinary_journal_and_its_reversal_still_reverse(): void
    {
        $journal = $this->postSimpleJournal('5020', '1010', 50000, ['date' => '2026-02-01']);

        $reversal = $this->postJson("/api/accounting/journals/{$journal->id}/reverse", ['date' => '2026-02-02'])
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/accounting/journals/{$reversal['id']}/reverse", ['date' => '2026-02-03'])->assertCreated();
    }

    public function test_reversing_still_needs_journals_reverse_first(): void
    {
        $this->closed();
        $closing = AccountingJournal::query()->where('source', 'closing')->where('branch_id', $this->branch->id)->firstOrFail();

        $this->actingAs($this->userWithNoRole());

        $this->postJson("/api/accounting/journals/{$closing->id}/reverse")->assertForbidden();
    }
}
