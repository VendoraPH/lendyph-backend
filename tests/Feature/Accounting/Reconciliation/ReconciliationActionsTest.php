<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Turning an unmatched statement line into an expense, a transfer or a
 * suspense entry, matched to it in the same step (Phase 2, Accounting round C,
 * slice C5). Amounts are integer centavos, signed, positive for money in.
 */
class ReconciliationActionsTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    // ── Expense ──

    public function test_a_bank_charge_line_becomes_a_paid_expense_matched_to_the_bank_line(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000, 'external_reference' => 'BC-0912'],
        ]);
        $line = $this->statementLine($reconciliation);

        $response = $this->act('expense', $reconciliation, $line, [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertCreated();

        $expense = AccountingExpense::query()->sole();
        $this->assertSame(15000, $expense->amount);
        $this->assertSame(15000, $expense->amount_paid);
        $this->assertSame('paid', $expense->settlement_status);
        $this->assertSame($this->account('1040'), $expense->payment_account_id);
        $this->assertSame('2026-09-12', $expense->date->toDateString());
        $this->assertNull($expense->branch_id);
        $this->assertSame('BC-0912', $expense->reference);

        $this->assertSame([
            '5070' => ['debit' => 15000, 'credit' => 0],
            '1040' => ['debit' => 0, 'credit' => 15000],
        ], $this->linesByCode($expense->journal_id));
        $this->assertMatchedTo($line, $expense->journal_id);

        $journal = AccountingJournal::query()->findOrFail($expense->journal_id);
        $this->assertSame([
            'type' => 'expense',
            'id' => $expense->id,
            'journal_id' => $journal->id,
            'journal_no' => $journal->journal_no,
        ], $response->json('created'));
        $this->assertSame($reconciliation->id, $response->json('data.id'));
    }

    public function test_money_in_cannot_be_recorded_as_an_expense(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-12', 'description' => 'Deposit', 'amount' => 15000],
        ]);

        $this->act('expense', $reconciliation, $this->statementLine($reconciliation), [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertStatus(422)->assertJsonValidationErrors('line');

        $this->assertSame(0, AccountingExpense::query()->count());
        $this->assertNull($this->statementLine($reconciliation)->matched_journal_line_id);
    }

    public function test_an_expense_needs_an_expense_account_and_a_payee(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000],
        ]);
        $line = $this->statementLine($reconciliation);

        $this->act('expense', $reconciliation, $line, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expense_account_id', 'payee']);

        $this->act('expense', $reconciliation, $line, [
            'expense_account_id' => $this->account('4010'),
            'payee' => 'BDO',
        ])->assertStatus(422)->assertJsonValidationErrors('expense_account_id');

        // 160, the expenses.payee column's width.
        $this->act('expense', $reconciliation, $line, [
            'expense_account_id' => $this->account('5070'),
            'payee' => str_repeat('B', 161),
        ])->assertStatus(422)->assertJsonValidationErrors('payee');

        $this->assertSame(0, AccountingExpense::query()->count());
    }

    // ── Transfer ──

    public function test_money_in_moves_from_the_counter_account_into_the_reconciled_one(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Cash deposit', 'amount' => 200000, 'external_reference' => 'DEP-14'],
        ]);
        $line = $this->statementLine($reconciliation);

        $response = $this->act('transfer', $reconciliation, $line, [
            'counter_account_id' => $this->account('1010'),
        ])->assertCreated();

        $journalId = $response->json('created.journal_id');
        $journal = AccountingJournal::query()->findOrFail($journalId);

        $this->assertSame('transfer', $journal->source);
        $this->assertSame('2026-09-14', $journal->date->toDateString());
        $this->assertNull($journal->branch_id);
        $this->assertSame('DEP-14', $journal->reference);
        $this->assertSame([
            '1040' => ['debit' => 200000, 'credit' => 0],
            '1010' => ['debit' => 0, 'credit' => 200000],
        ], $this->linesByCode($journalId));
        $this->assertMatchedTo($line, $journalId);
        $this->assertSame([
            'type' => 'transfer',
            'id' => $journalId,
            'journal_id' => $journalId,
            'journal_no' => $journal->journal_no,
        ], $response->json('created'));
    }

    public function test_money_out_moves_from_the_reconciled_account_into_the_counter_one(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Withdrawal', 'amount' => -200000],
        ]);
        $line = $this->statementLine($reconciliation);

        $journalId = $this->act('transfer', $reconciliation, $line, [
            'counter_account_id' => $this->account('1010'),
            'description' => 'Petty cash top-up',
        ])->assertCreated()->json('created.journal_id');

        $this->assertSame([
            '1010' => ['debit' => 200000, 'credit' => 0],
            '1040' => ['debit' => 0, 'credit' => 200000],
        ], $this->linesByCode($journalId));
        $this->assertSame('Petty cash top-up', AccountingJournal::query()->findOrFail($journalId)->description);
        $this->assertMatchedTo($line, $journalId);
    }

    public function test_the_counter_account_cannot_be_the_reconciled_one(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Cash deposit', 'amount' => 200000],
        ]);
        $before = $this->journalCount();

        $this->act('transfer', $reconciliation, $this->statementLine($reconciliation), [
            'counter_account_id' => $this->account('1040'),
        ])->assertStatus(422)->assertJsonValidationErrors('counter_account_id');

        $this->assertSame($before, $this->journalCount());
    }

    public function test_the_counter_account_must_be_a_money_account(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-14', 'description' => 'Cash deposit', 'amount' => 200000],
        ]);
        $before = $this->journalCount();

        $this->act('transfer', $reconciliation, $this->statementLine($reconciliation), [
            'counter_account_id' => $this->account('1110'),
        ])->assertStatus(422)->assertJsonValidationErrors('counter_account_id');

        $this->assertSame($before, $this->journalCount());
    }

    // ── Suspense ──

    public function test_unidentified_money_in_on_a_bank_account_goes_to_suspense_as_a_bank_transaction(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050, 'external_reference' => 'IBFT-88231'],
        ]);
        $line = $this->statementLine($reconciliation);

        $response = $this->act('suspense', $reconciliation, $line)->assertCreated();

        $journalId = $response->json('created.journal_id');
        $journal = AccountingJournal::query()->findOrFail($journalId);

        $this->assertSame('bank_transaction', $journal->source);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('2026-09-16', $journal->date->toDateString());
        $this->assertNull($journal->branch_id);
        $this->assertNull($journal->postable_type);
        $this->assertSame('IBFT-88231', $journal->reference);
        $this->assertSame('Unidentified statement line: IBFT 88231', $journal->description);
        $this->assertSame([
            '1040' => ['debit' => 75050, 'credit' => 0],
            '2390' => ['debit' => 0, 'credit' => 75050],
        ], $this->linesByCode($journalId));
        $this->assertMatchedTo($line, $journalId);
        $this->assertSame([
            'type' => 'journal',
            'id' => $journalId,
            'journal_id' => $journalId,
            'journal_no' => $journal->journal_no,
        ], $response->json('created'));
    }

    public function test_unidentified_money_out_on_a_cash_account_goes_to_suspense_as_a_cash_transaction(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'Count short', 'amount' => -5000],
        ], '1010');
        $line = $this->statementLine($reconciliation);

        $journalId = $this->act('suspense', $reconciliation, $line)->assertCreated()->json('created.journal_id');
        $journal = AccountingJournal::query()->findOrFail($journalId);

        $this->assertSame('cash_transaction', $journal->source);
        $this->assertSame("REC-{$reconciliation->id}-{$line->id}", $journal->reference);
        $this->assertSame([
            '2390' => ['debit' => 5000, 'credit' => 0],
            '1010' => ['debit' => 0, 'credit' => 5000],
        ], $this->linesByCode($journalId));
        $this->assertMatchedTo($line, $journalId, '1010');
    }

    public function test_suspense_needs_a_mapped_suspense_account(): void
    {
        AccountingAccountMapping::query()->where('role', 'suspense')->delete();

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);
        $before = $this->journalCount();

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))
            ->assertStatus(422)
            ->assertJsonPath('errors.account_mapping.0', 'Map a Suspense account in Accounting Settings first');

        $this->assertSame($before, $this->journalCount());
    }

    // ── Refusals ──

    public function test_a_second_submit_for_the_same_line_answers_409_and_records_once(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);
        $line = $this->statementLine($reconciliation);

        $this->act('suspense', $reconciliation, $line)->assertCreated();
        $after = $this->journalCount();

        $this->act('suspense', $reconciliation, $line)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This statement line is already matched');
        $this->act('expense', $reconciliation, $line, [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertStatus(409);

        $this->assertSame($after, $this->journalCount());
        $this->assertSame(0, AccountingExpense::query()->count());
    }

    public function test_a_line_dated_in_a_closed_period_is_refused_with_nothing_written(): void
    {
        $this->postSimpleJournal('1040', '3010', 100000, ['date' => '2026-09-02']);
        $this->getJson('/api/accounting/periods?per_page=100')->assertOk();

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000],
        ]);
        $line = $this->statementLine($reconciliation);

        $period = AccountingPeriod::query()->where('code', '2026-09')->sole();
        $this->postJson("/api/accounting/periods/{$period->id}/close")->assertOk();

        $journals = $this->journalCount();
        $audits = AuditLog::query()->count();

        $this->act('expense', $reconciliation, $line, [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->act('transfer', $reconciliation, $line, ['counter_account_id' => $this->account('1010')])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->act('suspense', $reconciliation, $line)
            ->assertStatus(422)->assertJsonValidationErrors('date');

        $this->assertSame($journals, $this->journalCount());
        $this->assertSame(0, AccountingExpense::query()->count());
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    public function test_a_line_dated_outside_the_reconciliation_window_is_refused(): void
    {
        // Accepted at creation inside the five-day value-dating tolerance, but a
        // journal dated there could never be matched to this reconciliation.
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-10-02', 'description' => 'IBFT late', 'amount' => 75050],
        ]);
        $before = $this->journalCount();

        $this->act('suspense', $reconciliation, $this->statementLine($reconciliation))
            ->assertStatus(422)
            ->assertJsonValidationErrors('line');

        $this->assertSame($before, $this->journalCount());
    }

    public function test_a_line_the_books_already_hold_is_refused_unless_recorded_anyway(): void
    {
        $existing = $this->postSimpleJournal('1040', '3010', 75050, [
            'date' => '2026-09-16',
            'reference' => 'IBFT-88231',
            'description' => 'Capital deposit',
        ]);

        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050, 'external_reference' => 'IBFT-88231'],
        ]);
        $line = $this->statementLine($reconciliation);
        $before = $this->journalCount();

        foreach (['suspense' => [], 'transfer' => ['counter_account_id' => $this->account('1010')]] as $action => $body) {
            $this->act($action, $reconciliation, $line, $body)
                ->assertStatus(422)
                ->assertJsonPath(
                    'errors.line.0',
                    "This statement line matches {$existing->journal_no} already in the books. Match it instead, or record it anyway.",
                );
        }

        $this->assertSame($before, $this->journalCount());
        $this->assertNull($line->refresh()->matched_journal_line_id);

        $journalId = $this->act('suspense', $reconciliation, $line, ['record_anyway' => true])
            ->assertCreated()
            ->json('created.journal_id');

        $this->assertMatchedTo($line, $journalId);
        $this->assertSame($before + 1, $this->journalCount());
    }

    public function test_the_journal_number_index_exists(): void
    {
        $this->assertNotEmpty(DB::select(
            'SHOW INDEX FROM accounting_journals WHERE Key_name = ?',
            ['accounting_journals_journal_no_unique'],
        ));
    }

    public function test_a_journal_number_collision_answers_409_with_nothing_written(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);
        $line = $this->statementLine($reconciliation);
        $before = $this->journalCount();

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

        $this->act('suspense', $reconciliation, $line)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another change to this reconciliation was saved at the same time. Reload and try again.');

        $this->assertSame($before, $this->journalCount());
        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    public function test_a_line_from_another_reconciliation_answers_404(): void
    {
        $first = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);
        $other = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'Cash count', 'amount' => 75050],
        ], '1010');

        $this->act('suspense', $first, $this->statementLine($other))->assertNotFound();
        $this->assertNull($this->statementLine($other)->matched_journal_line_id);
    }

    // ── Audit ──

    public function test_each_action_writes_one_audit_row_on_the_reconciliation(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);
        $line = $this->statementLine($reconciliation);

        $journalNo = $this->act('suspense', $reconciliation, $line)->assertCreated()->json('created.journal_no');

        $rows = AuditLog::query()
            ->where('auditable_type', $reconciliation->getMorphClass())
            ->where('auditable_id', $reconciliation->id)
            ->where('description', 'like', 'Statement line%')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame("Statement line {$line->id} recorded as suspense entry {$journalNo}", $rows[0]->description);
    }

    // ── Read model ──

    public function test_after_each_action_the_worksheet_shows_the_line_matched_and_the_figures_move(): void
    {
        $reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000],
            ['date' => '2026-09-14', 'description' => 'Cash deposit', 'amount' => 200000],
            ['date' => '2026-09-16', 'description' => 'IBFT 88231', 'amount' => 75050],
        ]);

        $before = $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()->json('data');
        $this->assertSame(0, $before['book_balance']);
        $this->assertSame(0, $before['matched_count']);

        $steps = [
            ['expense', 0, ['expense_account_id' => $this->account('5070'), 'payee' => 'BDO'], -15000],
            ['transfer', 1, ['counter_account_id' => $this->account('1010')], 200000],
            ['suspense', 2, [], 75050],
        ];

        $balance = 0;

        foreach ($steps as $i => [$action, $index, $body, $amount]) {
            $line = $this->statementLine($reconciliation, $index);
            $answered = $this->act($action, $reconciliation, $line, $body)->assertCreated()->json('data');
            $shown = $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()->json('data');

            // The action answers exactly what the worksheet shows.
            $this->assertSame($shown, $answered);

            $balance += $amount;
            $this->assertSame($balance, $shown['book_balance']);
            $this->assertSame($balance - $shown['statement_balance'], $shown['difference']);
            $this->assertSame($i + 1, $shown['matched_count']);

            $row = collect($shown['lines'])->firstWhere('statement_line_id', $line->id);
            $this->assertSame('matched', $row['match']);
            $this->assertSame($line->refresh()->matched_journal_line_id, $row['journal_line_id']);
        }

        $this->assertSame(0, $shown['unmatched_count']);
    }
}
