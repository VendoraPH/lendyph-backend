<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\Loan;
use App\Services\Accounting\Money;
use App\Services\ShareCapitalReleaseCredit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * The released loans whose withheld share capital never reached the member's
 * share capital ledger.
 *
 * A release has credited the member's ledger with the share capital its
 * deductions withhold since ShareCapitalReleaseCredit; releases before that
 * booked the money to the Share Capital equity account and left the member's
 * ledger, statement and the Share Capital report without it. Those past
 * releases are deliberately left alone. This command lists them, so the owner
 * can decide what to do with each from the facts.
 *
 * ## It only ever reads
 *
 * Every statement is a SELECT: no transaction is opened and nothing is
 * written, so it is safe to run on production as it is. It is a report, not
 * a fix, and it has no mode that changes anything.
 *
 * ## What it counts
 *
 * Every loan in {@see Loan::EVER_RELEASED_STATUSES} whose deductions carry a
 * share capital item, by the same reading the release uses
 * ({@see ShareCapitalReleaseCredit::amountFor()}, which is the release
 * journal's own classification), and that has no ledger row carrying its
 * `loan_id`. For each: the loan account number, its status, the member, and
 * the amount, in centavos summed exactly; then the count and total by status
 * and overall. A loan whose deductions cannot be read the way the release
 * journal reads them is listed apart, with the reason, and is in no total.
 *
 * Loans migrated in by the CSV importer (Loan::isImported()) are reported in
 * a section of their own, with their own counts and totals, and are in none
 * of the released-here figures. They were released under the cooperative's
 * old books, not through this application's release, and the share capital
 * their deductions name may already be in the member's imported ledger
 * balance, so they are listed to be checked, never counted as missing.
 *
 * Loans are read in chunks by id, with the ledger rows of each chunk fetched
 * in one query, so the cost is two queries per chunk however many loans
 * there are.
 */
#[Signature('share-capital:release-deductions-report')]
#[Description('List released loans that withheld share capital with no share capital ledger credit for it. Read only')]
class ReportShareCapitalReleaseDeductions extends Command
{
    private const CHUNK = 500;

    private const RELEASED_HERE = 'released';

    private const IMPORTED = 'imported';

    /**
     * Per section (RELEASED_HERE, IMPORTED): the loans, by status, the count
     * and the total.
     *
     * @var array<string, array{rows: list<list<string>>, by_status: array<string, array{loans: int, amount: int}>, count: int, total: int}>
     */
    private array $sections = [
        self::RELEASED_HERE => ['rows' => [], 'by_status' => [], 'count' => 0, 'total' => 0],
        self::IMPORTED => ['rows' => [], 'by_status' => [], 'count' => 0, 'total' => 0],
    ];

    /** @var list<list<string>> */
    private array $unreadable = [];

    public function handle(ShareCapitalReleaseCredit $credits): int
    {
        $this->info('Read only. Nothing is written.');
        $this->newLine();

        Loan::query()
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->with('borrower:id,borrower_code')
            ->select(['id', 'borrower_id', 'status', 'loan_account_number', 'application_number', 'external_loan_no', 'imported_arrears_baseline', 'total_deductions', 'deductions'])
            ->chunkById(self::CHUNK, function (EloquentCollection $loans) use ($credits): void {
                $candidates = $loans->filter(fn (Loan $loan): bool => $credits->withholdsShareCapital($loan));

                if ($candidates->isEmpty()) {
                    return;
                }

                $credited = DB::table('share_capital_ledger')
                    ->whereIn('loan_id', $candidates->modelKeys())
                    ->pluck('loan_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->flip();

                foreach ($candidates as $loan) {
                    if (! $credited->has($loan->getKey())) {
                        $this->count($loan, $credits);
                    }
                }
            });

        $this->report();

        return self::SUCCESS;
    }

    private function count(Loan $loan, ShareCapitalReleaseCredit $credits): void
    {
        $reference = (string) ($loan->loan_account_number ?? $loan->application_number);
        $section = $loan->isImported() ? self::IMPORTED : self::RELEASED_HERE;

        try {
            $amount = $credits->amountFor($loan);
        } catch (CannotPostToTheBooksException $unreadable) {
            $this->unreadable[] = [$reference, $loan->status, $section === self::IMPORTED ? 'imported' : 'released here', $unreadable->getMessage()];

            return;
        }

        if ($amount === 0) {
            return;
        }

        $this->sections[$section]['count']++;
        $this->sections[$section]['total'] += $amount;
        $this->sections[$section]['by_status'][$loan->status] = [
            'loans' => ($this->sections[$section]['by_status'][$loan->status]['loans'] ?? 0) + 1,
            'amount' => ($this->sections[$section]['by_status'][$loan->status]['amount'] ?? 0) + $amount,
        ];
        $this->sections[$section]['rows'][] = [$reference, $loan->status, (string) ($loan->borrower?->borrower_code ?? '—'), Money::format($amount)];
    }

    private function report(): void
    {
        $this->line('Loans released in this application whose withheld share capital has no ledger credit');
        $this->section(self::RELEASED_HERE);

        $this->newLine();
        $this->line('Imported (CSV) loans with share capital deductions and no release credit');
        $this->line('  Released under the old books: their share capital may already be in the member\'s imported ledger balance. Check each one; none is counted as missing.');
        $this->section(self::IMPORTED);

        $this->newLine();
        $this->line('Could not be read');

        if ($this->unreadable === []) {
            $this->line('  None.');
        } else {
            $this->table(['Loan', 'Status', 'Released', 'Why'], $this->unreadable);
        }

        $this->newLine();
        $this->line($this->summary('Missing release credits', self::RELEASED_HERE));
        $this->line($this->summary('Imported loans to check', self::IMPORTED));
    }

    /** One section's loans, then its totals by status. */
    private function section(string $section): void
    {
        $found = $this->sections[$section];

        if ($found['rows'] === []) {
            $this->line('  None.');

            return;
        }

        $this->table(['Loan', 'Status', 'Member', 'Share capital withheld'], $found['rows']);

        $statuses = array_values(array_filter(Loan::EVER_RELEASED_STATUSES, fn (string $status): bool => isset($found['by_status'][$status])));

        $this->table(['Status', 'Loans', 'Total'], array_map(fn (string $status): array => [
            $status,
            number_format($found['by_status'][$status]['loans']),
            Money::format($found['by_status'][$status]['amount']),
        ], $statuses));
    }

    private function summary(string $label, string $section): string
    {
        $count = $this->sections[$section]['count'];

        return sprintf(
            '%s: %s %s, %s',
            $label,
            number_format($count),
            $count === 1 ? 'loan' : 'loans',
            Money::format($this->sections[$section]['total']),
        );
    }
}
