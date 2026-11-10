<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\AccountingJournal;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Accounting\JournalPoster;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Browser check (Phase 3, S5): a savings journal reversed from the journal
 * screen would leave the savings records and the books disagreeing, so the
 * journal endpoint answers 422 for it, and the journal resource says why
 * (`reversible`, `not_reversible_reason`) so the screen can hide Reverse:
 *
 * - deposits, withdrawals and interest runs: reverse it from Savings;
 * - time deposit placements and settlements, account-closing payouts and the
 *   interest credited at closing: nothing reverses them, so a correcting
 *   journal entry fixes the cash or bank side.
 *
 * The reversal of a savings entry (source `reversal`) gets its original's
 * reason. Every other source keeps today's behaviour.
 */
class SavingsJournalReversalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const IN_SAVINGS = 'This entry comes from Savings. Reverse it there, so the savings records and the books stay the same.';

    private const NO_REVERSAL = 'This entry comes from a time deposit or an account closing and can\'t be reversed. To fix the cash or bank side, record a correcting journal entry.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> what posted it, its source, the reason */
    public static function savingsEntries(): array
    {
        return [
            'deposit' => ['deposit', 'savings', self::IN_SAVINGS],
            'withdrawal' => ['withdrawal', 'savings', self::IN_SAVINGS],
            'interest run' => ['run', 'savings_interest', self::IN_SAVINGS],
            'account-closing payout' => ['closing', 'savings', self::NO_REVERSAL],
            'interest credited at closing' => ['interest', 'savings_interest', self::NO_REVERSAL],
            'time deposit placement' => ['time_deposit', 'time_deposit', self::NO_REVERSAL],
            'time deposit settlement' => ['time_deposit', 'time_deposit_settlement', self::NO_REVERSAL],
        ];
    }

    /** A posted savings entry keyed on the record that posts it, as AutomaticPoster writes it. */
    private function savingsEntry(string $postedBy, string $source): AccountingJournal
    {
        $postable = match ($postedBy) {
            'run' => SavingsInterestRun::factory()->create(),
            'time_deposit' => TimeDeposit::factory()->create(),
            default => SavingsTransaction::factory()->create(['type' => $postedBy]),
        };

        return $this->postSimpleJournal('1010', '5030', 20_000, [
            'source' => $source,
            'reference' => 'SAVINGS-'.$postedBy,
            'postable_type' => $postable::class,
            'postable_id' => $postable->id,
        ]);
    }

    /** The reversal of a savings entry, as Savings posts it (JournalPoster::reverse()). */
    private function reversalOf(AccountingJournal $entry): AccountingJournal
    {
        return app(JournalPoster::class)->reverse($entry, '2026-09-16', 'Savings reversal', $this->admin->id);
    }

    private function assertRefusedAndUnchanged(AccountingJournal $journal, string $reason): void
    {
        $this->postJson("/api/accounting/journals/{$journal->id}/reverse", ['reason' => 'Wrong amount'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', $reason);

        $journal->refresh();
        $this->assertSame('posted', $journal->status);
        $this->assertNull($journal->reversed_by_journal_id);
        $this->assertSame(0, AccountingJournal::query()->where('reverses_journal_id', $journal->id)->count());

        $this->getJson("/api/accounting/journals/{$journal->id}")->assertOk()
            ->assertJsonPath('data.reversible', false)
            ->assertJsonPath('data.not_reversible_reason', $reason);
    }

    #[DataProvider('savingsEntries')]
    public function test_a_savings_entry_cant_be_reversed_from_the_journal_screen(string $postedBy, string $source, string $reason): void
    {
        $this->assertRefusedAndUnchanged($this->savingsEntry($postedBy, $source), $reason);
    }

    #[DataProvider('savingsEntries')]
    public function test_the_reversal_of_a_savings_entry_gets_its_originals_reason(string $postedBy, string $source, string $reason): void
    {
        $reversal = $this->reversalOf($this->savingsEntry($postedBy, $source));
        $this->assertSame('reversal', $reversal->source);

        $this->assertRefusedAndUnchanged($reversal, $reason);
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

    public function test_the_reversal_of_a_manual_entry_keeps_todays_behaviour(): void
    {
        $entry = $this->postSimpleJournal('5030', '1010', 35_000);
        $reversal = $this->reversalOf($entry);

        $this->getJson("/api/accounting/journals/{$reversal->id}")->assertOk()
            ->assertJsonPath('data.reversible', true)
            ->assertJsonPath('data.not_reversible_reason', null);

        $this->postJson("/api/accounting/journals/{$reversal->id}/reverse", ['reason' => 'Reinstate'])->assertCreated();
    }

    public function test_the_register_flags_each_row_by_what_posted_it(): void
    {
        $deposit = $this->savingsEntry('deposit', 'savings');
        $closing = $this->savingsEntry('closing', 'savings');
        $collection = $this->postSimpleJournal('1010', '4010', 10_000, ['source' => 'loan_collection']);
        $closingReversal = $this->reversalOf($this->savingsEntry('time_deposit', 'time_deposit'));

        $rows = collect($this->getJson('/api/accounting/journals?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertFalse($rows[$deposit->id]['reversible']);
        $this->assertSame(self::IN_SAVINGS, $rows[$deposit->id]['not_reversible_reason']);
        $this->assertFalse($rows[$closing->id]['reversible']);
        $this->assertSame(self::NO_REVERSAL, $rows[$closing->id]['not_reversible_reason']);
        $this->assertSame(self::NO_REVERSAL, $rows[$closingReversal->id]['not_reversible_reason']);
        $this->assertTrue($rows[$collection->id]['reversible']);
        $this->assertNull($rows[$collection->id]['not_reversible_reason']);
    }

    public function test_the_register_flags_savings_rows_without_a_query_per_row(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/accounting/journals?per_page=100')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // One of each kind of postable first: the register reads each kind's
        // labels in one query of its own (AccountingJournal::attachPostables()).
        $this->reversalOf($this->savingsEntry('deposit', 'savings'));
        $this->reversalOf($this->savingsEntry('run', 'savings_interest'));
        $this->reversalOf($this->savingsEntry('time_deposit', 'time_deposit'));
        // The first request also warms the permission cache.
        $count();
        $few = $count();

        $this->reversalOf($this->savingsEntry('closing', 'savings'));
        $this->reversalOf($this->savingsEntry('interest', 'savings_interest'));
        $this->reversalOf($this->savingsEntry('run', 'savings_interest'));
        $this->reversalOf($this->savingsEntry('time_deposit', 'time_deposit_settlement'));
        $this->savingsEntry('withdrawal', 'savings');

        $this->assertSame($few, $count());
    }
}
