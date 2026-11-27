<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The money review's follow-ups on C2: closed months after `as_of`, deleting
 * an account on the draft, the saved audit row's lines, a required
 * edit_version, finalise after the CLI, and the blocking reasons for a chart
 * that can't post the entry.
 */
class OpeningBalanceReviewFollowUpsTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    private const LATER_CLOSED = 'Months after Aug 31, 2026 are already closed. Reopen them before recording opening balances.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();
    }

    // ── Closed months after as_of ───────────────────────────────────────────

    public function test_a_save_dated_before_a_closed_month_is_refused(): void
    {
        $this->closeMonth('2026-10-01');

        $this->save($this->unbalancedLines())
            ->assertStatus(422)
            ->assertJsonPath('errors.as_of.0', self::LATER_CLOSED);
    }

    public function test_a_closed_month_before_as_of_does_not_block(): void
    {
        $this->closeMonth('2026-07-01');

        $this->save($this->balancedLines())->assertOk()->assertJsonPath('data.blocking_reasons', []);
    }

    public function test_a_finalise_dated_before_a_closed_month_is_refused_and_the_preview_says_why(): void
    {
        $version = $this->saveBalancedDraft();
        $this->closeMonth('2026-09-01');

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.blocking_reasons', [self::LATER_CLOSED])
            ->assertJsonPath('data.can_finalise', false);

        $this->finalise($version)->assertStatus(422)->assertJsonPath('errors.as_of.0', self::LATER_CLOSED);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_the_cli_refuses_an_as_of_before_a_closed_month(): void
    {
        $this->closeMonth('2026-09-01');
        $path = tempnam(sys_get_temp_dir(), 'openbal').'.csv';
        file_put_contents($path, "1010,100.00,\n2300,,50.00\n");

        try {
            $exit = Artisan::call('accounting:opening-balances', ['--file' => $path, '--as-of' => self::AS_OF, '--force' => true]);
        } finally {
            unlink($path);
        }

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString(self::LATER_CLOSED, Artisan::output());
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    // ── Deleting an account on the draft ────────────────────────────────────

    public function test_deleting_an_account_on_the_draft_answers_422_in_words(): void
    {
        $this->save([
            ['account_id' => $this->account('1420'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 100],
        ])->assertOk();

        $this->deleteJson('/api/accounting/accounts/'.$this->account('1420'))
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', '1420 Computer Equipment is on the opening balance draft. Remove it there first.');
        $this->assertTrue(AccountingAccount::query()->whereKey($this->account('1420'))->exists());
    }

    public function test_an_account_not_on_the_draft_still_deletes(): void
    {
        $this->save($this->unbalancedLines())->assertOk();

        $this->deleteJson('/api/accounting/accounts/'.$this->account('1420'))->assertOk();
    }

    // ── The saved audit row ─────────────────────────────────────────────────

    public function test_the_saved_audit_row_lists_each_line_so_a_move_between_accounts_shows(): void
    {
        $this->save([
            ['account_id' => $this->account('1010'), 'debit' => 10_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 10_000],
        ])->assertOk();
        $this->save([
            ['account_id' => $this->account('1020'), 'debit' => 10_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 10_000],
        ], 1)->assertOk();

        $audit = AuditLog::query()->where('action', 'accounting_opening_balance_saved')->sole();

        $this->assertSame([
            ['account' => '1010', 'debit_centavos' => 10_000, 'credit_centavos' => 0],
            ['account' => '3050', 'debit_centavos' => 0, 'credit_centavos' => 10_000],
        ], $audit->old_values['lines']);
        $this->assertSame([
            ['account' => '1020', 'debit_centavos' => 10_000, 'credit_centavos' => 0],
            ['account' => '3050', 'debit_centavos' => 0, 'credit_centavos' => 10_000],
        ], $audit->new_values['lines']);
    }

    // ── edit_version ────────────────────────────────────────────────────────

    public function test_edit_version_is_required_once_a_draft_exists(): void
    {
        $this->save($this->unbalancedLines())->assertOk();

        $this->save($this->balancedLines())->assertStatus(422)->assertJsonValidationErrors(['edit_version']);
        $this->getJson(self::URL)->assertJsonCount(3, 'data.lines');
    }

    // ── Finalise after the CLI ──────────────────────────────────────────────

    public function test_finalise_with_no_draft_after_the_cli_posted_answers_409(): void
    {
        $journal = $this->postSimpleJournal('1010', '3050', 10_000, ['source' => 'opening_balance', 'branch_id' => null]);

        $this->finalise(0)->assertStatus(409)
            ->assertJsonPath('message', "An opening balance has already been posted ({$journal->journal_no}).");
    }

    public function test_a_second_finalise_names_the_journal(): void
    {
        $version = $this->saveBalancedDraft();
        $this->finalise($version)->assertOk();
        $journalNo = AccountingJournal::query()->sole()->journal_no;

        $this->finalise($version + 1)->assertStatus(409)
            ->assertJsonPath('message', "The opening balances have already been finalised ({$journalNo}).");
    }

    // ── Blocking reasons for a chart that can't post it ─────────────────────

    public function test_a_chart_with_no_3050_is_a_blocking_reason(): void
    {
        $this->save([
            ['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('2300'), 'debit' => 0, 'credit' => 100],
        ])->assertOk();
        AccountingAccount::query()->whereKey($this->account('3050'))->delete();

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.blocking_reasons', ['The chart has no 3050 Opening Balance Equity. Add it under 3000 Equity first.'])
            ->assertJsonPath('data.can_finalise', false);
    }

    public function test_a_line_whose_account_was_deactivated_or_made_a_heading_is_a_blocking_reason(): void
    {
        $this->saveBalancedDraft();
        AccountingAccount::query()->whereKey($this->account('2300'))->update(['is_active' => false]);
        AccountingAccount::query()->whereKey($this->account('1010'))->update(['is_group' => true]);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.blocking_reasons', [
                '1010 Cash on Hand is a heading. Move its balance to the accounts under it.',
                '2300 Other Liabilities is deactivated. Reactivate it or move its balance to another account.',
            ])
            ->assertJsonPath('data.can_finalise', false);
    }
}
