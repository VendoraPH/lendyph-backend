<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Shared set-up for the reconciliation action tests (Phase 2, slice C5): a
 * September 2026 reconciliation with statement lines, and the three action
 * calls.
 */
trait ReconciliationActionHelpers
{
    /**
     * A September 2026 reconciliation on one money account, with the given
     * statement lines. Amounts are signed centavos, positive for money in.
     *
     * @param  list<array{date: string, description: string, amount: int, external_reference?: string|null}>  $lines
     */
    protected function reconciliationWith(array $lines, string $accountCode = '1040'): AccountingReconciliation
    {
        $id = $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account($accountCode),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 0,
            'lines' => $lines,
        ])->assertCreated()->json('data.id');

        return AccountingReconciliation::query()->findOrFail($id);
    }

    protected function statementLine(AccountingReconciliation $reconciliation, int $index = 0): AccountingReconciliationLine
    {
        return AccountingReconciliationLine::query()
            ->where('accounting_reconciliation_id', $reconciliation->id)
            ->orderBy('id')
            ->get()[$index];
    }

    /** @param  array<string, mixed>  $body */
    protected function act(
        string $action,
        AccountingReconciliation $reconciliation,
        AccountingReconciliationLine $line,
        array $body = [],
    ): TestResponse {
        return $this->postJson(
            "/api/accounting/reconciliations/{$reconciliation->id}/lines/{$line->id}/{$action}",
            $body,
        );
    }

    /** @return array<string, array{debit: int, credit: int}> account code => totals, on one journal */
    protected function linesByCode(int $journalId): array
    {
        return AccountingJournalLine::query()
            ->join('accounting_accounts as a', 'a.id', '=', 'accounting_journal_lines.accounting_account_id')
            ->where('accounting_journal_id', $journalId)
            ->get(['a.code', 'debit', 'credit'])
            ->mapWithKeys(static fn ($l): array => [
                $l->code => ['debit' => (int) $l->debit, 'credit' => (int) $l->credit],
            ])
            ->all();
    }

    /** The journal line on the reconciled account that the statement line is now matched to. */
    protected function assertMatchedTo(AccountingReconciliationLine $line, int $journalId, string $accountCode = '1040'): void
    {
        $expected = AccountingJournalLine::query()
            ->where('accounting_journal_id', $journalId)
            ->where('accounting_account_id', $this->account($accountCode))
            ->sole();

        $this->assertSame($expected->id, $line->refresh()->matched_journal_line_id);
    }

    protected function journalCount(): int
    {
        return AccountingJournal::query()->count();
    }

    /** A user holding exactly these permissions, through a role of their own, signed in. */
    protected function actAsUserWith(array $permissions): User
    {
        $role = Role::create(['name' => 'c5_'.substr(md5(implode(',', $permissions).microtime()), 0, 10), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = $this->userWithNoRole();
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }
}
