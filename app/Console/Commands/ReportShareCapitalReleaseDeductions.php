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
 * Loans are read in chunks by id, with the ledger rows of each chunk fetched
 * in one query, so the cost is two queries per chunk however many loans
 * there are.
 */
#[Signature('share-capital:release-deductions-report')]
#[Description('List released loans that withheld share capital with no share capital ledger credit for it. Read only')]
class ReportShareCapitalReleaseDeductions extends Command
{
    private const CHUNK = 500;

    /** @var list<list<string>> */
    private array $missing = [];

    /** @var array<string, array{loans: int, amount: int}> */
    private array $byStatus = [];

    /** @var list<list<string>> */
    private array $unreadable = [];

    private int $missingCount = 0;

    private int $missingTotal = 0;

    public function handle(ShareCapitalReleaseCredit $credits): int
    {
        $this->info('Read only. Nothing is written.');
        $this->newLine();

        Loan::query()
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->with('borrower:id,borrower_code')
            ->select(['id', 'borrower_id', 'status', 'loan_account_number', 'application_number', 'total_deductions', 'deductions'])
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

        try {
            $amount = $credits->amountFor($loan);
        } catch (CannotPostToTheBooksException $unreadable) {
            $this->unreadable[] = [$reference, $loan->status, $unreadable->getMessage()];

            return;
        }

        if ($amount === 0) {
            return;
        }

        $this->missingCount++;
        $this->missingTotal += $amount;
        $this->byStatus[$loan->status] = [
            'loans' => ($this->byStatus[$loan->status]['loans'] ?? 0) + 1,
            'amount' => ($this->byStatus[$loan->status]['amount'] ?? 0) + $amount,
        ];
        $this->missing[] = [$reference, $loan->status, (string) ($loan->borrower?->borrower_code ?? '—'), Money::format($amount)];
    }

    private function report(): void
    {
        $this->line('Released loans whose withheld share capital has no ledger credit');

        if ($this->missing === []) {
            $this->line('  None.');
        } else {
            $this->table(['Loan', 'Status', 'Member', 'Share capital withheld'], $this->missing);
        }

        $this->newLine();
        $this->line('By status');

        if ($this->byStatus === []) {
            $this->line('  None.');
        } else {
            $statuses = array_values(array_filter(Loan::EVER_RELEASED_STATUSES, fn (string $status): bool => isset($this->byStatus[$status])));

            $this->table(['Status', 'Loans', 'Total'], array_map(fn (string $status): array => [
                $status,
                number_format($this->byStatus[$status]['loans']),
                Money::format($this->byStatus[$status]['amount']),
            ], $statuses));
        }

        $this->newLine();
        $this->line('Could not be read');

        if ($this->unreadable === []) {
            $this->line('  None.');
        } else {
            $this->table(['Loan', 'Status', 'Why'], $this->unreadable);
        }

        $this->newLine();
        $this->line(sprintf(
            'Missing release credits: %s %s, %s',
            number_format($this->missingCount),
            $this->missingCount === 1 ? 'loan' : 'loans',
            Money::format($this->missingTotal),
        ));
    }
}
