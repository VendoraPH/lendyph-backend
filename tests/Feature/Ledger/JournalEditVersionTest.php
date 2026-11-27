<?php

namespace Tests\Feature\Ledger;

use App\Models\AccountingJournal;
use App\Services\Accounting\JournalPoster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Every writer of allowance (1200) lines takes the allowance account row
 * before any journal or line lock. Post, edit and reverse decide whether to
 * take it from an unlocked read of the draft's lines, so an edit landing
 * between that read and the journal lock could add a 1200 line the decision
 * never saw. Each edit now bumps the journal's `edit_version`; post, edit and
 * reverse compare it right after the journal lock, before any line lock, and
 * refuse (422) when it moved.
 *
 * The edit is made inside the journal-lock query's own moment
 * (beforeExecuting), on the same connection: the second request's work,
 * landing exactly in the window. Its writes roll back with the refused
 * request's savepoint here, so each test proves what the refused request did
 * NOT do after the edit: lock lines, delete lines, or take the allowance lock
 * behind the journal's.
 */
class JournalEditVersionTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_09_100000_add_edit_version_to_accounting_journals_table.php';

    /** Whether the edit set up by editAtTheJournalLock() has landed. */
    private ?\Closure $editLanded = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_post_refuses_an_edit_that_landed_before_its_journal_lock_before_locking_any_line(): void
    {
        $draft = $this->draftWithoutTheAllowance();
        $after = $this->editAtTheJournalLock($draft, fn () => $this->poster()->updateDraft($draft->fresh(), [], $this->allowanceLines()));

        try {
            $this->poster()->post($draft, $this->admin->id);
            $this->fail('A draft edited mid-post was posted.');
        } catch (ValidationException $e) {
            $this->assertTrue(($this->editLanded)(), 'the edit never landed, so this test proved nothing');
            $this->assertStringContainsString('changed while it was being posted', $e->getMessage());
        }

        $this->assertSame([], $after->filter(fn (string $sql): bool => str_contains($sql, '`accounting_journal_lines`') && str_contains($sql, 'for update'))->all());
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_an_edit_refuses_another_edit_that_landed_before_its_journal_lock(): void
    {
        $draft = $this->draftWithoutTheAllowance();
        $after = $this->editAtTheJournalLock($draft, fn () => $this->poster()->updateDraft($draft->fresh(), [], $this->allowanceLines()));

        try {
            $this->poster()->updateDraft($draft, ['description' => 'Second edit'], [
                ['account_id' => $this->account('5140'), 'debit' => 200_000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 200_000],
            ]);
            $this->fail('An edit made over another, unseen, edit was saved.');
        } catch (ValidationException $e) {
            $this->assertTrue(($this->editLanded)(), 'the edit never landed, so this test proved nothing');
            $this->assertStringContainsString('changed while it was being edited', $e->getMessage());
        }

        // It would have deleted the other edit's allowance line behind the journal lock.
        $this->assertSame([], $after->filter(fn (string $sql): bool => str_starts_with($sql, 'delete from `accounting_journal_lines`'))->all());
    }

    public function test_a_reversal_refuses_a_draft_edited_and_posted_before_its_journal_lock(): void
    {
        $draft = $this->draftWithoutTheAllowance();
        $after = $this->editAtTheJournalLock($draft, function () use ($draft): void {
            $this->poster()->updateDraft($draft->fresh(), [], $this->allowanceLines());
            $this->poster()->post($draft->fresh(), $this->admin->id);
        });

        try {
            $this->poster()->reverse($draft, now()->toDateString(), 'Stale tab', $this->admin->id);
            $this->fail('A journal edited and posted mid-reversal was reversed.');
        } catch (ValidationException $e) {
            $this->assertTrue(($this->editLanded)(), 'the edit never landed, so this test proved nothing');
            $this->assertStringContainsString('changed while it was being reversed', $e->getMessage());
        }

        // It would have taken the allowance lock behind the journal's, for the mirror.
        $this->assertSame([], $after->filter(fn (string $sql): bool => str_contains($sql, '`accounting_accounts`') && str_contains($sql, 'for update'))->all());
    }

    /** Edits one after another are the ordinary case: each bumps the version, and the post goes through. */
    public function test_edits_one_after_another_bump_the_version_and_the_post_goes_through(): void
    {
        $draft = $this->draftWithoutTheAllowance();
        $this->assertSame(0, $this->versionOf($draft));

        $this->poster()->updateDraft($draft, ['description' => 'First edit']);
        $this->poster()->updateDraft($draft->fresh(), [], $this->allowanceLines());
        $this->assertSame(2, $this->versionOf($draft));

        $posted = $this->poster()->post($draft->fresh(), $this->admin->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame(2, $this->versionOf($draft), 'posting is not an edit');
        $this->poster()->reverse($posted, now()->toDateString(), null, $this->admin->id);
        $this->assertSame('reversed', $posted->fresh()->status);
    }

    public function test_the_migration_adds_the_version_with_a_default_of_zero_and_runs_twice(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('accounting_journals', 'edit_version'));
        $this->assertSame(0, $this->versionOf($this->draftWithoutTheAllowance()));
    }

    private function poster(): JournalPoster
    {
        return app(JournalPoster::class);
    }

    private function draftWithoutTheAllowance(): AccountingJournal
    {
        return $this->draftJournal([
            ['account_id' => $this->account('5140'), 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100_000],
        ]);
    }

    /** @return list<array{account_id: int, debit: int, credit: int}> */
    private function allowanceLines(): array
    {
        return [
            ['account_id' => $this->account('5140'), 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $this->account('1200'), 'debit' => 0, 'credit' => 100_000],
        ];
    }

    /**
     * Run `$edit` once, just before the next journal-lock query on `$draft`,
     * and collect every query that runs after it.
     *
     * @return Collection<int, string>
     */
    private function editAtTheJournalLock(AccountingJournal $draft, callable $edit): Collection
    {
        $state = (object) ['editing' => false, 'edited' => false];
        $after = collect();
        $this->editLanded = fn (): bool => $state->edited;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($state, $after, $draft, $edit): void {
            $sql = strtolower($query);

            if ($state->edited) {
                $after->push($sql);

                return;
            }

            if ($state->editing
                || ! str_starts_with($sql, 'select * from `accounting_journals`')
                || ! str_contains($sql, 'for update')
                || (int) ($bindings[0] ?? 0) !== $draft->id) {
                return;
            }

            $state->editing = true;
            $edit();
            $state->edited = true;
        });

        return $after;
    }

    private function versionOf(AccountingJournal $journal): int
    {
        return (int) DB::table('accounting_journals')->where('id', $journal->id)->value('edit_version');
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
