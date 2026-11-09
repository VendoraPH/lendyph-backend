<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;
use App\Models\AuditLog;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * POST /accounting/opening-balances/finalise: the draft posts once, as one
 * journal, only when it balances, and never next to the CLI's.
 */
class OpeningBalanceFinaliseTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();
    }

    public function test_a_balanced_draft_posts_once_as_one_opening_balance_journal(): void
    {
        $version = $this->saveBalancedDraft();

        $response = $this->finalise($version)->assertOk();

        $journal = AccountingJournal::query()->where('source', 'opening_balance')->sole();
        $draft = AccountingOpeningBalance::query()->sole();

        $response->assertJsonPath('data.status', 'finalised')
            ->assertJsonPath('data.journal', ['id' => $journal->id, 'journal_no' => $journal->journal_no])
            ->assertJsonPath('data.finalised_by', ['id' => $this->admin->id, 'name' => $this->admin->full_name])
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_finalise', false);
        $this->assertNotNull($response->json('data.finalised_at'));

        $this->assertSame('posted', $journal->status);
        $this->assertNull($journal->branch_id);
        $this->assertSame(self::AS_OF, $journal->date->toDateString());
        $this->assertSame('Opening balances', $journal->reference);
        $this->assertSame('Opening balances as of Aug 31, 2026', $journal->description);
        $this->assertSame($draft->getMorphClass(), $journal->postable_type);
        $this->assertSame($draft->id, $journal->postable_id);
        $this->assertSame(250_000_000, $journal->total_debit);
        $this->assertSame(250_000_000, $journal->total_credit);

        $posted = $journal->lines()->orderBy('accounting_account_id')->get(['accounting_account_id', 'debit', 'credit'])
            ->map(fn ($line): array => [(int) $line->accounting_account_id, (int) $line->debit, (int) $line->credit])->all();
        $drafted = $draft->lines()->orderBy('accounting_account_id')->get(['accounting_account_id', 'debit', 'credit'])
            ->map(fn ($line): array => [(int) $line->accounting_account_id, (int) $line->debit, (int) $line->credit])->all();
        $this->assertSame($drafted, $posted);

        $this->assertSame('finalised', $draft->status);
        $this->assertSame($journal->id, $draft->accounting_journal_id);
        $this->assertSame($this->admin->id, $draft->finalised_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'accounting_opening_balance_finalised')
            ->where('auditable_type', $draft->getMorphClass())->where('auditable_id', $draft->id)->count());
    }

    public function test_the_trial_balance_goes_up_by_the_lines(): void
    {
        $this->finalise($this->saveBalancedDraft())->assertOk();

        $byCode = collect(app(TrialBalanceBuilder::class)->build(self::AS_OF)['rows'])->keyBy('account_code');

        $this->assertSame(40_000_000, $byCode['1010']['debit']);
        $this->assertSame(210_000_000, $byCode['1110']['debit']);
        $this->assertSame(15_000_000, $byCode['2300']['credit']);
        $this->assertSame(235_000_000, $byCode['3050']['credit']);
    }

    public function test_an_unbalanced_draft_is_refused_with_nothing_posted(): void
    {
        $version = (int) $this->save($this->unbalancedLines())->assertOk()->json('data.edit_version');

        $this->finalise($version)->assertStatus(422)->assertJsonValidationErrors(['lines']);

        $this->assertSame(0, AccountingJournal::query()->count());
        $this->assertSame('draft', AccountingOpeningBalance::query()->sole()->status);
    }

    public function test_one_line_is_refused(): void
    {
        $version = (int) $this->save([['account_id' => $this->account('1010'), 'debit' => 100, 'credit' => 0]])->json('data.edit_version');

        $this->finalise($version)->assertStatus(422);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_nothing_saved_is_refused(): void
    {
        $this->finalise(0)->assertStatus(422);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_second_finalise_answers_409(): void
    {
        $version = $this->saveBalancedDraft();
        $this->finalise($version)->assertOk();

        $this->finalise($version)->assertStatus(409);
        $this->finalise($version + 1)->assertStatus(409);
        $this->assertSame(1, AccountingJournal::query()->count());
    }

    public function test_a_changed_version_answers_409(): void
    {
        $version = $this->saveBalancedDraft();
        $this->save($this->unbalancedLines(), $version)->assertOk();
        $this->save($this->balancedLines(), $version + 1)->assertOk();

        $this->finalise($version)->assertStatus(409)
            ->assertJsonPath('message', 'Someone else changed the opening balances; reload and try again.');
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_after_the_cli_posted_finalise_answers_409_and_reads_posted_elsewhere(): void
    {
        $version = $this->saveBalancedDraft();
        $this->postSimpleJournal('1010', '3050', 10_000, ['source' => 'opening_balance', 'branch_id' => null]);

        $this->finalise($version)->assertStatus(409);

        $this->assertSame(1, AccountingJournal::query()->count());
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.status', 'posted_elsewhere');
    }

    public function test_after_the_screen_posted_the_cli_refuses_with_its_own_message(): void
    {
        $this->finalise($this->saveBalancedDraft())->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'openbal').'.csv';
        file_put_contents($path, "1010,100.00,\n2300,,100.00\n");

        try {
            $exit = Artisan::call('accounting:opening-balances', ['--file' => $path, '--as-of' => self::AS_OF, '--force' => true]);
        } finally {
            unlink($path);
        }

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('already has an opening balance', Artisan::output());
        $this->assertSame(1, AccountingJournal::query()->count());
    }

    public function test_a_closed_period_answers_422(): void
    {
        $version = $this->saveBalancedDraft();
        $this->closeMonth(self::AS_OF);

        $this->finalise($version)->assertStatus(422);
        $this->assertSame(0, AccountingJournal::query()->count());
    }
}
