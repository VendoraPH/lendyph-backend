<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Money;
use App\Services\Accounting\PostingRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * What the release rule would post today, against what the books hold.
 *
 * {@see PostingRules::loanRelease()} books each deduction by its type. Journals
 * posted before that are left exactly as they are — a posted entry is never
 * rewritten, and correcting one is a reversal an accountant decides on, not a
 * side effect of a deploy. This command is how that decision gets its facts:
 * for every POSTED `loan_release` journal it rebuilds, from the loan's current
 * figures and today's account mappings, the posting a release would produce
 * now ({@see AutomaticPoster::releasePosting()}, the real conversion and the
 * real rule), and compares the two account by account.
 *
 * ## It only ever reads
 *
 * Without `--dry-run` it refuses and exits non-zero, so nobody runs it
 * expecting a fix and walks away believing the books were corrected. With it,
 * every statement is a SELECT: no transaction is opened, and
 * {@see JournalPoster} is never called.
 *
 * ## What it prints
 *
 * - every account on a journal whose posted debit/credit totals differ from
 *   what would be posted, with both figures and the difference;
 * - every journal it could not rebuild, and why — a loan whose figures no
 *   longer reconcile, a role with no account, a loan that no longer exists;
 * - the deduction types found on those loans, how many loans carry each and
 *   for how much, and whether each has an account mapping;
 * - the counts, and the total absolute difference in pesos.
 *
 * Journals are read in chunks by id with their lines eager-loaded and their
 * loans fetched in one query per chunk, so the cost is three queries per
 * {@see self::CHUNK} journals however large the book.
 */
#[Signature('accounting:loan-release-diff {--dry-run : Required. Preview the comparison; this command never changes a journal}')]
#[Description('Compare every posted loan release journal with what the release rule would post today. Read only')]
class DiffLoanReleaseJournals extends Command
{
    private const CHUNK = 500;

    private const NOTHING = '—';

    private int $scanned = 0;

    private int $differing = 0;

    private int $absoluteDifference = 0;

    /** @var list<list<string>> */
    private array $differences = [];

    /** @var list<list<string>> */
    private array $skipped = [];

    /** @var array<string, array{loans: int, amount: int, role: string|null}> */
    private array $types = [];

    private int $unitemisedLoans = 0;

    private int $unitemisedAmount = 0;

    /** @var array<int, string> */
    private array $accountLabels = [];

    public function handle(AutomaticPoster $poster): int
    {
        if (! $this->option('dry-run')) {
            $this->error(
                'accounting:loan-release-diff only previews. It never changes a journal: a posted entry is '
                .'corrected by a reversal, never rewritten, and nothing here writes one.'
            );
            $this->line('Run it again with --dry-run to see the comparison.');

            return self::FAILURE;
        }

        $this->info('Dry run — read only. No journal, journal line or loan is written.');

        if (! AccountMap::chartExists()) {
            $this->line('No chart of accounts: this organisation keeps no books, so there are no release journals to compare.');

            return self::SUCCESS;
        }

        $this->line("Rebuilt from each loan's current figures with today's release rule and today's account mappings.");
        $this->newLine();

        $map = AccountMap::resolve();

        $this->accountLabels = AccountingAccount::query()
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(static fn (AccountingAccount $account): array => [(int) $account->id => "{$account->code} {$account->name}"])
            ->all();

        AccountingJournal::query()
            ->where('source', 'loan_release')
            ->where('status', 'posted')
            ->select(['id', 'journal_no', 'postable_type', 'postable_id'])
            ->with('lines:id,accounting_journal_id,accounting_account_id,debit,credit')
            ->chunkById(self::CHUNK, function (EloquentCollection $journals) use ($poster, $map): void {
                $loans = Loan::query()
                    ->whereIn('id', $journals->where('postable_type', Loan::class)->pluck('postable_id')->all())
                    ->get(['id', 'loan_account_number', 'application_number', 'principal_amount', 'net_proceeds', 'total_deductions', 'deductions'])
                    ->keyBy('id');

                foreach ($journals as $journal) {
                    $loan = $journal->postable_type === Loan::class ? $loans->get($journal->postable_id) : null;

                    $this->compare($journal, $loan, $poster, $map);
                }
            });

        $this->report($map);

        return self::SUCCESS;
    }

    /** One journal against the posting its loan would produce today. */
    private function compare(AccountingJournal $journal, ?Loan $loan, AutomaticPoster $poster, AccountMap $map): void
    {
        $this->scanned++;

        if ($loan === null) {
            $this->skipped[] = [(string) $journal->journal_no, self::NOTHING, 'The loan this journal was posted for no longer exists.'];

            return;
        }

        $reference = (string) ($loan->loan_account_number ?? $loan->application_number ?? self::NOTHING);

        try {
            $this->countTypes($poster->releaseDeductions($loan));
            $posting = $poster->releasePosting($loan, $map);
        } catch (CannotPostToTheBooksException $refused) {
            $this->skipped[] = [(string) $journal->journal_no, $reference, $refused->getMessage()];

            return;
        }

        $posted = $this->totalsByAccount($journal->lines->map(static fn ($line): array => [
            'account_id' => (int) $line->accounting_account_id,
            'debit' => (int) $line->debit,
            'credit' => (int) $line->credit,
        ])->all());
        $wouldBe = $this->totalsByAccount($posting['lines']);

        $differs = false;

        foreach (array_keys($posted + $wouldBe) as $accountId) {
            $was = $posted[$accountId] ?? ['debit' => 0, 'credit' => 0];
            $now = $wouldBe[$accountId] ?? ['debit' => 0, 'credit' => 0];

            if ($was === $now) {
                continue;
            }

            $differs = true;
            $this->absoluteDifference += abs($now['debit'] - $was['debit']) + abs($now['credit'] - $was['credit']);

            $this->differences[] = [
                (string) $journal->journal_no,
                $reference,
                $this->accountLabels[$accountId] ?? "account #{$accountId}",
                $this->sides($was),
                $this->sides($now),
                $this->net(($now['debit'] - $now['credit']) - ($was['debit'] - $was['credit'])),
            ];
        }

        if ($differs) {
            $this->differing++;
        }
    }

    /**
     * @param  array{types: array<string, array{amount: int, role: string|null}>, remainder: int}  $deductions
     */
    private function countTypes(array $deductions): void
    {
        foreach ($deductions['types'] as $type => $found) {
            $this->types[$type] = [
                'loans' => ($this->types[$type]['loans'] ?? 0) + 1,
                'amount' => ($this->types[$type]['amount'] ?? 0) + $found['amount'],
                'role' => $found['role'],
            ];
        }

        if ($deductions['remainder'] !== 0) {
            $this->unitemisedLoans++;
            $this->unitemisedAmount += $deductions['remainder'];
        }
    }

    /**
     * @param  iterable<array{account_id: int, debit: int, credit: int}>  $lines
     * @return array<int, array{debit: int, credit: int}>
     */
    private function totalsByAccount(iterable $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $id = $line['account_id'];
            $totals[$id] = [
                'debit' => ($totals[$id]['debit'] ?? 0) + $line['debit'],
                'credit' => ($totals[$id]['credit'] ?? 0) + $line['credit'],
            ];
        }

        return $totals;
    }

    private function report(AccountMap $map): void
    {
        $this->line('Journals that would differ');

        if ($this->differences === []) {
            $this->line('  No posted release journal differs from what the rule would post today.');
        } else {
            $this->table(['Journal', 'Loan', 'Account', 'Posted', 'Would be', 'Difference'], $this->differences);
        }

        $this->newLine();
        $this->line('Journals skipped');

        if ($this->skipped === []) {
            $this->line('  None.');
        } else {
            $this->table(['Journal', 'Loan', 'Why'], $this->skipped);
        }

        $this->newLine();
        $this->line('Deduction types on these loans');

        if ($this->types === []) {
            $this->line('  None.');
        } else {
            $types = $this->types;
            uksort($types, static fn (string $a, string $b): int => [$types[$b]['loans'], $a] <=> [$types[$a]['loans'], $b]);

            $this->table(['Deduction type', 'Loans', 'Total', 'Account mapping'], array_map(
                fn (string $type, array $found): array => [
                    $type === '' ? '(no name)' : $type,
                    number_format($found['loans']),
                    Money::format($found['amount']),
                    $this->mappingOf($found['role'], $map),
                ],
                array_keys($types),
                $types,
            ));
        }

        $this->line(sprintf(
            '  Not itemised (total deductions minus the items): %s loan(s), %s — booked to %s as before.',
            number_format($this->unitemisedLoans),
            Money::format($this->unitemisedAmount),
            PostingRules::UNMAPPED_DEDUCTION_ROLE,
        ));

        $this->newLine();
        $this->line('Summary');

        foreach ([
            'Journals scanned:' => number_format($this->scanned),
            'Would differ:' => number_format($this->differing),
            'Matching:' => number_format($this->scanned - $this->differing - count($this->skipped)),
            'Skipped:' => number_format(count($this->skipped)),
            'Total absolute difference:' => Money::format($this->absoluteDifference),
        ] as $label => $value) {
            $this->line(sprintf('  %-28s %s', $label, $value));
        }
    }

    /** "processing_fee_income → 4030 Loan Processing Fee Income", or why there is none. */
    private function mappingOf(?string $role, AccountMap $map): string
    {
        if ($role === null) {
            return 'none — booked to '.PostingRules::UNMAPPED_DEDUCTION_ROLE.' as before'
                .$this->accountOf(PostingRules::UNMAPPED_DEDUCTION_ROLE, $map, ' (%s)');
        }

        return $map->has($role)
            ? $role.$this->accountOf($role, $map, ' → %s')
            : "{$role} → not set in Default Accounts";
    }

    private function accountOf(string $role, AccountMap $map, string $format): string
    {
        if (! $map->has($role)) {
            return '';
        }

        $id = $map->accountFor($role);

        return sprintf($format, $this->accountLabels[$id] ?? "account #{$id}");
    }

    /** @param  array{debit: int, credit: int}  $amounts */
    private function sides(array $amounts): string
    {
        $sides = array_filter([
            $amounts['debit'] !== 0 ? 'Dr '.Money::format($amounts['debit']) : null,
            $amounts['credit'] !== 0 ? 'Cr '.Money::format($amounts['credit']) : null,
        ]);

        return $sides === [] ? self::NOTHING : implode(' / ', $sides);
    }

    /** A signed debit-minus-credit figure, said the way a bookkeeper says it. */
    private function net(int $centavos): string
    {
        return match (true) {
            $centavos > 0 => 'Dr '.Money::format($centavos),
            $centavos < 0 => 'Cr '.Money::format(-$centavos),
            default => Money::format(0).' net',
        };
    }
}
