<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\AccountingJournal;
use App\Services\Accounting\JournalPoster;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Browser check (Phase 3, S5): a savings journal reversed from the journal
 * screen would leave the savings records and the books disagreeing. Savings
 * journals are reversed only from Savings: the journal endpoint answers 422
 * for them, and the journal resource says so (`reversible`,
 * `not_reversible_reason`) so the screen can hide Reverse. Every other
 * source keeps today's behaviour.
 *
 * The reversal Savings posts of its own entry (source `reversal`) is held the
 * same way: reversing it from Accounting would put the savings entry back in
 * the books without its savings record.
 */
class SavingsJournalReversalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const REFUSAL = 'This entry comes from Savings. Reverse it there, so the savings records and the books stay the same.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return list<array{0: string}> */
    public static function savingsSources(): array
    {
        return [['savings'], ['savings_interest'], ['time_deposit'], ['time_deposit_settlement']];
    }

    #[DataProvider('savingsSources')]
    public function test_a_savings_journal_cant_be_reversed_from_the_journal_screen(string $source): void
    {
        $journal = $this->postSimpleJournal('1010', '5030', 50_000, ['source' => $source, 'reference' => 'SVT-20260915-000001']);

        $this->postJson("/api/accounting/journals/{$journal->id}/reverse", ['reason' => 'Wrong amount'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', self::REFUSAL);

        $journal->refresh();
        $this->assertSame('posted', $journal->status);
        $this->assertNull($journal->reversed_by_journal_id);
        $this->assertSame(0, AccountingJournal::query()->where('reverses_journal_id', $journal->id)->count());

        $this->getJson("/api/accounting/journals/{$journal->id}")->assertOk()
            ->assertJsonPath('data.reversible', false)
            ->assertJsonPath('data.not_reversible_reason', self::REFUSAL);
    }

    public function test_a_manual_journal_still_reverses_as_before(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 35_000);

        $this->getJson("/api/accounting/journals/{$journal->id}")->assertOk()
            ->assertJsonPath('data.reversible', true)
            ->assertJsonPath('data.not_reversible_reason', null);

        $response = $this->postJson("/api/accounting/journals/{$journal->id}/reverse", ['reason' => 'Duplicate'])->assertCreated();

        $this->assertSame('reversal', $response->json('data.source'));
        $this->assertSame('reversed', $journal->refresh()->status);
    }

    public function test_the_register_flags_each_row_by_its_source(): void
    {
        $savings = $this->postSimpleJournal('1010', '5030', 10_000, ['source' => 'savings']);
        $collection = $this->postSimpleJournal('1010', '4010', 10_000, ['source' => 'loan_collection']);

        $rows = collect($this->getJson('/api/accounting/journals?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertFalse($rows[$savings->id]['reversible']);
        $this->assertSame(self::REFUSAL, $rows[$savings->id]['not_reversible_reason']);
        $this->assertTrue($rows[$collection->id]['reversible']);
        $this->assertNull($rows[$collection->id]['not_reversible_reason']);
    }

    /** A savings entry and the reversal Savings posts of it, as Reverser and RunReverser do. */
    private function savingsEntryReversedBySavings(string $source = 'savings'): AccountingJournal
    {
        $entry = $this->postSimpleJournal('1010', '5030', 20_000, ['source' => $source]);

        return app(JournalPoster::class)->reverse($entry, '2026-09-16', 'Savings reversal', $this->admin->id);
    }

    #[DataProvider('savingsSources')]
    public function test_the_reversal_of_a_savings_entry_cant_be_reversed_from_the_journal_screen(string $source): void
    {
        $reversal = $this->savingsEntryReversedBySavings($source);
        $this->assertSame('reversal', $reversal->source);

        $this->postJson("/api/accounting/journals/{$reversal->id}/reverse", ['reason' => 'Undo the undo'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', self::REFUSAL);

        $reversal->refresh();
        $this->assertSame('posted', $reversal->status);
        $this->assertNull($reversal->reversed_by_journal_id);
        $this->assertSame(0, AccountingJournal::query()->where('reverses_journal_id', $reversal->id)->count());

        $this->getJson("/api/accounting/journals/{$reversal->id}")->assertOk()
            ->assertJsonPath('data.reversible', false)
            ->assertJsonPath('data.not_reversible_reason', self::REFUSAL);
    }

    public function test_the_reversal_of_a_manual_entry_keeps_todays_behaviour(): void
    {
        $entry = $this->postSimpleJournal('5030', '1010', 35_000);
        $reversal = app(JournalPoster::class)->reverse($entry, '2026-09-16', 'Duplicate', $this->admin->id);

        $this->getJson("/api/accounting/journals/{$reversal->id}")->assertOk()
            ->assertJsonPath('data.reversible', true)
            ->assertJsonPath('data.not_reversible_reason', null);

        $this->postJson("/api/accounting/journals/{$reversal->id}/reverse", ['reason' => 'Reinstate'])->assertCreated();
    }

    public function test_the_register_flags_reversals_without_a_query_per_row(): void
    {
        $count = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = collect($this->getJson('/api/accounting/journals?per_page=100')->assertOk()->json('data'))->keyBy('id');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$rows, $queries];
        };

        $first = $this->savingsEntryReversedBySavings();
        // The first request also warms the permission cache.
        $count();
        [$rows, $oneReversal] = $count();
        $this->assertFalse($rows[$first->id]['reversible']);
        $this->assertSame(self::REFUSAL, $rows[$first->id]['not_reversible_reason']);

        $this->savingsEntryReversedBySavings();
        $this->savingsEntryReversedBySavings('time_deposit');
        [, $threeReversals] = $count();

        $this->assertSame($oneReversal, $threeReversals);
    }
}
