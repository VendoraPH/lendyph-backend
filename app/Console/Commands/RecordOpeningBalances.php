<?php

namespace App\Console\Commands;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Money;
use App\Services\AuditLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Signature('accounting:opening-balances
    {--file= : CSV of the balances being carried in — account code, debit, credit, in PESOS. Required.}
    {--as-of= : The calendar date the balances are as of, Y-m-d. The entry is dated this day. Required.}
    {--dry-run : Print the whole proposed entry, computed plug and all, and write nothing. Start here, every time.}
    {--force : Skip the interactive confirmation. Required for a non-interactive run.}')]
#[Description('Record the balances a cooperative arrives with as one posted journal, plugged to 3050 Opening Balance Equity — once, ever')]
class RecordOpeningBalances extends Command
{
    use ConfirmableTrait;

    /**
     * The journal source. Already in {@see AccountingJournal::SOURCES} and in
     * the column's enum since the module shipped, and until this command
     * existed nothing in the application ever wrote it.
     */
    private const SOURCE = 'opening_balance';

    /**
     * 3050 Opening Balance Equity — the named plug.
     *
     * Resolved BY CODE, deliberately. 3050 is not in
     * {@see ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES} and not in
     * `AccountingAccountMapping::ROLES`, because a posting role is something the
     * automatic engine asks for on every release and collection and an
     * organisation may re-point; this account is touched exactly once in the
     * life of a deployment. Inventing a role for it would put a permanently
     * re-pointable indirection in front of a one-shot entry.
     */
    private const PLUG_CODE = '3050';

    /** Recognised spellings of the three column headings, via self::labelKey(). */
    private const HEADER_LABELS = [
        0 => ['code', 'accountcode', 'account', 'accountno', 'acct', 'glcode'],
        1 => ['debit', 'debits', 'dr'],
        2 => ['credit', 'credits', 'cr'],
    ];

    /** `reference` is `string(64)`; a longer filename is truncated rather than refused. */
    private const MAX_REFERENCE = 64;

    /**
     * Record what a cooperative already had, so the books do not start at zero.
     *
     * ## The problem, concretely
     *
     * Seed the chart onto a co-op that is already trading and every account
     * starts at nil — but the co-op has ₱400,000 in the tin and ₱2.1M out on
     * loan. The first release posts a credit to 1010 Cash on Hand and the
     * balance goes NEGATIVE, because the cash it paid out was never on the
     * books to begin with. Nothing errors. The trial balance still balances;
     * it balances around a cash account reading minus ninety thousand pesos,
     * and every statement built on top of it is wrong from day one.
     *
     * This command is the fix: one journal, dated the day the co-op's figures
     * are as of, that puts those balances on the books before any automatic
     * posting can run against them.
     *
     * ## The plug, and why it is a named account
     *
     * An opening-balance file is NOT expected to balance, and that is the whole
     * reason 3050 exists. A co-op hands over its assets and its liabilities; it
     * does not hand over the equity figure that reconciles them, because the
     * equity figure IS the difference and nobody has computed it. So the
     * difference is computed here and posted to 3050 Opening Balance Equity —
     * a named account an accountant can see, question and later reclassify into
     * share capital, statutory funds and undivided net surplus. The alternative
     * is folding it into 3030 Retained Earnings, which hides it permanently in
     * the one account nobody ever reconciles. See the ChartOfAccountsSeeder
     * class docblock, which says the same thing from the other side.
     *
     * ## Once, ever
     *
     * The refusal on an existing `opening_balance` journal is the main safety
     * property of this command, not a convenience. A second run does not fail
     * loudly: it writes a second perfectly balanced journal, the trial balance
     * still balances, every statement is still internally consistent, and the
     * co-op's cash and portfolio are quietly doubled. There is no report that
     * catches that, and by the time anyone notices, months of real postings sit
     * on top of it. So the check runs before the preview AND again under a lock
     * inside the write transaction.
     *
     * ## Everything goes through JournalPoster
     *
     * Never a raw insert. The poster recomputes the totals from the persisted
     * lines, allocates the number, re-checks every account is still postable,
     * and honours the closed-period lock; the database's balanced-CHECK and the
     * posted-journal immutability guard sit underneath it. An opening balance
     * written around all of that would be the one entry in the books with no
     * proof it balances.
     */
    public function handle(ChartOfAccountsSeeder $chart, JournalPoster $poster): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $path = trim((string) $this->option('file'));

        if ($path === '') {
            return $this->refuse('--file is required.', [
                'Name the CSV holding the balances being carried in. Three columns:',
                '',
                '  account code, debit, credit      amounts in PESOS, one side per row',
                '',
                '  1010,400000.00,',
                '  1110,2100000.00,',
                '  2300,,150000.00',
                '',
                'Preview it first — the preview is the whole point of this command:',
                '',
                '  php artisan accounting:opening-balances --file=opening.csv --as-of=2026-08-31 --dry-run',
            ]);
        }

        if (! is_file($path) || ! is_readable($path)) {
            return $this->refuse("--file [{$path}] could not be opened for reading.", [
                'Check the path. A relative path is resolved against the working directory of',
                'the shell, which on a deployment is not necessarily the application root.',
            ]);
        }

        $asOf = $this->parseAsOf(trim((string) $this->option('as-of')));

        if ($asOf === null) {
            return self::FAILURE;
        }

        /*
         * The chart has to exist before balances can be hung off it, and the
         * failure without this check is not an error — it is EVERY account code
         * in the file coming back unknown, which reads like a bad file rather
         * than an un-set-up deployment and sends the operator to edit the CSV.
         */
        if (! $chart->hasChart()) {
            return $this->refuse('This deployment has no chart of accounts yet.', [
                'There is nothing to post opening balances against. Seed the chart first —',
                'the Chart of Accounts screen, or ChartOfAccountsSeeder — and note that the',
                'balances below are meant to go on BEFORE any loan release or collection is',
                'posted, so this is the right order to discover that in.',
            ]);
        }

        if (($already = $this->existingOpeningBalance()) !== null) {
            return $this->refuseSecondRun($already);
        }

        $rows = $this->readRows($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        $plan = $this->plan($rows);

        if ($plan === null) {
            return self::FAILURE;
        }

        $this->printHeader($path, $asOf, $dryRun);
        $this->printEntry($plan, $dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY RUN — nothing was written.');
            $this->line('Re-run without --dry-run to post it. Check the plug line above first: it is the');
            $this->line('figure nobody handed you, and it is the one that will be wrong if a balance was');
            $this->line('left out of the file.');

            return self::SUCCESS;
        }

        $this->announceFinality($plan);

        /*
         * `true` rather than the default callback, which only prompts when
         * APP_ENV is literally `production`. This is a ten-box fleet shipping
         * `APP_ENV=local` in `.env.example`, so an environment-conditional
         * prompt is absent on exactly the box whose env has drifted — and an
         * opening balance is once-ever on staging too. --force bypasses it for
         * a non-interactive run; under --no-interaction the prompt takes its
         * default, which is cancel.
         */
        if (! $this->confirmToProceed('Posting the opening balances', true)) {
            return self::FAILURE;
        }

        try {
            $journal = $this->post($poster, $plan, $asOf, $path);
        } catch (ValidationException $e) {
            /*
             * The poster's refusals arrive as a ValidationException, which on
             * the console would otherwise print a stack trace over the preview
             * the operator is still reading. Every one of them is a real
             * condition this command cannot pre-empt from here — a period
             * closed between the preview and the confirmation, an account
             * deactivated in another tab, a second operator posting first.
             */
            $this->newLine();
            $this->error('Refused by JournalPoster — nothing was written:');

            foreach ($e->errors() as $messages) {
                foreach ((array) $messages as $message) {
                    $this->line('  '.$message);
                }
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Posted %s — %s of opening balances as of %s.',
            $journal->journal_no,
            Money::format((int) $journal->total_debit),
            $asOf,
        ));
        $this->line('These now appear in the trial balance, the general ledger and every statement');
        $this->line('built on them, dated '.$asOf.'. Re-running this command will be refused.');

        if ($plan['plug'] !== null) {
            $this->newLine();
            $this->line(sprintf(
                'Hand %s Opening Balance Equity to the accountant — it holds %s.',
                self::PLUG_CODE,
                Money::format($plan['plug']['amount']),
            ));
            $this->line('That figure is a plug, not a classification. It belongs in share capital,');
            $this->line('statutory funds or undivided net surplus once somebody decides how it splits.');
        }

        return self::SUCCESS;
    }

    // ── Input ───────────────────────────────────────────────────────────────

    /**
     * `--as-of`, as a calendar date, or null having said why.
     *
     * Dated `--as-of` rather than today because the two are routinely weeks
     * apart: the co-op's figures are as of the last day of a month and the
     * migration happens when it happens. The date is also what makes the entry
     * visible — every report reads `date <= cutoff`, so an entry dated today
     * would be missing from a trial balance run as of the co-op's own year end.
     */
    private function parseAsOf(string $value): ?string
    {
        if ($value === '') {
            $this->refuse('--as-of is required.', [
                'The date the balances are as of, Y-m-d. The journal is dated this day, and that',
                'date decides which reports see it: every statement reads `date <= cutoff`, so an',
                'opening balance dated after the co-op\'s year end is absent from the year end.',
            ]);

            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value) {
            $this->refuse("--as-of [{$value}] is not a date in Y-m-d form.", [
                'Write it as 2026-08-31. Anything looser is guessed at differently by every',
                'parser, and the guess decides which month the co-op\'s whole opening position',
                'lands in.',
            ]);

            return null;
        }

        // config('app.timezone') is Asia/Manila, so this is local wall-clock
        // today rather than a UTC day that turns over at 08:00 here.
        if ($date->isAfter(Carbon::today())) {
            $this->refuse("--as-of [{$value}] is in the future.", [
                'Opening balances are what the co-op HAS, not what it expects to have. A future',
                'date also hides the entry from every report run before it, which looks exactly',
                'like the command not having run at all.',
            ]);

            return null;
        }

        return $value;
    }

    /**
     * The CSV, as a list of `['line' => int, 'code' => string, 'debit' => int, 'credit' => int]`
     * in centavos — or null having printed why not.
     *
     * Hand-rolled rather than routed through `App\Services\CsvImport`. That
     * package's reader is bound to `CsvImportSchema`, which declares itself
     * "the single declaration of what the TWO migration CSVs look like" with
     * labels copied verbatim from one client's workbook and ORDER IS CONTRACT
     * over them. A third shape in there would be a third feature's file in a
     * table whose whole discipline is that it describes that one workbook.
     *
     * What is worth borrowing from it is borrowed: the UTF-8 BOM strip (Excel
     * writes one on the first cell, and "\u{FEFF}1010" is a different account
     * code from "1010"), the explicit empty escape string fgetcsv needs on
     * PHP 8.4, and refusing a record of the wrong width outright rather than
     * reading whatever landed in position two.
     *
     * @return list<array{line: int, code: string, debit: int, credit: int}>|null
     */
    private function readRows(string $path): ?array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->refuse("--file [{$path}] could not be opened for reading.");

            return null;
        }

        $rows = [];
        $seen = [];
        $lineNo = 0;
        $skipped = [];
        $isFirstRecord = true;

        try {
            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $lineNo++;

                $cells = array_map(
                    static fn ($cell): string => trim((string) $cell),
                    $record,
                );

                // The first cell of the file, and only that one, can carry a
                // UTF-8 BOM — Excel writes one. Left in place, "\u{FEFF}1010"
                // is a DIFFERENT account code from "1010", so the row would be
                // reported as an account this deployment does not have.
                if ($isFirstRecord && $cells !== []) {
                    $cells[0] = $this->stripBom($cells[0]);
                }

                // Blank lines, and the row of bare commas a spreadsheet leaves
                // under the last one. fgetcsv spells the first of those [null].
                if (implode('', $cells) === '') {
                    continue;
                }

                if ($isFirstRecord) {
                    $isFirstRecord = false;

                    if ($this->looksLikeHeader($cells)) {
                        continue;
                    }
                }

                // Trailing empties are what a spreadsheet leaves behind when a
                // fourth column once held something; they are not a width
                // problem. Anything past that is a different file.
                while (count($cells) > 3 && end($cells) === '') {
                    array_pop($cells);
                }

                if (count($cells) !== 3) {
                    $this->refuse(sprintf('Line %d has %d column(s), not 3.', $lineNo, count($cells)), [
                        'Every row is: account code, debit, credit. A file of a different width is a',
                        'different report, or a column was inserted — and read positionally it would put',
                        'somebody\'s balance in the wrong column of the wrong account.',
                        '',
                        '  Got: '.implode(' | ', $cells),
                    ]);

                    return null;
                }

                [$code, $debitCell, $creditCell] = $cells;

                if ($code === '') {
                    $this->refuse(sprintf('Line %d has no account code.', $lineNo));

                    return null;
                }

                $debit = $this->centavos($debitCell, $lineNo, 'debit');
                $credit = $this->centavos($creditCell, $lineNo, 'credit');

                if ($debit === null || $credit === null) {
                    return null;
                }

                if ($debit > 0 && $credit > 0) {
                    $this->refuse(sprintf('Line %d has both a debit and a credit.', $lineNo), [
                        'A balance sits on one side or the other. Two figures on one row is either a',
                        'net that has not been taken, or a column that slipped — and the two are',
                        'indistinguishable from here.',
                    ]);

                    return null;
                }

                if ($debit === 0 && $credit === 0) {
                    // Unambiguous, unlike everything else refused above: an
                    // account with no balance contributes nothing, and the
                    // poster would refuse a line of zero on both sides anyway.
                    // Reported rather than silently dropped.
                    $skipped[] = $code;

                    continue;
                }

                if (isset($seen[$code])) {
                    $this->refuse(sprintf('Account %s appears twice — lines %d and %d.', $code, $seen[$code], $lineNo), [
                        'One balance per account. Two rows could mean "add them up" or could mean one of',
                        'them is a correction that was never deleted, and posting the wrong reading puts a',
                        'figure on the books that no report will ever question.',
                    ]);

                    return null;
                }

                $seen[$code] = $lineNo;
                $rows[] = ['line' => $lineNo, 'code' => $code, 'debit' => $debit, 'credit' => $credit];

                /*
                 * Stop reading rather than buffer a file that can never post.
                 *
                 * JournalPoster caps an entry at MAX_LINES so MAX_LINES *
                 * Money::maxCentavos() stays inside PHP_INT_MAX and the summed
                 * total is exact. A file past that is refused either way — the
                 * difference is whether this process reads all of it into
                 * memory first. PHP runs at memory_limit here, and a wrong file
                 * (a whole ledger export, say) is exactly the shape that
                 * exhausts it: an OOM kill mid-read is a worse failure than a
                 * refusal, because it has no message.
                 */
                if (count($rows) > JournalPoster::MAX_LINES) {
                    $this->refuse(
                        sprintf('The file holds more than %d balances.', JournalPoster::MAX_LINES),
                        [
                            'That is past what one journal entry can carry, so this cannot post however it',
                            'is split up here — and it is usually the wrong file rather than a very large',
                            'chart. A co-op chart of accounts is dozens of rows, not hundreds.',
                        ],
                    );

                    return null;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d account(s) carried no balance and were left out: %s',
                count($skipped),
                implode(', ', $skipped),
            ));
        }

        if ($rows === []) {
            $this->refuse('The file holds no balances.', [
                'Every row was blank, a heading, or zero on both sides. An entry that records',
                'nothing would still spend a journal number and would still make this command',
                'refuse to run a second time — which is the run that mattered.',
            ]);

            return null;
        }

        return $rows;
    }

    /**
     * One cell, in centavos, or null having said why not.
     *
     * {@see Money::toCentavos()} — the same parser {@see AutomaticPoster}
     * puts every peso figure through on its way from the lending tables
     * (`decimal:2` PESOS) into the accounting ones (integer CENTAVOS). It reads
     * a decimal string digit by digit rather than through a float, so a large
     * balance cannot lose its last centavo to binary rounding on the way in,
     * and it strips the peso sign, the thousands separators and the
     * non-breaking spaces a spreadsheet export leaves behind.
     */
    private function centavos(string $cell, int $lineNo, string $side): ?int
    {
        if ($cell === '') {
            return 0;
        }

        $centavos = Money::toCentavos($cell);

        if ($centavos === null) {
            $this->refuse(sprintf('Line %d: [%s] is not an amount I can read in the %s column.', $lineNo, $cell, $side), [
                'Amounts are positive pesos — 1500, 1500.50, "₱1,500.50" all read. A negative does',
                'not: direction belongs to the column, so a -1500 debit is a 1500 credit, and',
                'accepting both spellings would let two different-looking files post the same entry.',
            ]);

            return null;
        }

        return $centavos;
    }

    /** Whether the first record is column headings rather than a balance. */
    private function looksLikeHeader(array $cells): bool
    {
        foreach (self::HEADER_LABELS as $index => $labels) {
            if (in_array($this->labelKey($cells[$index] ?? ''), $labels, true)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercased, stripped of everything that is not a letter or a digit. */
    private function labelKey(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    // ── The entry ───────────────────────────────────────────────────────────

    /**
     * Resolve every code, compute the plug, and hand back the whole entry —
     * or null having printed exactly which rows are the problem.
     *
     * Problems are collected and reported TOGETHER rather than one per run.
     * This is a file somebody assembled by hand from a co-op's ledger; failing
     * on the first bad code means one more round trip per bad code, and the
     * operator only ever sees the shape of the mistake on the last one.
     *
     * @param  list<array{line: int, code: string, debit: int, credit: int}>  $rows
     * @return array{lines: list<array{code: string, name: string, account_id: int, debit: int, credit: int, origin: string}>, plug: array{account_id: int, amount: int, side: string}|null, total_debit: int, total_credit: int}|null
     */
    private function plan(array $rows): ?array
    {
        $codes = array_column($rows, 'code');

        $accounts = AccountingAccount::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        $unknown = [];
        $groups = [];
        $inactive = [];

        foreach ($rows as $row) {
            $account = $accounts->get($row['code']);

            if ($account === null) {
                $unknown[] = sprintf('line %d: %s', $row['line'], $row['code']);

                continue;
            }

            if ($account->is_group) {
                $groups[] = sprintf('line %d: %s %s', $row['line'], $account->code, $account->name);

                continue;
            }

            if (! $account->is_active) {
                $inactive[] = sprintf('line %d: %s %s', $row['line'], $account->code, $account->name);
            }
        }

        if ($unknown !== []) {
            return $this->refuseWithList(
                'No such account on this deployment.',
                $unknown,
                [
                    'The chart is a TEMPLATE — administrators add, rename and deactivate accounts, and a',
                    'co-op\'s equity section looks nothing like the seeded default. A code that is right on',
                    'one box may not exist on the next, so this is a file-against-deployment mismatch',
                    'rather than necessarily a typo. Check the Chart of Accounts screen on THIS box.',
                ],
            );
        }

        if ($groups !== []) {
            return $this->refuseWithList(
                'Heading accounts cannot take a balance.',
                $groups,
                [
                    'A heading\'s balance is the sum of the accounts beneath it, so a figure posted',
                    'against it is counted twice on the same statement — once as the posting and once',
                    'as the subtotal. Post to the sub-accounts and let the heading add them up.',
                    'JournalPoster refuses these too; catching them here means the whole list arrives',
                    'at once instead of one per run.',
                ],
            );
        }

        if ($inactive !== []) {
            return $this->refuseWithList(
                'Deactivated accounts cannot take new entries.',
                $inactive,
                [
                    'Somebody said these must take no new history. Reactivate the ones the co-op really',
                    'carries a balance on, or point the row at the account that replaced them.',
                ],
            );
        }

        $plugAccount = $accounts->get(self::PLUG_CODE)
            ?? AccountingAccount::query()->where('code', self::PLUG_CODE)->first();

        if ($plugAccount === null) {
            return $this->refuseWithList(
                'This chart has no '.self::PLUG_CODE.' Opening Balance Equity.',
                [],
                [
                    'It is in the seeded default and is what the difference between the assets and the',
                    'liabilities is posted to. Without it there is nowhere honest for that figure to go —',
                    'and folding it into 3030 Retained Earnings hides it permanently, in the one account',
                    'nobody ever reconciles. Add '.self::PLUG_CODE.' under 3000 Equity and re-run.',
                ],
            );
        }

        $lines = [];
        $fileDebit = 0;
        $fileCredit = 0;
        $fileNamesPlug = false;

        foreach ($rows as $row) {
            $account = $accounts->get($row['code']);

            $fileDebit += $row['debit'];
            $fileCredit += $row['credit'];
            $fileNamesPlug = $fileNamesPlug || $row['code'] === self::PLUG_CODE;

            $lines[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'account_id' => (int) $account->id,
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                'origin' => 'line '.$row['line'],
            ];
        }

        /*
         * The ceiling, applied here rather than left to the poster.
         *
         * Money::toCentavos() bounds what it will PARSE at 10^15 PESOS, which
         * is a DIFFERENT and higher bound than Money::maxCentavos() — the
         * largest centavo figure the module's arithmetic stays exact at. So a
         * cell can parse cleanly and still be past what the books can hold.
         * JournalPoster refuses it, but only inside the write transaction,
         * which is after the preview and after the operator has confirmed an
         * entry that was never going to post.
         */
        $oversized = [];

        foreach ($lines as $line) {
            if (max($line['debit'], $line['credit']) > Money::maxCentavos()) {
                $oversized[] = sprintf(
                    '%s: %s',
                    $line['origin'],
                    Money::format(max($line['debit'], $line['credit'])),
                );
            }
        }

        if ($oversized !== []) {
            return $this->refuseWithList(
                'An amount is beyond anything this system records.',
                $oversized,
                [
                    'Past '.Money::format(Money::maxCentavos()).' the arithmetic stops being exact, so a figure',
                    'that size is a typo or a unit error — pesos entered as centavos, most likely —',
                    'rather than a balance.',
                ],
            );
        }

        $difference = $fileDebit - $fileCredit;
        $plug = null;

        /*
         * THE ONE CASE THE PLUG IS NOT COMPUTED.
         *
         * The file normally lists assets and liabilities and does NOT balance —
         * the difference IS the co-op's opening equity, and computing it is the
         * entire feature. But a file that names 3050 itself has already stated
         * that figure, and there are only bad ways to reconcile the two: adding
         * a second 3050 line puts the account on the entry twice, and folding
         * the remainder into the operator's number silently overwrites the one
         * figure they went to the trouble of supplying.
         *
         * So a file that names the plug account is read as a COMPLETE entry and
         * is required to balance on its own. That is the only reading in which
         * the operator's 3050 figure survives unaltered, and an imbalance under
         * it is a real, findable mistake rather than something to absorb.
         */
        if ($fileNamesPlug) {
            if ($difference !== 0) {
                return $this->refuseWithList(
                    sprintf(
                        'Debits and credits differ by %s, and the file sets %s itself.',
                        Money::format(abs($difference)),
                        self::PLUG_CODE,
                    ),
                    [
                        'total debits : '.Money::format($fileDebit),
                        'total credits: '.Money::format($fileCredit),
                    ],
                    [
                        'A file that does not name '.self::PLUG_CODE.' need not balance — the difference is computed',
                        'and posted there as the opening equity, which is what that account is for. This',
                        'one names it, so it is taken as a finished entry and has to balance: absorbing',
                        'the difference would mean overwriting the '.self::PLUG_CODE.' figure you supplied, and there',
                        'is no way to tell that apart from the balance that was actually left out.',
                        '',
                        'Either drop the '.self::PLUG_CODE.' row and let it be computed, or find the missing '.Money::format(abs($difference)).'.',
                    ],
                );
            }
        } elseif ($difference !== 0) {
            // More debits than credits means the co-op arrives with more assets
            // than liabilities, and the excess is its equity — a CREDIT. The
            // other direction is a co-op that arrives owing more than it holds,
            // which is a real condition and is posted as a debit rather than
            // clamped or refused.
            $plug = [
                'account_id' => (int) $plugAccount->id,
                'amount' => abs($difference),
                'side' => $difference > 0 ? 'credit' : 'debit',
            ];

            $lines[] = [
                'code' => (string) $plugAccount->code,
                'name' => (string) $plugAccount->name,
                'account_id' => (int) $plugAccount->id,
                'debit' => $difference > 0 ? 0 : abs($difference),
                'credit' => $difference > 0 ? abs($difference) : 0,
                'origin' => 'COMPUTED PLUG',
            ];
        }

        $totalDebit = Money::sum(array_column($lines, 'debit'));
        $totalCredit = Money::sum(array_column($lines, 'credit'));

        // Belt and braces over the arithmetic above. The poster checks this
        // too, and the database's CHECK constraint checks it under that — but
        // an assertion that fires here names the plug as the suspect, where one
        // that fires three layers down names a journal the operator never saw.
        if (! Money::isBalanced($totalDebit, $totalCredit)) {
            return $this->refuseWithList(
                sprintf('The assembled entry is out by %s.', Money::format(abs($totalDebit - $totalCredit))),
                [
                    'total debits : '.Money::format($totalDebit),
                    'total credits: '.Money::format($totalCredit),
                ],
                ['This is a bug in the plug arithmetic, not in the file. Nothing was written.'],
            );
        }

        // The other half of the ceiling: many individually legal rows whose SUM
        // runs past it. The per-line scan above cannot see this one.
        if ($totalDebit > Money::maxCentavos()) {
            return $this->refuseWithList(
                'The entry totals '.Money::format($totalDebit).', which is beyond anything this system records.',
                [],
                [
                    'Past '.Money::format(Money::maxCentavos()).' the summed total stops being exact, and a header',
                    'that disagrees with its own lines is the one error no report joins the two to',
                    'catch. Check the units — this is what a file in centavos rather than pesos does.',
                ],
            );
        }

        // The exact bound, now that the plug is on. Checked here rather than
        // left to the poster so it is refused BEFORE the preview and the
        // confirmation, instead of after the operator has approved it.
        if (count($lines) > JournalPoster::MAX_LINES) {
            return $this->refuseWithList(
                sprintf('The entry would have %d lines; a journal entry may carry %d.', count($lines), JournalPoster::MAX_LINES),
                [],
                [
                    'The cap is arithmetic, not policy: MAX_LINES * Money::maxCentavos() has to stay',
                    'inside PHP_INT_MAX or the summed total stops being exact, and a header that',
                    'disagrees with its own lines is the one error no report joins the two to catch.',
                ],
            );
        }

        if (count($lines) < 2) {
            return $this->refuseWithList(
                'A journal entry needs at least two lines.',
                [],
                [
                    'One balance on its own cannot post: there is nothing for it to be the other side',
                    'of. A single asset row would normally pick up a computed '.self::PLUG_CODE.' line and post as a',
                    'pair, so getting here means the row nets to nothing.',
                ],
            );
        }

        return [
            'lines' => $lines,
            'plug' => $plug,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ];
    }

    /**
     * Draft, post and record it — all inside one transaction.
     *
     * @param  array{lines: list<array<string, mixed>>, plug: array{account_id: int, amount: int, side: string}|null, total_debit: int, total_credit: int}  $plan
     */
    private function post(JournalPoster $poster, array $plan, string $asOf, string $path): AccountingJournal
    {
        return DB::transaction(function () use ($poster, $plan, $asOf, $path): AccountingJournal {
            /*
             * THE SAME CHECK AS AT THE TOP, AND IT IS NOT REDUNDANT.
             *
             * `source` carries no unique index — it cannot, since every other
             * source is written thousands of times — so nothing in the database
             * stops a second opening balance. The check above answers the
             * ordinary case (an operator re-running the command); this one,
             * under a lock taken inside the write transaction, answers the case
             * that check cannot: two operators on two shells, both past the
             * preview, both about to post. Without it they both read "none
             * yet", both write, and the co-op's entire opening position is
             * doubled in books that still balance.
             */
            $racing = AccountingJournal::query()
                ->where('source', self::SOURCE)
                ->lockForUpdate()
                ->first();

            if ($racing !== null) {
                throw ValidationException::withMessages([
                    'source' => [
                        'An opening balance was posted while this run was waiting at the confirmation'
                        .' prompt ('.($racing->journal_no ?: 'draft #'.$racing->id).'). Nothing was written.',
                    ],
                ]);
            }

            $journal = $poster->postImmediately(
                [
                    'date' => $asOf,
                    'source' => self::SOURCE,
                    'reference' => substr(basename($path), 0, self::MAX_REFERENCE),
                    'description' => 'Opening balances as of '.$asOf,
                    // NULL on purpose. Opening balances belong to the
                    // organisation, not to one of its branches — this is a
                    // single-tenant-per-deployment application and the figures
                    // arrive as one co-op's position. A branch-filtered trial
                    // balance therefore does not show them, which is correct:
                    // there is no honest branch to attribute the co-op's
                    // founding cash to, and picking one would overstate it.
                    'branch_id' => null,
                ],
                array_map(static fn (array $line): array => [
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['origin'] === 'COMPUTED PLUG'
                        ? 'Computed plug — difference between the assets and liabilities carried in'
                        : 'Opening balance',
                ], $plan['lines']),
                // No user id: this runs on the console, where auth() resolves
                // to nobody. The audit row below is the attribution, and says
                // so honestly rather than claiming an operator it cannot verify.
                null,
            );

            AuditLogService::log(
                'accounting_opening_balances_posted',
                auditable: $journal,
                newValues: [
                    'journal_no' => $journal->journal_no,
                    'as_of' => $asOf,
                    'source_file' => basename($path),
                    'lines' => count($plan['lines']),
                    'total_debit' => $plan['total_debit'],
                    'total_credit' => $plan['total_credit'],
                    'plug_account' => self::PLUG_CODE,
                    'plug_amount' => $plan['plug']['amount'] ?? 0,
                    'plug_side' => $plan['plug']['side'] ?? null,
                    // Ten deployments share this code. "Which box was this?" is
                    // the question a later fleet-wide audit has to answer, and
                    // the one the private-files rollout learned the hard way.
                    'deployment' => config('app.name').' ('.config('app.env').') @ '.gethostname(),
                ],
                description: sprintf(
                    'accounting:opening-balances posted %s carrying %s onto the books as of %s',
                    $journal->journal_no,
                    Money::format($plan['total_debit']),
                    $asOf,
                ),
                // Not request()->ip(). Laravel synthesises a console request
                // whose default makes that the literal 127.0.0.1, so the row
                // would claim the loopback address of whichever box ran it.
                ipAddress: 'console',
            );

            return $journal;
        });
    }

    /** The opening balance already on the books, in any status, if there is one. */
    private function existingOpeningBalance(): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('source', self::SOURCE)
            ->orderBy('id')
            ->first();
    }

    // ── Output ──────────────────────────────────────────────────────────────

    private function printHeader(string $path, string $asOf, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun ? '=== DRY RUN — no writes ===' : '=== LIVE RUN — this writes ===');

        // now() is Asia/Manila (config/app.php), so this is local wall-clock
        // time rather than a UTC instant that reads as yesterday before 08:00.
        $this->line(sprintf('  When   : %s %s', now()->format('Y-m-d H:i:s'), config('app.timezone')));
        $this->line(sprintf('  Where  : %s (%s)', config('app.name'), config('app.env')));
        $this->line(sprintf('  File   : %s', $path));
        $this->line(sprintf('  As of  : %s — the entry is dated this day', $asOf));
    }

    /**
     * The complete proposed entry, every line of it, before anything is written.
     *
     * The whole command is this table. An opening balance is one journal an
     * operator gets one attempt at, assembled from a spreadsheet nobody here
     * can check against the co-op's real ledger — so the only review that can
     * happen is a human reading the entry back. Printing a summary, or only the
     * plug, would hide the row that matters: the account that is NOT on the
     * list because it was left out of the file, which is exactly the omission
     * the plug figure silently absorbs.
     *
     * Future tense in both modes, and deliberately: this is printed before the
     * confirmation and before the transaction, so a past-tense heading would be
     * a lie on every run that is then cancelled at the prompt — and the run
     * most likely to be cancelled is the one where the table held a surprise.
     *
     * @param  array{lines: list<array<string, mixed>>, plug: array{account_id: int, amount: int, side: string}|null, total_debit: int, total_credit: int}  $plan
     */
    private function printEntry(array $plan, bool $dryRun): void
    {
        $rows = [];

        foreach ($plan['lines'] as $line) {
            $rows[] = [
                $line['code'],
                $line['name'],
                $line['debit'] > 0 ? Money::format($line['debit']) : '',
                $line['credit'] > 0 ? Money::format($line['credit']) : '',
                $line['origin'],
            ];
        }

        $rows[] = ['', 'TOTAL', Money::format($plan['total_debit']), Money::format($plan['total_credit']), ''];

        $this->newLine();
        $this->line($dryRun ? '  Would post this entry:' : '  About to post this entry:');
        $this->newLine();
        $this->table(['Code', 'Account', 'Debit', 'Credit', 'From'], $rows);

        if ($plan['plug'] === null) {
            $this->line('  The file balances on its own — no '.self::PLUG_CODE.' plug was computed.');

            return;
        }

        $this->line(sprintf(
            '  PLUG: %s to %s Opening Balance Equity.',
            Money::format($plan['plug']['amount']),
            self::PLUG_CODE,
        ));
        $this->line($plan['plug']['side'] === 'credit'
            ? '  The co-op arrives holding more than it owes; the difference is its opening equity.'
            : '  The co-op arrives owing more than it holds; the difference is a deficit. Worth a second'
                .PHP_EOL.'  look — it is also what a missing asset row looks like.');
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, plug: array{account_id: int, amount: int, side: string}|null, total_debit: int, total_credit: int}  $plan
     */
    private function announceFinality(array $plan): void
    {
        $this->newLine();
        $this->warn('  THIS HAPPENS ONCE');
        $this->line(sprintf(
            '  %s of opening balances across %d account(s) goes onto the books, and this',
            Money::format($plan['total_debit']),
            count($plan['lines']),
        ));
        $this->line('  command will refuse to run again on this deployment. A posted journal cannot be');
        $this->line('  edited or deleted — correcting it means a reversing entry, which leaves both');
        $this->line('  halves on the record for good.');
        $this->line('  Read the table above once more. A balance left out of the file does not show up');
        $this->line('  as an error; it shows up as a plug figure that looks plausible.');
    }

    /**
     * Say no, say why, and say what to do instead.
     *
     * @param  list<string>  $detail
     */
    private function refuse(string $headline, array $detail = []): int
    {
        $this->newLine();
        $this->error('Refusing to run: '.$headline);

        if ($detail !== []) {
            $this->newLine();

            foreach ($detail as $line) {
                $this->line($line === '' ? '' : '  '.$line);
            }
        }

        return self::FAILURE;
    }

    /**
     * The same, with the offending rows listed in between.
     *
     * @param  list<string>  $items
     * @param  list<string>  $detail
     */
    private function refuseWithList(string $headline, array $items, array $detail = []): null
    {
        $this->newLine();
        $this->error('Refusing to run: '.$headline);

        if ($items !== []) {
            $this->newLine();

            foreach ($items as $item) {
                $this->line('  '.$item);
            }
        }

        if ($detail !== []) {
            $this->newLine();

            foreach ($detail as $line) {
                $this->line($line === '' ? '' : '  '.$line);
            }
        }

        return null;
    }

    /** The refusal this command mostly exists for. */
    private function refuseSecondRun(AccountingJournal $journal): int
    {
        $this->newLine();
        $this->error('Refusing to run: this deployment already has an opening balance.');
        $this->newLine();
        $this->line(sprintf(
            '  %s  dated %s  %s  %s',
            $journal->journal_no ?: 'draft #'.$journal->id,
            $journal->date instanceof \DateTimeInterface
                ? $journal->date->format('Y-m-d')
                : substr((string) $journal->date, 0, 10),
            Money::format((int) $journal->total_debit),
            strtoupper((string) $journal->status),
        ));
        $this->newLine();
        $this->line('  Opening balances go on once, ever. A second run would not fail — it would write a');
        $this->line('  second perfectly balanced journal, the trial balance would still balance, every');
        $this->line('  statement would still be internally consistent, and the co-op\'s cash and');
        $this->line('  portfolio would quietly be double what they are. No report catches that.');
        $this->newLine();
        $this->line('  If the entry above is wrong, reverse it through the app — that leaves both halves');
        $this->line('  on the record — and post the correction as an ordinary adjusting entry. Do not');
        $this->line('  reach for this command again.');

        return self::FAILURE;
    }
}
