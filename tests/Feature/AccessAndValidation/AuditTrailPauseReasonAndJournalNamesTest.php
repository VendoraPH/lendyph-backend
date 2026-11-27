<?php

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliationLine;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\ReminderPause;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class, PostsJournals::class);

/*
 * The Audit Trail, follow-up round 2:
 * - a reminder pause's reason is personal text about the member, so it needs
 *   `borrowers:view` (with every branch), as a member pause's reason does on
 *   the reminder screens;
 * - a change naming a journal or a journal line reads the journal's number,
 *   never its database id. Stored rows don't change.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

function auditReaderHolding(Branch $branch, array $permissions): User
{
    $role = Role::create(['name' => 'probe_'.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->inBranches($branch)->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @return array<string, array{field: string, old: string|null, new: string|null}>
 */
function journalAuditChanges(string $type, array $old, array $new): array
{
    $row = AuditLog::create(['action' => 'updated', 'auditable_type' => $type, 'auditable_id' => 1, 'old_values' => $old, 'new_values' => $new]);

    return collect(test()->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field')->all();
}

it('leaves a reminder pause\'s reason out for a reader without borrowers:view, on the list, the detail and the CSV', function () {
    $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $pause = ReminderPause::query()->create([
        'scope' => 'borrower',
        'borrower_id' => $member->id,
        'reason' => 'Pause reason Lihim: in hospital',
        'paused_at' => now(),
        'paused_by' => $this->admin->id,
    ]);
    $pause->update(['resumed_at' => now(), 'resumed_by' => $this->admin->id]);
    $rows = AuditLog::query()->where('auditable_type', ReminderPause::class)->where('auditable_id', $pause->id)->orderBy('id')->pluck('id')->all();
    expect($rows)->toHaveCount(2);

    $this->actingAs(auditReaderHolding($this->branch, ['audit_logs:view', 'audit_logs:export', 'branches:view_all']));

    foreach ($rows as $id) {
        $response = $this->getJson("/api/audit-logs/{$id}")->assertOk();
        expect(array_keys($response->json('data.old_values') ?? []))->not->toContain('reason')
            ->and(array_keys($response->json('data.new_values') ?? []))->not->toContain('reason')
            ->and(array_column($response->json('data.changes'), 'field'))->not->toContain('reason')
            ->and($response->getContent())->not->toContain('Pause reason Lihim');
    }

    $list = $this->getJson('/api/audit-logs?auditable_type=ReminderPause&per_page=100')->assertOk()->getContent();
    $csv = $this->get('/api/audit-logs/export')->assertOk()->streamedContent();
    expect($list)->not->toContain('Pause reason Lihim')
        ->and($csv)->not->toContain('Pause reason Lihim');

    $this->actingAs(auditReaderHolding($this->branch, ['audit_logs:view', 'branches:view_all', 'borrowers:view']));
    expect($this->getJson("/api/audit-logs/{$rows[0]}")->assertOk()->json('data.new_values.reason'))->toBe('Pause reason Lihim: in hospital');

    expect(AuditLog::query()->findOrFail($rows[0])->new_values['reason'])->toBe('Pause reason Lihim: in hospital');
});

it('reads a matched journal line by its journal\'s number and line, and a reversal by the journal\'s number', function () {
    $this->seedChartOfAccounts();
    $journal = $this->postSimpleJournal('1010', '4010', 150_000);
    $line = AccountingJournalLine::query()->where('accounting_journal_id', $journal->id)->orderBy('line_no')->firstOrFail();
    $reversal = app(JournalPoster::class)->reverse($journal->fresh(), '2026-09-16', 'Posted twice', $this->admin->id);

    $matched = journalAuditChanges(AccountingReconciliationLine::class, ['matched_journal_line_id' => null], ['matched_journal_line_id' => $line->id]);
    $reversed = journalAuditChanges(AccountingJournal::class, ['reversed_by_journal_id' => null, 'status' => 'posted'], ['reversed_by_journal_id' => $reversal->id, 'status' => 'reversed']);
    $reverses = journalAuditChanges(AccountingJournal::class, ['reverses_journal_id' => null], ['reverses_journal_id' => $journal->id]);

    expect($journal->journal_no)->toStartWith('JE-')
        ->and($matched['matched_journal_line_id']['new'])->toBe("{$journal->journal_no} line {$line->line_no}")
        ->and($reversed['reversed_by_journal_id']['new'])->toBe($reversal->journal_no)
        ->and($reverses['reverses_journal_id']['new'])->toBe($journal->journal_no);
});

it('reads a draft journal by its reference, and a removed journal or line by its id in words', function () {
    $this->seedChartOfAccounts();
    $draft = $this->draftJournal([
        ['account_id' => $this->account('1010'), 'debit' => 5_000, 'credit' => 0],
        ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 5_000],
    ], ['reference' => 'OR-7781']);

    $changes = journalAuditChanges(AccountingJournal::class, ['journal_id' => 999_998, 'reversed_by_journal_id' => null], ['journal_id' => $draft->id, 'reversed_by_journal_id' => 999_999]);
    $line = journalAuditChanges(AccountingReconciliationLine::class, ['matched_journal_line_id' => 999_997], ['matched_journal_line_id' => null]);

    expect($draft->journal_no)->toBeNull()
        ->and($changes['journal_id'])->toBe(['field' => 'journal_id', 'old' => 'Journal #999998', 'new' => 'OR-7781'])
        ->and($changes['reversed_by_journal_id']['new'])->toBe('Journal #999999')
        ->and($line['matched_journal_line_id'])->toBe(['field' => 'matched_journal_line_id', 'old' => 'Journal line #999997', 'new' => null]);
});
