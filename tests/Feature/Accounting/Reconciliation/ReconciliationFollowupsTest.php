<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AuditLog;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\ReconciliationMatcher;
use App\Services\Accounting\TrialBalanceBuilder;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Follow-ups to the C5 reconciliation review (follow-up round 1, FU-B):
 * near-date duplicates, the override's audit, the journal-number collision
 * report, the Reconciled flag and re-pairing a matched line. Amounts are
 * integer centavos, signed, positive for money in.
 */
class ReconciliationFollowupsTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** The journal line a journal posted on the given account. */
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

    /** @return array<string, mixed> */
    private function show(AccountingReconciliation $reconciliation): array
    {
        return $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()->json('data');
    }

    // ── L1: near-date duplicates ──

    public function test_an_entry_dated_just_before_the_period_is_caught_and_can_be_matched_instead(): void
    {
        // Deposited Aug 30, cleared the bank Sep 2: a deposit in transit,
        // in August's books and September's statement.
        $existing = $this->deposit(75050, '2026-08-30');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'Deposit', 'amount' => 75050],
        ]);
        $reconciliation->update(['statement_balance' => 75050]);
        $line = $this->statementLine($reconciliation);
        $before = $this->journalCount();

        $this->act('suspense', $reconciliation, $line)
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.line.0',
                "This statement line matches {$existing->journal_no}, dated 2026-08-30, already in the books. "
                .'Match it instead, or record it anyway.',
            );

        $this->assertSame($before, $this->journalCount());
        $this->assertNull($line->refresh()->matched_journal_line_id);

        // "Match it instead" is real: the suggestion is on the worksheet and
        // confirming it reconciles September.
        $shown = $this->show($reconciliation);
        $row = collect($shown['lines'])->firstWhere('statement_line_id', $line->id);
        $this->assertSame('possible', $row['match']);
        $this->assertSame('2026-08-30', $row['date']);
        $this->assertTrue($row['outside_period']);
        $this->assertFalse($shown['is_reconciled']);

        $confirmed = $this->postJson(
            "/api/accounting/reconciliations/{$reconciliation->id}/match",
            ['accept_suggestions' => true],
        )->assertOk()->json('data');

        $this->assertSame($this->journalLineOn($existing), $line->refresh()->matched_journal_line_id);
        $this->assertSame(0, $confirmed['difference']);
        $this->assertSame(1, $confirmed['matched_count']);
        $this->assertTrue($confirmed['is_reconciled']);
    }

    public function test_an_entry_dated_just_after_the_period_with_the_same_reference_is_caught(): void
    {
        $existing = $this->postSimpleJournal('5070', '1040', 20000, [
            'date' => '2026-10-03',
            'reference' => 'CHK-1007',
            'description' => 'Cheque 1007',
        ]);

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-29', 'description' => 'Cheque 1007', 'amount' => -21000, 'external_reference' => 'chk-1007'],
        ]);

        $this->act('expense', $reconciliation, $this->statementLine($reconciliation), [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'Supplier',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.line.0', fn (string $message): bool => str_contains($message, $existing->journal_no));
    }

    public function test_a_reference_on_the_other_side_is_not_the_same_money(): void
    {
        // A returned cheque echoes the deposit's reference with the opposite
        // sign: it is not the deposit.
        $this->deposit(20000, '2026-10-03', 'CHK-1007');
        $this->deposit(30000, '2026-09-20', 'CHK-2001');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-29', 'description' => 'Returned cheque', 'amount' => -20000, 'external_reference' => 'CHK-1007'],
            ['date' => '2026-09-21', 'description' => 'Returned cheque', 'amount' => -30000, 'external_reference' => 'CHK-2001'],
        ]);

        $statementRows = collect($this->show($reconciliation)['lines'])->whereNotNull('statement_line_id');
        $this->assertSame(['unmatched', 'unmatched'], $statementRows->pluck('match')->values()->all());

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))->assertCreated();
    }

    public function test_a_reversed_entry_and_its_reversal_outside_the_period_are_not_candidates(): void
    {
        $original = $this->deposit(75050, '2026-08-29');
        app(JournalPoster::class)->reverse($original, '2026-08-31', 'Posted twice', $this->admin->id);

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'Deposit', 'amount' => 75050],
            ['date' => '2026-09-03', 'description' => 'Debit', 'amount' => -75050],
        ]);

        $lines = $this->show($reconciliation)['lines'];
        $this->assertSame([], array_values(array_filter($lines, static fn (array $row): bool => $row['date'] < '2026-09-01')));
        $this->assertSame(0, $this->show($reconciliation)['possible_count']);

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))->assertCreated();
    }

    public function test_an_entry_more_than_five_days_away_is_not_a_duplicate(): void
    {
        $this->deposit(75050, '2026-08-27');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'Deposit', 'amount' => 75050],
        ]);

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))->assertCreated();
    }

    public function test_an_entry_of_the_opposite_sign_is_not_a_duplicate(): void
    {
        $this->postSimpleJournal('5070', '1040', 75050, ['date' => '2026-08-31', 'description' => 'Paid out']);

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'Deposit', 'amount' => 75050],
        ]);

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))->assertCreated();
    }

    public function test_an_entry_another_statement_line_already_matched_is_not_a_duplicate(): void
    {
        $august = $this->deposit(75050, '2026-08-30');

        $augustReconciliation = AccountingReconciliation::query()->findOrFail(
            $this->postJson('/api/accounting/reconciliations', [
                'account_id' => $this->account('1040'),
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'statement_balance' => 75050,
                'lines' => [['date' => '2026-08-30', 'description' => 'Deposit', 'amount' => 75050]],
            ])->assertCreated()->json('data.id'),
        );
        $this->postJson("/api/accounting/reconciliations/{$augustReconciliation->id}/match", [
            'matches' => [[
                'line_id' => $this->statementLine($augustReconciliation)->id,
                'journal_line_id' => $this->journalLineOn($august),
            ]],
        ])->assertOk();

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'A second deposit', 'amount' => 75050],
        ]);

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))->assertCreated();
    }

    // ── Outstanding items (deposits in transit, uncleared cheques) ──

    public function test_the_worksheet_lists_unclaimed_entries_from_60_days_before_to_5_days_after(): void
    {
        $this->deposit(10000, '2026-07-02'); // 61 days before Sep 1: too old
        $inTransit = $this->deposit(20000, '2026-07-03'); // 60 days before
        $this->deposit(30000, '2026-09-15'); // in the period
        $late = $this->deposit(40000, '2026-10-05'); // 5 days after Sep 30
        $this->deposit(50000, '2026-10-06'); // 6 days after: too late

        $reconciliation = $this->reconciliationWith([]);
        $shown = $this->show($reconciliation);

        $ledger = collect($shown['lines'])->where('source', 'ledger')
            ->map(static fn (array $row): array => [$row['date'], $row['amount'], $row['outside_period']])
            ->values()->all();

        $this->assertSame([
            ['2026-07-03', 20000, true],
            ['2026-09-15', 30000, false],
            ['2026-10-05', 40000, true],
        ], $ledger);
        $this->assertSame($this->journalLineOn($inTransit), $shown['lines'][0]['journal_line_id']);
        $this->assertSame($this->journalLineOn($late), $shown['lines'][2]['journal_line_id']);

        // In-period leftovers keep their count; outstanding items have their own.
        $this->assertSame(1, $shown['unmatched_count']);
        $this->assertSame(2, $shown['outstanding_count']);
    }

    public function test_an_outstanding_entry_can_be_matched_by_hand_and_an_older_one_cannot(): void
    {
        $inTransit = $this->deposit(20000, '2026-08-10');
        $tooOld = $this->deposit(20000, '2026-06-30');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-01', 'description' => 'Deposit', 'amount' => 20000],
        ]);
        $line = $this->statementLine($reconciliation);
        $url = "/api/accounting/reconciliations/{$reconciliation->id}/match";

        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($tooOld)]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('matches');

        $shown = $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($inTransit)]]])
            ->assertOk()->json('data');

        $row = collect($shown['lines'])->firstWhere('statement_line_id', $line->id);
        $this->assertSame('matched', $row['match']);
        $this->assertSame('2026-08-10', $row['date']);
        $this->assertTrue($row['outside_period']);
    }

    public function test_an_entry_another_reconciliation_matched_is_shown_but_never_suggested(): void
    {
        // A Sep 29 deposit that cleared Oct 2 belongs to October's statement.
        $deposit = $this->deposit(75050, '2026-09-29');

        $october = AccountingReconciliation::query()->findOrFail(
            $this->postJson('/api/accounting/reconciliations', [
                'account_id' => $this->account('1040'),
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
                'statement_balance' => 75050,
                'lines' => [['date' => '2026-10-02', 'description' => 'Deposit', 'amount' => 75050]],
            ])->assertCreated()->json('data.id'),
        );
        $this->postJson("/api/accounting/reconciliations/{$october->id}/match", ['accept_suggestions' => true])
            ->assertOk();
        $this->assertTrue($this->show($october)['is_reconciled']);

        $september = $this->reconciliationWith([
            ['date' => '2026-09-29', 'description' => 'Another deposit', 'amount' => 75050],
        ]);

        $shown = $this->show($september);
        $this->assertSame(0, $shown['possible_count']);
        $ledgerRow = collect($shown['lines'])->firstWhere('source', 'ledger');
        $this->assertSame($this->journalLineOn($deposit), $ledgerRow['journal_line_id']);
        $this->assertFalse($ledgerRow['outside_period']);

        $this->postJson("/api/accounting/reconciliations/{$september->id}/match", ['accept_suggestions' => true])
            ->assertOk();
    }

    public function test_a_journal_that_is_not_posted_cannot_be_matched(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('1040'), 'debit' => 20000, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 20000],
        ], ['date' => '2026-09-10']);
        $reversed = $this->deposit(30000, '2026-09-11');
        app(JournalPoster::class)->reverse($reversed, '2026-09-12', 'Wrong account', $this->admin->id);

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 20000],
        ]);
        $line = $this->statementLine($reconciliation);
        $url = "/api/accounting/reconciliations/{$reconciliation->id}/match";

        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($draft)]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.matches.0', fn (string $m): bool => str_contains($m, 'is a draft'));

        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($reversed->refresh())]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.matches.0', fn (string $m): bool => str_contains($m, 'has been reversed'));

        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    // ── L4: the override is audited ──

    public function test_recording_anyway_audits_the_override_and_the_suggested_journal(): void
    {
        $existing = $this->deposit(75050, '2026-08-30');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'Deposit', 'amount' => 75050],
        ]);
        $line = $this->statementLine($reconciliation);

        $journalNo = $this->act('suspense', $reconciliation, $line, ['record_anyway' => true])
            ->assertCreated()
            ->json('created.journal_no');

        $row = AuditLog::query()
            ->where('auditable_type', $reconciliation->getMorphClass())
            ->where('auditable_id', $reconciliation->id)
            ->where('description', 'like', 'Statement line%')
            ->sole();

        $this->assertTrue($row->new_values['record_anyway']);
        $this->assertSame($existing->journal_no, $row->new_values['suggested_journal_no']);
        $this->assertSame(
            "Statement line {$line->id} recorded as suspense entry {$journalNo}, recorded anyway although it "
            ."matched {$existing->journal_no}",
            $row->description,
        );
    }

    public function test_recording_anyway_over_an_in_period_suggestion_is_audited_too(): void
    {
        $existing = $this->deposit(75050, '2026-09-16', 'IBFT-88231');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050, 'external_reference' => 'IBFT-88231'],
        ]);

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation), ['record_anyway' => true])
            ->assertCreated();

        $row = AuditLog::query()
            ->where('auditable_type', $reconciliation->getMorphClass())
            ->where('auditable_id', $reconciliation->id)
            ->where('description', 'like', 'Statement line%')
            ->sole();

        $this->assertTrue($row->new_values['record_anyway']);
        $this->assertSame($existing->journal_no, $row->new_values['suggested_journal_no']);
    }

    public function test_an_action_with_nothing_to_override_audits_no_override(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation), ['record_anyway' => true])
            ->assertCreated();

        $row = AuditLog::query()
            ->where('auditable_type', $reconciliation->getMorphClass())
            ->where('auditable_id', $reconciliation->id)
            ->where('description', 'like', 'Statement line%')
            ->sole();

        $this->assertArrayNotHasKey('record_anyway', $row->new_values);
        $this->assertArrayNotHasKey('suggested_journal_no', $row->new_values);
    }

    // ── match(): locks and collisions ──

    public function test_match_locks_the_named_statement_lines_in_id_order_before_anything_else(): void
    {
        $first = $this->deposit(10000, '2026-09-10');
        $second = $this->deposit(20000, '2026-09-11');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-10', 'description' => 'A', 'amount' => 10000],
            ['date' => '2026-09-11', 'description' => 'B', 'amount' => 20000],
        ]);
        $a = $this->statementLine($reconciliation, 0);
        $b = $this->statementLine($reconciliation, 1);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Named in reverse id order on purpose.
        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", ['matches' => [
            ['line_id' => $b->id, 'journal_line_id' => $this->journalLineOn($second)],
            ['line_id' => $a->id, 'journal_line_id' => $this->journalLineOn($first)],
        ]])->assertOk();

        $locking = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $q): bool => str_ends_with($q, 'for update'),
        ));
        DB::disableQueryLog();

        // One read locks both lines, in id order, before any other lock.
        $this->assertStringContainsString('from `accounting_reconciliation_lines` where `accounting_reconciliation_lines`.`id` in', $locking[0]);
        $this->assertStringContainsString('order by `id` asc', $locking[0]);

        // Each ledger line's "already claimed" state is read under a lock.
        $claimReads = array_filter($locking, static fn (string $q): bool => str_contains($q, '`matched_journal_line_id` ='));
        $this->assertCount(2, $claimReads);
    }

    public function test_a_deadlock_while_matching_answers_409_in_words(): void
    {
        $deposit = $this->deposit(10000, '2026-09-10');
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-10', 'description' => 'A', 'amount' => 10000],
        ]);
        $line = $this->statementLine($reconciliation);

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, '`matched_journal_line_id` =') && str_ends_with($query, 'for update')) {
                $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
                $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
                (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '40001');

                throw $pdo;
            }
        });

        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", [
            'matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($deposit)]],
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another change to this reconciliation was saved at the same time. Reload and try again.');

        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    public function test_a_ledger_line_claimed_at_the_same_moment_answers_409_in_words(): void
    {
        $deposit = $this->deposit(10000, '2026-09-10');
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-10', 'description' => 'A', 'amount' => 10000],
        ]);
        $line = $this->statementLine($reconciliation);

        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (str_starts_with($query, 'update `accounting_reconciliation_lines`')) {
                $index = 'acct_recon_lines_journal_line_unique';
                $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key '{$index}'");
                $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key '{$index}'"];
                (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '23000');

                throw (new UniqueConstraintViolationException(DB::connection()->getName(), $query, $bindings, $pdo))
                    ->setIndex($index);
            }
        });

        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", [
            'matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($deposit)]],
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That ledger entry was just matched to another statement line. Reload and try again.');
    }

    // ── L5: a journal-number collision is reported ──

    public function test_a_journal_number_collision_is_reported_before_the_409(): void
    {
        Exceptions::fake();

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);

        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (str_starts_with($query, 'update `accounting_journals`') && str_contains($query, '`journal_no`')) {
                $index = 'accounting_journals_journal_no_unique';
                $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key '{$index}'");
                $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key '{$index}'"];
                (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '23000');

                throw (new UniqueConstraintViolationException(DB::connection()->getName(), $query, $bindings, $pdo))
                    ->setIndex($index);
            }
        });

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))->assertStatus(409);

        Exceptions::assertReported(UniqueConstraintViolationException::class);
    }

    // ── The Reconciled flag ──

    public function test_a_zero_difference_with_an_unmatched_statement_line_is_not_reconciled(): void
    {
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-10', 'description' => 'DEP 001', 'amount' => 1500000],
            ['date' => '2026-09-20', 'description' => 'Bank charge', 'amount' => -1234],
        ]);
        $reconciliation->update(['statement_balance' => 1500000]);

        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", ['accept_suggestions' => true])
            ->assertOk();

        $shown = $this->show($reconciliation);
        $this->assertSame(0, $shown['difference']);
        $this->assertSame(1, $shown['unmatched_count']);
        $this->assertFalse($shown['is_reconciled']);
    }

    public function test_a_suggestion_not_yet_confirmed_is_not_reconciled_until_it_is(): void
    {
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-11', 'description' => 'DEP 001', 'amount' => 1500000],
        ]);
        $reconciliation->update(['statement_balance' => 1500000]);

        $this->assertSame('possible', $this->show($reconciliation)['lines'][0]['match']);
        $this->assertFalse($this->show($reconciliation)['is_reconciled']);

        $confirmed = $this->postJson(
            "/api/accounting/reconciliations/{$reconciliation->id}/match",
            ['accept_suggestions' => true],
        )->assertOk()->json('data');

        $this->assertSame(0, $confirmed['difference']);
        $this->assertTrue($confirmed['is_reconciled']);
    }

    public function test_the_list_reads_the_same_flag(): void
    {
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-20', 'description' => 'Bank charge', 'amount' => -1234],
        ]);
        $reconciliation->update(['statement_balance' => 1500000]);

        $row = collect($this->getJson('/api/accounting/reconciliations')->assertOk()->json('data'))
            ->firstWhere('id', $reconciliation->id);

        $this->assertSame(0, $row['difference']);
        $this->assertFalse($row['is_reconciled']);
    }

    // ── Re-pairing a matched line ──

    public function test_a_matched_line_cannot_be_paired_with_another_entry_until_it_is_unmatched(): void
    {
        $first = $this->deposit(1500000, '2026-09-10');
        $second = $this->deposit(1500000, '2026-09-12');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-10', 'description' => 'DEP 001', 'amount' => 1500000],
        ]);
        $line = $this->statementLine($reconciliation);
        $url = "/api/accounting/reconciliations/{$reconciliation->id}/match";

        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($first)]]])
            ->assertOk();

        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($second)]]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This statement line is already matched. Unmatch it first.');

        $this->assertSame($this->journalLineOn($first), $line->refresh()->matched_journal_line_id);

        // Sending the same pairing again changes nothing and is not a re-pair.
        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($first)]]])
            ->assertOk();

        // Unmatch, then pair with the other entry.
        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => null]]])->assertOk();
        $this->postJson($url, ['matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($second)]]])
            ->assertOk();

        $this->assertSame($this->journalLineOn($second), $line->refresh()->matched_journal_line_id);
    }

    public function test_accepting_suggestions_skips_a_line_matched_since_they_were_worked_out(): void
    {
        // The route keeps its controller, so the matcher is swapped before the
        // first request. Its suggestions are the ones worked out before the
        // other request's match committed; everything else is the real engine.
        $stale = [];
        $matcher = Mockery::mock(ReconciliationMatcher::class, [app(TrialBalanceBuilder::class)])->makePartial();
        $matcher->shouldReceive('suggestions')->andReturnUsing(function () use (&$stale): array {
            return $stale;
        });
        $this->instance(ReconciliationMatcher::class, $matcher);

        $first = $this->deposit(1500000, '2026-09-10');
        $second = $this->deposit(1500000, '2026-09-12');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-10', 'description' => 'DEP 001', 'amount' => 1500000],
        ]);
        $line = $this->statementLine($reconciliation);
        $stale = [$line->id => $this->journalLineOn($second)];

        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", [
            'matches' => [['line_id' => $line->id, 'journal_line_id' => $this->journalLineOn($first)]],
        ])->assertOk();

        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", ['accept_suggestions' => true])
            ->assertOk();

        $this->assertSame($this->journalLineOn($first), $line->refresh()->matched_journal_line_id);
    }
}
