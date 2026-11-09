<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\ReconciliationMatcher;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Follow-up round 2, slice f2c-be: the 92-day period cap and a bounded
 * duplicate check, a transfer recorded from both sides, same-amount matches,
 * outstanding-count netting on the row's own journal, the 180-day window, the
 * statement line's own figures on paired rows, and netted rows. Amounts are
 * integer centavos, signed, positive for money in.
 */
class ReconciliationWindowAndMatchingTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function journalLineOn(AccountingJournal $journal, string $accountCode = '1040'): int
    {
        return (int) AccountingJournalLine::query()
            ->where('accounting_journal_id', $journal->id)
            ->where('accounting_account_id', $this->account($accountCode))
            ->value('id');
    }

    private function deposit(int $centavos, string $date, ?string $reference = null): AccountingJournal
    {
        return $this->postSimpleJournal('1040', '3010', $centavos, [
            'date' => $date,
            'reference' => $reference,
            'description' => 'Capital deposit',
        ]);
    }

    private function reverse(AccountingJournal $journal, string $date): AccountingJournal
    {
        return app(JournalPoster::class)->reverse($journal, $date, 'Reversed in a test', $this->admin->id);
    }

    /** @return array<string, mixed> */
    private function show(AccountingReconciliation $reconciliation): array
    {
        return $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()->json('data');
    }

    private function matchUrl(AccountingReconciliation $reconciliation): string
    {
        return "/api/accounting/reconciliations/{$reconciliation->id}/match";
    }

    // ── 1. The period cap and a bounded duplicate check ──

    public function test_a_period_longer_than_92_days_is_refused_in_words(): void
    {
        $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'start_date' => '2026-06-30',
            'end_date' => '2026-09-30',
            'statement_balance' => 0,
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.end_date.0',
                'A reconciliation covers at most 92 days, and 2026-06-30 to 2026-09-30 is 93 days. Reconcile it a '
                .'month or a quarter at a time.',
            );

        $this->assertSame(0, AccountingReconciliation::query()->count());
    }

    public function test_a_quarter_of_92_days_is_accepted(): void
    {
        $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'start_date' => '2026-07-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 0,
        ])->assertCreated();
    }

    public function test_the_duplicate_check_reads_no_balance(): void
    {
        $this->deposit(75050, '2026-08-30');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Unidentified', 'amount' => 12300],
        ]);

        // The whole worksheet is built once, for the action's answer. The
        // duplicate check reads only the lines it pairs.
        $matcher = new class(app(TrialBalanceBuilder::class)) extends ReconciliationMatcher
        {
            public int $presented = 0;

            public function present(AccountingReconciliation $reconciliation): array
            {
                $this->presented++;

                return parent::present($reconciliation);
            }
        };
        $this->app->instance(ReconciliationMatcher::class, $matcher);

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))->assertCreated();

        $this->assertSame(1, $matcher->presented);
    }

    // ── 2. A transfer recorded from both sides ──

    public function test_a_transfer_locks_both_money_accounts_in_id_order_before_the_duplicate_check(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Cash deposit', 'amount' => 200000],
        ]);
        $line = $this->statementLine($reconciliation);
        $ids = [$this->account('1040'), $this->account('1010')];
        sort($ids);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->act('transfer', $reconciliation, $line, ['counter_account_id' => $this->account('1010')])->assertCreated();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $lineLock = $this->firstQuery($log, static fn (string $q): bool => str_contains($q, 'from `accounting_reconciliation_lines`')
            && str_ends_with($q, 'for update'));
        $accountLock = $this->firstQuery($log, static fn (string $q): bool => str_contains($q, 'from `accounting_accounts`')
            && str_ends_with($q, 'for update'));
        $ledgerRead = $this->firstQuery($log, static fn (string $q): bool => str_contains($q, 'from `accounting_journal_lines` as `l`'));

        $this->assertNotNull($lineLock, 'The statement line was not locked.');
        $this->assertNotNull($accountLock, 'The money accounts were not locked.');
        $this->assertNotNull($ledgerRead, 'The duplicate check read no ledger lines.');
        $this->assertLessThan($accountLock, $lineLock, 'The statement line is locked first.');
        $this->assertLessThan($ledgerRead, $accountLock, 'Both accounts are locked before the duplicate check reads the books.');

        // One read, both accounts, locked in id order. Integer keys are
        // written into the SQL rather than bound.
        $this->assertStringContainsString('order by `id` asc', $log[$accountLock]['query']);
        $this->assertSame(1, preg_match('/in \(([\d, ]+)\)/', $log[$accountLock]['query'], $in));
        $locked = array_map('intval', explode(',', $in[1]));
        sort($locked);
        $this->assertSame($ids, $locked);
    }

    public function test_the_other_side_of_a_recorded_transfer_is_caught_as_already_in_the_books(): void
    {
        $bank = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Cash deposited', 'amount' => 200000],
        ]);
        $journalId = $this->act('transfer', $bank, $this->statementLine($bank), [
            'counter_account_id' => $this->account('1010'),
        ])->assertCreated()->json('created.journal_id');
        $journalNo = AccountingJournal::query()->findOrFail($journalId)->journal_no;

        $cash = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Cash taken to the bank', 'amount' => -200000],
        ], '1010');
        $before = $this->journalCount();

        $this->act('transfer', $cash, $this->statementLine($cash), ['counter_account_id' => $this->account('1040')])
            ->assertStatus(422)
            ->assertJsonPath('errors.line.0', "This statement line matches {$journalNo} already in the books. Match it instead, or record it anyway.");

        $this->assertSame($before, $this->journalCount());
    }

    // ── 3. Same-amount matches ──

    public function test_a_manual_match_of_a_different_amount_and_side_is_refused_in_words(): void
    {
        $deposit = $this->deposit(200000, '2026-09-10');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-11', 'description' => 'Bank charge', 'amount' => -8800],
        ]);
        $line = $this->statementLine($reconciliation);

        $this->postJson($this->matchUrl($reconciliation), ['matches' => [
            ['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($deposit)],
        ]])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.matches.0',
                'This statement line is ₱88.00 out; the entry is ₱2,000.00 in. Only an entry for the same amount, '
                .'in the same direction, can be matched to it.',
            );

        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    public function test_a_manual_match_of_the_same_side_but_another_amount_is_refused(): void
    {
        $deposit = $this->deposit(200000, '2026-09-10');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-11', 'description' => 'Deposit', 'amount' => 199999],
        ]);

        $this->postJson($this->matchUrl($reconciliation), ['matches' => [
            ['line_id' => $this->statementLine($reconciliation)->id, 'journal_line_id' => $this->journalLineOn($deposit)],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors.matches.0', fn (string $m): bool => str_starts_with(
                $m,
                'This statement line is ₱1,999.99 in; the entry is ₱2,000.00 in.',
            ));
    }

    public function test_the_same_amount_and_side_is_matched_by_hand(): void
    {
        $deposit = $this->deposit(200000, '2026-09-01');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-20', 'description' => 'Deposit', 'amount' => 200000],
        ]);

        $shown = $this->postJson($this->matchUrl($reconciliation), ['matches' => [
            ['line_id' => $this->statementLine($reconciliation)->id, 'journal_line_id' => $this->journalLineOn($deposit)],
        ]])->assertOk()->json('data');

        $this->assertSame(1, $shown['matched_count']);
    }

    public function test_a_reference_with_another_amount_is_not_suggested_but_is_still_caught_as_a_duplicate(): void
    {
        $existing = $this->postSimpleJournal('5070', '1040', 20000, [
            'date' => '2026-09-28',
            'reference' => 'CHK-1007',
            'description' => 'Cheque 1007',
        ]);

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-29', 'description' => 'Cheque 1007', 'amount' => -21000, 'external_reference' => 'chk-1007'],
        ]);

        // A suggestion is always one match() takes, so accepting the
        // suggestions never fails on one.
        $shown = $this->show($reconciliation);
        $this->assertSame(0, $shown['possible_count']);
        $this->postJson($this->matchUrl($reconciliation), ['accept_suggestions' => true])->assertOk();

        // Booking the cheque from the statement would still record it twice.
        $this->act('expense', $reconciliation, $this->statementLine($reconciliation), [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'Supplier',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.line.0',
                "This statement line has the same reference as {$existing->journal_no} already in the books, which is "
                .'₱200.00 out against the statement\'s ₱210.00 out. Correct that entry rather than booking the money '
                .'twice, or record it anyway.',
            );
    }

    public function test_a_paired_row_is_matchable_only_for_the_same_amount_and_every_suggestion_is_matchable(): void
    {
        $deposit = $this->deposit(200000, '2026-09-10', 'DEP-1');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-11', 'description' => 'Deposit', 'amount' => 200000, 'external_reference' => 'DEP-1'],
        ]);

        $row = collect($this->show($reconciliation)['lines'])->firstWhere('source', 'both');
        $this->assertSame('possible', $row['match']);
        $this->assertSame($this->journalLineOn($deposit), $row['journal_line_id']);
        $this->assertTrue($row['matchable']);
    }

    // ── 4. Outstanding-count netting reads the row's own journal ──

    public function test_a_reversed_journal_whose_other_line_is_matched_here_is_outstanding(): void
    {
        $journal = $this->postJournal([
            ['account_id' => $this->account('1040'), 'debit' => 300000, 'credit' => 0],
            ['account_id' => $this->account('1040'), 'debit' => 200000, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 500000],
        ], ['date' => '2026-08-20']);
        $this->reverse($journal, '2026-08-28');
        $first = (int) AccountingJournalLine::query()
            ->where('accounting_journal_id', $journal->id)
            ->where('accounting_account_id', $this->account('1040'))
            ->orderBy('id')->value('id');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-20', 'description' => 'Deposit', 'amount' => 300000],
        ]);
        $this->postJson($this->matchUrl($reconciliation), ['matches' => [
            ['line_id' => $this->statementLine($reconciliation)->id, 'journal_line_id' => $first],
        ]])->assertOk();

        // The journal's other line and both lines of its reversal are
        // outstanding: the journal is matched, so the pair does not net away.
        $shown = $this->show($reconciliation);
        $this->assertSame(3, $shown['outstanding_count']);
        $this->assertSame([false, false, false], collect($shown['lines'])->where('source', 'ledger')->pluck('netted')->values()->all());
    }

    public function test_a_reversed_journal_whose_other_line_another_reconciliation_matched_is_outstanding(): void
    {
        $journal = $this->postJournal([
            ['account_id' => $this->account('1040'), 'debit' => 300000, 'credit' => 0],
            ['account_id' => $this->account('1040'), 'debit' => 200000, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 500000],
        ], ['date' => '2026-08-20']);
        $this->reverse($journal, '2026-08-28');
        $first = (int) AccountingJournalLine::query()
            ->where('accounting_journal_id', $journal->id)
            ->where('accounting_account_id', $this->account('1040'))
            ->orderBy('id')->value('id');

        $august = AccountingReconciliation::query()->findOrFail(
            $this->postJson('/api/accounting/reconciliations', [
                'account_id' => $this->account('1040'),
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'statement_balance' => 0,
                'lines' => [['date' => '2026-08-21', 'description' => 'Deposit', 'amount' => 300000]],
            ])->assertCreated()->json('data.id'),
        );
        $this->postJson($this->matchUrl($august), ['matches' => [
            ['line_id' => $this->statementLine($august)->id, 'journal_line_id' => $first],
        ]])->assertOk();

        $september = $this->reconciliationWith([
            ['date' => '2026-09-20', 'description' => 'Something else', 'amount' => 12300],
        ]);

        $this->assertSame(3, $this->show($september)['outstanding_count']);
    }

    // ── 6. Outstanding items reach back 180 days ──

    public function test_the_worksheet_reaches_back_180_days_before_the_period(): void
    {
        $this->deposit(10000, '2026-03-04'); // 181 days before Sep 1: too old
        $inTransit = $this->deposit(20000, '2026-03-05'); // 180 days before

        $shown = $this->show($this->reconciliationWith([]));

        $ledger = collect($shown['lines'])->where('source', 'ledger')->values();
        $this->assertSame([$this->journalLineOn($inTransit)], $ledger->pluck('journal_line_id')->all());
        $this->assertSame(1, $shown['outstanding_count']);
    }

    public function test_a_cheque_clearing_after_180_days_is_matched_and_an_older_one_is_refused_in_words(): void
    {
        $inTransit = $this->deposit(20000, '2026-03-05');
        $tooOld = $this->deposit(20000, '2026-03-04');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-01', 'description' => 'Deposit', 'amount' => 20000],
        ]);
        $line = $this->statementLine($reconciliation);

        $this->postJson($this->matchUrl($reconciliation), ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($tooOld)]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.matches.0', fn (string $m): bool => str_contains($m, 'up to 180 days before the period'));

        $this->postJson($this->matchUrl($reconciliation), ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($inTransit)]]])
            ->assertOk();
    }

    // ── 7. Paired rows carry the statement line's own figures ──

    public function test_paired_rows_carry_the_statement_lines_own_date_and_amount(): void
    {
        $suggested = $this->deposit(75050, '2026-08-30');
        $matched = $this->deposit(20000, '2026-09-05');
        $this->deposit(30000, '2026-09-15');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'Deposit', 'amount' => 75050],
            ['date' => '2026-09-09', 'description' => 'Deposit', 'amount' => 20000],
            ['date' => '2026-09-25', 'description' => 'Bank charge', 'amount' => -5000],
        ]);
        $this->postJson($this->matchUrl($reconciliation), ['matches' => [
            ['line_id' => $this->statementLine($reconciliation, 1)->id, 'journal_line_id' => $this->journalLineOn($matched)],
        ]])->assertOk();

        $rows = collect($this->show($reconciliation)['lines']);

        $possible = $rows->firstWhere('match', 'possible');
        $this->assertSame($this->journalLineOn($suggested), $possible['journal_line_id']);
        $this->assertSame('2026-08-30', $possible['date']);
        $this->assertSame('2026-09-02', $possible['statement_date']);
        $this->assertSame(75050, $possible['statement_amount']);

        $confirmed = $rows->firstWhere('match', 'matched');
        $this->assertSame('2026-09-05', $confirmed['date']);
        $this->assertSame('2026-09-09', $confirmed['statement_date']);
        $this->assertSame(20000, $confirmed['statement_amount']);

        $statementOnly = $rows->firstWhere('source', 'statement');
        $this->assertSame('2026-09-25', $statementOnly['statement_date']);
        $this->assertSame(-5000, $statementOnly['statement_amount']);

        $booksOnly = $rows->firstWhere('source', 'ledger');
        $this->assertNull($booksOnly['statement_date']);
        $this->assertNull($booksOnly['statement_amount']);
    }

    // ── 8. Netted rows ──

    public function test_outstanding_rows_left_out_of_the_count_are_marked_netted(): void
    {
        $original = $this->deposit(75050, '2026-08-20');
        $reversal = $this->reverse($original, '2026-08-25');
        $inTransit = $this->deposit(20000, '2026-08-27');
        $inPeriod = $this->deposit(30000, '2026-09-15');

        $shown = $this->show($this->reconciliationWith([
            ['date' => '2026-09-20', 'description' => 'Something else', 'amount' => 12300],
        ]));

        $netted = collect($shown['lines'])->where('source', 'ledger')->mapWithKeys(
            static fn (array $row): array => [$row['journal_line_id'] => $row['netted']],
        )->all();

        $this->assertSame([
            $this->journalLineOn($original) => true,
            $this->journalLineOn($reversal) => true,
            $this->journalLineOn($inTransit) => false,
            $this->journalLineOn($inPeriod) => false,
        ], $netted);
        $this->assertSame(1, $shown['outstanding_count']);
        $this->assertFalse(collect($shown['lines'])->firstWhere('source', 'statement')['netted']);
    }

    /**
     * The index of the first logged query the test accepts, or null.
     *
     * @param  list<array{query: string, bindings: array<int, mixed>}>  $log
     * @param  callable(string): bool  $test
     */
    private function firstQuery(array $log, callable $test): ?int
    {
        foreach ($log as $i => $entry) {
            if ($test($entry['query'])) {
                return $i;
            }
        }

        return null;
    }
}
