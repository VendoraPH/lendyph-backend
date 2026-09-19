<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AuditLog;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Console\Command;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * `php artisan accounting:opening-balances`.
 *
 * What a co-op arrives with, put on the books as one journal before any
 * automatic posting can run against accounts that all read zero.
 *
 * Almost nothing here fails loudly if it is wrong, which is why these are tests
 * rather than review notes. A second run writes a second perfectly balanced
 * journal and doubles the portfolio in books that still balance. A file with a
 * row left out posts a plug figure that looks entirely plausible. A group
 * account takes a balance that is then counted twice on the same statement. In
 * every case the trial balance reports healthy books.
 *
 * The last spec is the one that proves the feature rather than the row: the
 * balances have to turn up in TrialBalanceBuilder, because "wrote a journal" and
 * "the books now say the co-op has ₱400,000 in cash" are different claims.
 */
class AccountingOpeningBalancesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const COMMAND = 'accounting:opening-balances';

    private const AS_OF = '2026-08-31';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * A CSV on disk, cleaned up after the test.
     *
     * A real file rather than a fake disk: the command takes a filesystem path
     * an operator types at a shell, so anything that stubs the filesystem would
     * be testing a code path the operator never takes.
     */
    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'openbal').'.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Run the command and hand back everything it printed.
     *
     * The buffer rather than `$this->artisan()->expectsOutputToContain()`,
     * following RequirePasswordChangeCommandTest: those expectations are
     * ordered and one-shot per write, so a phrase that legitimately appears
     * twice is consumed by whichever assertion came first.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runCommand(array $parameters, int $expectedExit = Command::SUCCESS): string
    {
        // --force by default. confirmToProceed() is unconditional rather than
        // production-only, so an un-forced live run would sit on a prompt and
        // take its default — cancel — in every spec that is about something
        // else.
        $exitCode = Artisan::call(self::COMMAND, $parameters + ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame($expectedExit, $exitCode, "Unexpected exit code. Output was:\n{$output}");

        return $output;
    }

    /**
     * The canonical file: a co-op with cash and a portfolio, and one liability.
     *
     * It does NOT balance, on purpose — ₱2,500,000 of debits against ₱150,000
     * of credits. That is what an opening-balance file looks like: the co-op
     * hands over its assets and its liabilities, and the equity figure that
     * reconciles them is the one nobody has computed. Computing it is the
     * feature under test.
     *
     * Carries a header row, so the header is exercised by every spec that uses
     * it rather than by one of its own.
     */
    private function canonicalCsv(): string
    {
        return $this->csv(<<<'CSV'
        Account Code,Debit,Credit
        1010,400000.00,
        1110,2100000.00,
        2300,,150000.00
        CSV);
    }

    private function journalLines(AccountingJournal $journal): array
    {
        return AccountingJournalLine::query()
            ->where('accounting_journal_id', $journal->id)
            ->join('accounting_accounts', 'accounting_accounts.id', '=', 'accounting_journal_lines.accounting_account_id')
            ->orderBy('accounting_journal_lines.line_no')
            ->get(['accounting_accounts.code', 'accounting_journal_lines.debit', 'accounting_journal_lines.credit'])
            ->mapWithKeys(static fn ($line): array => [
                (string) $line->code => ['debit' => (int) $line->debit, 'credit' => (int) $line->credit],
            ])
            ->all();
    }

    // ── The entry it writes ─────────────────────────────────────────────────

    public function test_a_balanced_entry_is_posted_with_the_opening_balance_source_and_a_computed_plug(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $this->assertSame(1, AccountingJournal::query()->count(), 'Exactly one journal, and it is this one.');

        $journal = AccountingJournal::query()->sole();

        // The source nothing in this application had ever written until now.
        $this->assertSame('opening_balance', $journal->source);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('JE-000001', $journal->journal_no);
        $this->assertSame(self::AS_OF, $journal->date->format('Y-m-d'));

        // ₱2,500,000 of debits, ₱150,000 of credits, so ₱2,350,000 of equity.
        // Centavos throughout — the accounting tables are integers, and the
        // lending tables' decimal:2 pesos are a different unit entirely.
        $this->assertSame(250_000_000, (int) $journal->total_debit);
        $this->assertSame(250_000_000, (int) $journal->total_credit);

        $this->assertSame([
            '1010' => ['debit' => 40_000_000, 'credit' => 0],
            '1110' => ['debit' => 210_000_000, 'credit' => 0],
            '2300' => ['debit' => 0, 'credit' => 15_000_000],
            '3050' => ['debit' => 0, 'credit' => 235_000_000],
        ], $this->journalLines($journal));
    }

    public function test_the_plug_is_a_debit_when_the_cooperative_arrives_owing_more_than_it_holds(): void
    {
        // A real condition, not an error: a co-op whose liabilities exceed its
        // assets has a deficit, and it is posted as a debit to 3050 rather than
        // clamped, refused, or quietly flipped to the side that looks better.
        $file = $this->csv("1010,50000.00,\n2300,,120000.00\n");

        $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF]);

        $this->assertSame([
            '1010' => ['debit' => 5_000_000, 'credit' => 0],
            '2300' => ['debit' => 0, 'credit' => 12_000_000],
            '3050' => ['debit' => 7_000_000, 'credit' => 0],
        ], $this->journalLines(AccountingJournal::query()->sole()));
    }

    public function test_the_plug_line_is_described_as_computed_so_the_ledger_says_where_it_came_from(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $plug = AccountingJournalLine::query()
            ->where('accounting_account_id', $this->account('3050'))
            ->sole();

        $this->assertStringContainsString('Computed plug', (string) $plug->description);
    }

    public function test_the_whole_proposed_entry_is_printed_before_anything_is_written(): void
    {
        $output = $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF, '--dry-run' => true]);

        // Every line, not a summary. The row that matters on an opening-balance
        // review is the one that is NOT in the table because it was left out of
        // the file, and only the full entry lets a human notice it.
        foreach (['1010', '1110', '2300', '3050'] as $code) {
            $this->assertStringContainsString($code, $output);
        }

        $this->assertStringContainsString('2,350,000.00', $output, 'The computed plug is shown as a figure.');
        $this->assertStringContainsString('PLUG', $output);
    }

    // ── Once, ever ──────────────────────────────────────────────────────────

    public function test_a_second_run_is_refused(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $output = $this->runCommand(
            ['--file' => $this->canonicalCsv(), '--as-of' => '2026-09-15'],
            Command::FAILURE,
        );

        $this->assertStringContainsString('already has an opening balance', $output);
        $this->assertStringContainsString('JE-000001', $output, 'It names the entry that is in the way.');

        // The point of the refusal: a second balanced journal would double the
        // co-op's cash and portfolio in books that still balance, and no report
        // catches that.
        $this->assertSame(1, AccountingJournal::query()->count());
    }

    public function test_a_second_run_is_refused_even_after_the_first_entry_is_reversed(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        AccountingJournal::query()->sole()->forceFill(['status' => 'reversed'])->saveQuietly();

        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF], Command::FAILURE);
    }

    public function test_an_unrelated_journal_does_not_block_the_run(): void
    {
        // The guard keys on `source`, not on "are there journals". A co-op that
        // has posted an expense before anyone got to the opening balances must
        // still be able to run this.
        $this->postSimpleJournal('5030', '1010', 350000);

        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $this->assertSame(1, AccountingJournal::query()->where('source', 'opening_balance')->count());
    }

    // ── What it refuses ─────────────────────────────────────────────────────

    public function test_an_unseeded_chart_is_refused(): void
    {
        // Mappings first: their FK to accounts is restrictOnDelete. Accounts in
        // descending id order so a child is always gone before its parent, for
        // the same reason.
        AccountingAccountMapping::query()->delete();
        AccountingAccount::query()->orderByDesc('id')->get()->each->delete();

        $output = $this->runCommand(
            ['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF],
            Command::FAILURE,
        );

        // Named as what it is. Without this check the failure is every code in
        // the file coming back unknown, which reads like a bad CSV and sends
        // the operator off to edit it.
        $this->assertStringContainsString('no chart of accounts', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_an_unknown_account_code_is_refused(): void
    {
        $file = $this->csv("1010,400000.00,\n9999,120000.00,\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('No such account', $output);
        $this->assertStringContainsString('9999', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_every_unknown_code_is_listed_rather_than_only_the_first(): void
    {
        // One round trip per bad code means the operator only ever sees the
        // shape of the mistake on the last one.
        $file = $this->csv("9998,10.00,\n9999,20.00,\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('9998', $output);
        $this->assertStringContainsString('9999', $output);
    }

    public function test_a_group_account_is_refused(): void
    {
        // 1000 Assets is a heading. Its balance is the sum of the accounts
        // beneath it, so a figure posted against it is counted twice on the
        // same statement.
        $file = $this->csv("1000,400000.00,\n2300,,150000.00\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('Heading accounts cannot take a balance', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_the_synthetic_current_year_earnings_heading_is_refused_too(): void
    {
        // 3040 is a group in this chart on purpose — statements.ts derives that
        // line from the period's net income, so anything posted to the real
        // account is counted twice. It is the heading an opening-balance file is
        // most likely to reach for by mistake.
        $file = $this->csv("1010,400000.00,\n3040,,400000.00\n");

        $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_deactivated_account_is_refused(): void
    {
        AccountingAccount::query()->where('code', '1010')->update(['is_active' => false]);

        $output = $this->runCommand(
            ['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF],
            Command::FAILURE,
        );

        $this->assertStringContainsString('Deactivated accounts', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_an_unbalanced_file_is_refused_when_it_sets_the_plug_account_itself(): void
    {
        /*
         * A file that does NOT name 3050 is not expected to balance — the
         * difference is the co-op's opening equity and is computed. This one
         * names it, so it has already stated that figure and is read as a
         * finished entry: absorbing the ₱100,000 difference would mean silently
         * overwriting the one number the operator went to the trouble of
         * supplying, and that is indistinguishable from the balance they left
         * out.
         */
        $file = $this->csv("1010,400000.00,\n2300,,150000.00\n3050,,150000.00\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('Debits and credits differ by', $output);
        $this->assertStringContainsString('100,000.00', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_file_that_sets_the_plug_account_itself_and_balances_posts_exactly_as_given(): void
    {
        $file = $this->csv("1010,400000.00,\n2300,,150000.00\n3050,,250000.00\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF]);

        $this->assertStringContainsString('no 3050 plug was computed', $output);

        // Three lines, not four: the operator's 3050 figure stands, and nothing
        // was added beside it.
        $this->assertSame([
            '1010' => ['debit' => 40_000_000, 'credit' => 0],
            '2300' => ['debit' => 0, 'credit' => 15_000_000],
            '3050' => ['debit' => 0, 'credit' => 25_000_000],
        ], $this->journalLines(AccountingJournal::query()->sole()));
    }

    public function test_a_row_with_both_a_debit_and_a_credit_is_refused(): void
    {
        $file = $this->csv("1010,400000.00,50000.00\n2300,,150000.00\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('both a debit and a credit', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_the_same_account_twice_is_refused_rather_than_added_up(): void
    {
        // Two rows could mean "total them" or could mean one is a correction
        // nobody deleted. Guessing puts a figure on the books that no report
        // will ever question.
        $file = $this->csv("1010,400000.00,\n1010,25000.00,\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('appears twice', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_negative_amount_is_refused(): void
    {
        // Direction belongs to the column: a -1500 debit is a 1500 credit, and
        // accepting both spellings would let two different-looking files post
        // the same entry.
        $file = $this->csv("1010,-400000.00,\n2300,,150000.00\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('not an amount I can read', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function decimalCommaProvider(): array
    {
        return [
            'european, one group' => ['1.500,50'],
            'decimal comma, no grouping' => ['10,50'],
            'space-grouped european' => ['1 500,50'],
            'centavos after a comma' => ['400000,00'],
        ];
    }

    #[DataProvider('decimalCommaProvider')]
    public function test_a_comma_used_as_a_decimal_point_is_refused_rather_than_misread(string $amount): void
    {
        /*
         * The nastiest input this command takes, because it is not rejected by
         * the money parser — it is silently reinterpreted. Money::toCentavos()
         * strips commas as thousands separators before parsing, so "10,50"
         * posts ₱1,050.00 and "1.500,50" posts ₱1.50, and the residual vanishes
         * into the computed plug looking entirely plausible.
         */
        // QUOTED, because a decimal comma is also a field separator — which is
        // how a spreadsheet writes such a cell, and the only way it reaches the
        // amount parser rather than the width check.
        $file = $this->csv('1010,"'.$amount.'",'.PHP_EOL.'2300,,150000.00'.PHP_EOL);

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('comma as a decimal point', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_ordinary_grouped_and_peso_signed_amounts_still_post(): void
    {
        // The other half of the rule above: the way the app itself formats
        // money has to keep working, or the guard has cost more than it saved.
        // A quoted cell with a peso sign and a thousands separator — what
        // Intl.NumberFormat and Money::format() both emit.
        $file = $this->csv(
            '1010,"₱1,500.50",'.PHP_EOL
            .'2300,,"1,000.50"'.PHP_EOL,
        );

        $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF]);

        $this->assertSame([
            '1010' => ['debit' => 150_050, 'credit' => 0],
            '2300' => ['debit' => 0, 'credit' => 100_050],
            '3050' => ['debit' => 0, 'credit' => 50_000],
        ], $this->journalLines(AccountingJournal::query()->sole()));
    }

    public function test_a_zero_valued_plug_row_still_counts_as_the_file_setting_the_plug(): void
    {
        /*
         * "3050,,0" is an operator stating there is no opening equity. The row
         * carries no balance, so it never becomes a journal line — but if that
         * made it invisible, the difference would be computed and posted to
         * 3050 anyway, overwriting the very thing they said. It has to take the
         * "the file sets the plug, so it must balance" path.
         */
        $file = $this->csv("1010,400000.00,\n2300,,150000.00\n3050,,0\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('Debits and credits differ by', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_an_oversized_file_is_refused_before_it_is_opened(): void
    {
        // fgetcsv() has no per-record length bound, so a file with no line
        // breaks is read whole in one call — before the row cap can see it.
        $this->runCommand(
            ['--file' => $this->csv(str_repeat('1010,1.00,x', 200_000)), '--as-of' => self::AS_OF],
            Command::FAILURE,
        );
    }

    public function test_a_long_multibyte_filename_does_not_abort_the_post(): void
    {
        /*
         * `reference` is VARCHAR(64) — sixty-four CHARACTERS. Cutting bytes
         * leaves a dangling lead byte that MySQL rejects outright under
         * STRICT_TRANS_TABLES, and it would do so after the operator had
         * confirmed, blaming the data for something the filename did.
         */
        $path = sys_get_temp_dir().'/'.str_repeat('ñ', 80).'.csv';
        file_put_contents($path, "1010,400000.00,\n2300,,150000.00\n");
        $this->tempFiles[] = $path;

        $this->runCommand(['--file' => $path, '--as-of' => self::AS_OF]);

        $this->assertSame(64, mb_strlen((string) AccountingJournal::query()->sole()->reference));
    }

    public function test_a_file_of_the_wrong_width_is_refused_rather_than_read_positionally(): void
    {
        // Four cells with something in the last one. A TRAILING EMPTY is
        // tolerated on purpose — a spreadsheet leaves one behind when a fourth
        // column once held something — so the fixture has to be genuinely wide.
        $file = $this->csv("1010,Cash on Hand,400000.00,0\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('not 3', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_an_amount_past_what_the_books_can_hold_is_refused_before_the_confirmation(): void
    {
        /*
         * Money::toCentavos() parses up to 10^15 PESOS, which is a higher bound
         * than Money::maxCentavos() — the largest centavo figure the module's
         * arithmetic stays exact at. So this parses cleanly and is still past
         * what the books can hold. JournalPoster would refuse it too, but only
         * inside the write transaction: after the preview, and after an
         * operator has confirmed an entry that was never going to post.
         */
        $file = $this->csv("1010,99999999999999,\n2300,,150000.00\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('beyond anything this system records', $output);
        $this->assertStringNotContainsString('About to post this entry', $output, 'Refused before the preview.');
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_file_past_the_journal_line_cap_is_refused_before_it_is_all_read(): void
    {
        // JournalPoster::MAX_LINES is 500, so an entry cannot carry more. The
        // refusal is here rather than three layers down because the file is
        // read into memory on the way, and an OOM kill mid-read is a worse
        // failure than a message — it has no message.
        $rows = '';

        // DISTINCT codes, so the duplicate-code guard does not fire first. They
        // do not have to exist in the chart: the cap is enforced by the reader,
        // which refuses on record 501 — before the resolver ever sees them.
        for ($i = 0; $i < JournalPoster::MAX_LINES + 50; $i++) {
            $rows .= sprintf("9%03d,1.00,\n", $i);
        }

        $output = $this->runCommand(['--file' => $this->csv($rows), '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('more than '.JournalPoster::MAX_LINES.' balances', $output);
        $this->assertStringNotContainsString('No such account', $output, 'Refused while reading, not after resolving.');
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->runCommand(
            ['--file' => sys_get_temp_dir().'/there-is-no-such-file.csv', '--as-of' => self::AS_OF],
            Command::FAILURE,
        );
    }

    public function test_an_as_of_that_is_not_a_date_is_refused(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => '31/08/2026'], Command::FAILURE);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_a_file_with_no_balances_is_refused(): void
    {
        // Every row zero on both sides. An entry that records nothing would
        // still spend a journal number and would still make the once-only guard
        // refuse the run that mattered.
        $file = $this->csv("Account Code,Debit,Credit\n1010,0,0\n2300,,\n");

        $output = $this->runCommand(['--file' => $file, '--as-of' => self::AS_OF], Command::FAILURE);

        $this->assertStringContainsString('no balances', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    // ── Two operators at once ───────────────────────────────────────────────

    public function test_an_opening_balance_that_lands_mid_transaction_is_refused_by_the_in_transaction_guard(): void
    {
        /*
         * The pre-flight check in handle() cannot see this one: the journal
         * appears AFTER it ran and before this run posts. In production that is
         * two operators on two shells; here it is an insert hung off the
         * command's own transaction opening, which puts the row in exactly the
         * same place in the sequence.
         *
         * This is the path the row lock on 3050 exists to make reachable. Lock
         * the `source` index gap instead and two real runs deadlock rather than
         * refuse — and under READ COMMITTED they do not even do that, they both
         * commit.
         */
        $injected = false;

        Event::listen(TransactionBeginning::class, function () use (&$injected): void {
            if ($injected) {
                return;
            }

            $injected = true;

            DB::table('accounting_journals')->insert([
                'journal_no' => 'JE-000999',
                'date' => self::AS_OF,
                'source' => 'opening_balance',
                'description' => 'Posted by another operator a moment ago',
                'status' => 'posted',
                'total_debit' => 100,
                'total_credit' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $output = $this->runCommand(
            ['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF],
            Command::FAILURE,
        );

        $this->assertTrue($injected, 'The race was actually simulated.');
        $this->assertStringContainsString('Another run posted an opening balance', $output);

        // Rolled back with the refused transaction, injected row and all — so
        // the assertion that matters is that this run wrote nothing of its own.
        $this->assertSame(0, AccountingJournalLine::query()->count());
    }

    // ── --dry-run ───────────────────────────────────────────────────────────

    public function test_dry_run_writes_nothing(): void
    {
        $output = $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF, '--dry-run' => true]);

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertSame(0, AccountingJournal::query()->count());
        $this->assertSame(0, AccountingJournalLine::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'accounting_opening_balances_posted')->count());
    }

    public function test_a_dry_run_does_not_use_up_the_one_run(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF, '--dry-run' => true]);

        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $this->assertSame(1, AccountingJournal::query()->count());
    }

    public function test_a_live_run_without_force_is_cancelled_by_the_confirmation(): void
    {
        // --no-interaction makes the prompt take its default, which is cancel.
        // The gate is unconditional rather than production-only: this fleet
        // ships APP_ENV=local, so an environment-conditional prompt would be
        // absent on exactly the box whose env has drifted.
        $exitCode = Artisan::call(self::COMMAND, [
            '--file' => $this->canonicalCsv(),
            '--as-of' => self::AS_OF,
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    // ── The part that proves it worked ──────────────────────────────────────

    public function test_the_balances_appear_in_the_trial_balance(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $trialBalance = app(TrialBalanceBuilder::class)->build(self::AS_OF);

        $byCode = collect($trialBalance['rows'])->keyBy('account_code');

        // Assets land in the debit column, the liability and the plug in the
        // credit column, and the two columns agree — which is the claim
        // "wrote a journal row" does not make on its own.
        $this->assertSame(40_000_000, $byCode['1010']['debit']);
        $this->assertSame(210_000_000, $byCode['1110']['debit']);
        $this->assertSame(15_000_000, $byCode['2300']['credit']);
        $this->assertSame(235_000_000, $byCode['3050']['credit']);

        $this->assertSame(250_000_000, $trialBalance['total_debit']);
        $this->assertSame(250_000_000, $trialBalance['total_credit']);
        $this->assertTrue($trialBalance['is_balanced']);

        // TrialBalanceBuilder needed no change to see these: it filters on
        // status and date and never on source, so a source nothing had ever
        // written is included the moment something writes it.
        $this->assertSame(
            250_000_000,
            app(TrialBalanceBuilder::class)->build('2026-12-31')['total_debit'],
            'Still there at a later cutoff.',
        );
    }

    public function test_the_entry_is_absent_from_a_trial_balance_run_before_the_as_of_date(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        // `date <= cutoff`, so --as-of is not cosmetic: it decides which
        // statements see the co-op's whole opening position.
        $this->assertSame([], app(TrialBalanceBuilder::class)->build('2026-08-30')['rows']);
    }

    public function test_opening_cash_keeps_a_later_release_from_driving_the_account_negative(): void
    {
        /*
         * The problem this command exists for, end to end.
         *
         * Seed the chart onto a co-op that is already trading and 1010 reads
         * zero. Post a release and the cash it paid out — cash it really had —
         * takes the account negative. Nothing errors; the books still balance
         * around a cash balance that is wrong.
         */
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $this->postSimpleJournal('1110', '1010', 30_000_000, ['date' => '2026-09-01']);

        $balances = app(TrialBalanceBuilder::class)->signedBalances('2026-09-30');

        // ₱400,000 carried in, ₱300,000 released: ₱100,000 left, not minus
        // ₱300,000.
        $this->assertSame(10_000_000, $balances[$this->account('1010')]);
    }

    // ── The trail ───────────────────────────────────────────────────────────

    public function test_the_run_is_recorded_in_the_audit_log(): void
    {
        $this->runCommand(['--file' => $this->canonicalCsv(), '--as-of' => self::AS_OF]);

        $entry = AuditLog::query()->where('action', 'accounting_opening_balances_posted')->sole();

        $this->assertSame(235_000_000, $entry->new_values['plug_amount']);
        $this->assertSame('credit', $entry->new_values['plug_side']);
        $this->assertSame(self::AS_OF, $entry->new_values['as_of']);

        // Ten deployments share this code and this is a once-per-box act, so
        // "which box was this?" has to be answerable later.
        // From the journal, not from the plan: for a once-ever entry the trail
        // should hold what actually landed.
        $journal = AccountingJournal::query()->sole();
        $this->assertSame((int) $journal->total_debit, $entry->new_values['total_debit']);
        $this->assertSame((int) $journal->total_credit, $entry->new_values['total_credit']);

        // DeploymentIdentity, the same terms /api/health and the fleet drift
        // check already describe a box in — and no server hostname in a row
        // GET /audit-logs hands to anyone holding the view permission.
        $this->assertArrayHasKey('deployment', $entry->new_values);
        $this->assertArrayHasKey('env', $entry->new_values['deployment']);
        $this->assertArrayHasKey('branch', $entry->new_values['deployment']);
        $this->assertSame('console', $entry->ip_address);
    }
}
