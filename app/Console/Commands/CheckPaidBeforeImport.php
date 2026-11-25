<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CsvImportRow;
use App\Models\Loan;
use App\Services\Accounting\Money;
use App\Services\CsvImport\NormalizedRow;
use App\Services\PaidBeforeImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;

/**
 * The imported loans whose "paid before the import" figure (PaidBeforeImport)
 * should be checked before it is relied on: below zero, or not what the
 * loan's import row says was paid (the Loan Amount less the Loan Balance, plus
 * the Interest Amount less the Interest Balance), where that row still holds
 * the file's figures.
 *
 * The figure is read back from the schedule: what its periods were paid, less
 * what posted payments here applied. Before 2026-10-02, extensions,
 * restructures and term extensions deleted part-paid periods, and penalty
 * waivers erased paid penalty, so on a loan touched by one of those the
 * periods hold less than was paid and the figure reads short, or below zero.
 * Total Paid and the loan Ledger show it as it reads; this lists the loans to
 * look at.
 *
 * ## It only ever reads
 *
 * Every statement is a SELECT: nothing is written, floored or corrected, so it
 * is safe to run on production as it is. A row `imports:redact-rows` has
 * redacted no longer holds the file's figures: such a loan is listed only
 * when its figure is below zero, and the run says how many loans could not be
 * compared with the file.
 *
 * Loans are read in chunks by id, with each chunk's figures, members and
 * import rows fetched in a fixed number of queries.
 */
#[Signature('imports:paid-before-import-check {--branch= : Only the loans booked in this branch (its id)}')]
#[Description('List imported loans whose paid-before-import figure is below zero or not what the import file says. Read only')]
class CheckPaidBeforeImport extends Command
{
    private const CHUNK = 500;

    /** The import row's four figures, by the loans file's column keys. */
    private const FILE_FIGURES = ['loan_amount', 'loan_balance', 'interest_amount', 'interest_balance'];

    public function handle(): int
    {
        $branch = $this->option('branch');

        if ($branch !== null && (preg_match('/^[1-9]\d*$/', (string) $branch) !== 1)) {
            $this->error('--branch takes a branch id, a whole number.');

            return self::INVALID;
        }

        $this->info('Read only. Nothing is written.');
        $this->newLine();

        // Per run, never on the command: a process that runs it twice (the
        // tests, a long-lived worker) must not carry one run's loans into the
        // next.
        $listed = [];
        $read = 0;
        $notCompared = 0;

        Loan::query()
            ->where(fn (Builder $q) => $q->whereNotNull('external_loan_no')->orWhereNotNull('imported_arrears_baseline'))
            ->when($branch !== null, fn (Builder $q) => $q->where('branch_id', (int) $branch))
            ->with('borrower:id,borrower_code')
            ->select(['id', 'borrower_id', 'branch_id', 'loan_account_number', 'application_number', 'external_loan_no'])
            ->chunkById(self::CHUNK, function (EloquentCollection $loans) use (&$listed, &$read, &$notCompared): void {
                $figures = PaidBeforeImport::totalsByLoan($loans->modelKeys());
                $inFile = $this->filePaidByLoan($loans->modelKeys());

                foreach ($loans as $loan) {
                    $read++;
                    $notCompared += isset($inFile[$loan->id]) ? 0 : 1;
                    $row = $this->row($loan, $figures[$loan->id] ?? 0, $inFile[$loan->id] ?? null);

                    if ($row !== null) {
                        $listed[] = $row;
                    }
                }
            });

        $this->report($listed, $read, $notCompared);

        return self::SUCCESS;
    }

    /**
     * The loan's line when its figure is below zero or not what its file
     * says; null when it reads as the file (or, with no file, isn't negative).
     *
     * @return list<string>|null
     */
    private function row(Loan $loan, int $figure, ?int $inFile): ?array
    {
        $why = match (true) {
            $figure < 0 => 'Below zero',
            $inFile !== null && $figure !== $inFile => 'Not what the file says',
            default => null,
        };

        if ($why === null) {
            return null;
        }

        return [
            (string) ($loan->loan_account_number ?? $loan->application_number),
            (string) ($loan->external_loan_no ?? '—'),
            (string) ($loan->borrower?->borrower_code ?? '—'),
            Money::format($figure),
            $inFile === null ? 'Not kept' : Money::format($inFile),
            $why,
        ];
    }

    /**
     * What each loan's import row says was paid before the import, in
     * centavos: the row that wrote the loan, while it still holds the file's
     * figures. A loan whose row is redacted or gone has no key.
     *
     * @param  list<int>  $loanIds
     * @return array<int, int>
     */
    private function filePaidByLoan(array $loanIds): array
    {
        $paid = [];

        $rows = CsvImportRow::query()
            ->whereIn('loan_id', $loanIds)
            ->where('result', 'imported')
            ->whereNull('redacted_at')
            ->orderBy('id')
            ->get(['id', 'loan_id', 'normalized']);

        foreach ($rows as $row) {
            $loanId = (int) $row->loan_id;

            if (isset($paid[$loanId]) || ! is_array($row->normalized) || ! is_array($row->normalized['values'] ?? null)) {
                continue;
            }

            try {
                $normalized = NormalizedRow::fromPayload($row->normalized);
            } catch (InvalidArgumentException) {
                continue;
            }

            $figures = array_map(fn (string $key): mixed => $normalized->value($key), self::FILE_FIGURES);

            if (in_array(null, $figures, true)) {
                continue;
            }

            [$amount, $balance, $interest, $interestBalance] = array_map('intval', $figures);
            $paid[$loanId] = ($amount - $balance) + ($interest - $interestBalance);
        }

        return $paid;
    }

    /**
     * @param  list<list<string>>  $listed
     */
    private function report(array $listed, int $read, int $notCompared): void
    {
        $this->line('Imported loans whose paid-before-import figure is below zero or not what the import file says');

        if ($listed === []) {
            $this->line('  None.');
        } else {
            $this->table(['Loan', 'Loan No. in the file', 'Member', 'Paid before import', 'In the file', 'Why'], $listed);
        }

        $count = count($listed);

        $this->newLine();
        $this->line('Imported loans read: '.number_format($read));
        $this->line('Not compared with the file (its import row is redacted or gone): '.number_format($notCompared));
        $this->line('Imported loans to check: '.number_format($count).' '.($count === 1 ? 'loan' : 'loans'));
    }
}
