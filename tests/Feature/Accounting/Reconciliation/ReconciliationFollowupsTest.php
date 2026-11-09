<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AuditLog;
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

    public function test_an_entry_dated_just_before_the_period_is_caught_as_already_in_the_books(): void
    {
        // Deposited Aug 30, cleared the bank Sep 2: the engine only looks
        // inside September, so it never offers the August entry.
        $existing = $this->deposit(75050, '2026-08-30');

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-02', 'description' => 'Deposit', 'amount' => 75050],
        ]);
        $line = $this->statementLine($reconciliation);
        $before = $this->journalCount();

        $this->act('suspense', $reconciliation, $line)
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.line.0',
                "This statement line matches {$existing->journal_no}, dated 2026-08-30, already in the books just "
                .'outside this period. Check it is not the same money, or record it anyway.',
            );

        $this->assertSame($before, $this->journalCount());
        $this->assertNull($line->refresh()->matched_journal_line_id);
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
