<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Loan;
use App\Services\Accounting\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Closed loans (completed or restructured) that still show insurance premium
 * owed (`insurance_remaining_balance`).
 *
 * Payments collect the premium a release left owed first, and a loan that
 * owes it no longer completes. Before that, nothing collected it, so a loan
 * could complete, or be closed by a restructure, with the premium still on
 * it. Closed records are never recalculated: this command lists them, so the
 * accountant can decide what to do with each from the facts.
 *
 * ## It only ever reads
 *
 * Every statement is a SELECT: no transaction is opened and nothing is
 * written, so it is safe to run on production as it is. It is a report, not
 * a fix, and it has no mode that changes anything.
 *
 * For each loan: the account number, status, member code, the premium, what
 * the release collected of it, and what is still owed, in centavos summed
 * exactly; then the count and total by status and overall. Loans are read in
 * chunks by id with their members, one query plus one per chunk.
 */
#[Signature('insurance:owed-on-closed-loans-report')]
#[Description('List completed or restructured loans that still show insurance premium owed. Read only')]
class ReportInsuranceOwedOnClosedLoans extends Command
{
    private const CHUNK = 500;

    private const CLOSED_STATUSES = ['completed', 'restructured'];

    /** @var list<list<string>> */
    private array $rows = [];

    /** @var array<string, array{loans: int, owed: int}> */
    private array $byStatus = [];

    private int $total = 0;

    public function handle(): int
    {
        $this->info('Read only. Nothing is written.');
        $this->newLine();

        Loan::query()
            ->whereIn('status', self::CLOSED_STATUSES)
            ->where('insurance_remaining_balance', '>', 0)
            ->with('borrower:id,borrower_code')
            ->select(['id', 'borrower_id', 'status', 'loan_account_number', 'application_number', 'insurance_premium_amount', 'insurance_partial_amount', 'insurance_remaining_balance'])
            ->chunkById(self::CHUNK, function (EloquentCollection $loans): void {
                foreach ($loans as $loan) {
                    $this->count($loan);
                }
            });

        $this->report();

        return self::SUCCESS;
    }

    private function count(Loan $loan): void
    {
        $owed = Money::toCentavos($loan->insurance_remaining_balance) ?? 0;

        if ($owed <= 0) {
            return;
        }

        $this->total += $owed;
        $this->byStatus[$loan->status] = [
            'loans' => ($this->byStatus[$loan->status]['loans'] ?? 0) + 1,
            'owed' => ($this->byStatus[$loan->status]['owed'] ?? 0) + $owed,
        ];
        $this->rows[] = [
            (string) ($loan->loan_account_number ?? $loan->application_number),
            $loan->status,
            (string) ($loan->borrower?->borrower_code ?? '—'),
            Money::format(Money::toCentavos($loan->insurance_premium_amount ?? 0) ?? 0),
            Money::format(Money::toCentavos($loan->insurance_partial_amount ?? 0) ?? 0),
            Money::format($owed),
        ];
    }

    private function report(): void
    {
        $this->line('Closed loans that still show insurance premium owed');

        if ($this->rows === []) {
            $this->line('  None.');
        } else {
            $this->table(['Loan', 'Status', 'Member', 'Premium', 'Collected at release', 'Premium owed'], $this->rows);

            $statuses = array_values(array_filter(self::CLOSED_STATUSES, fn (string $status): bool => isset($this->byStatus[$status])));

            $this->table(['Status', 'Loans', 'Premium owed'], array_map(fn (string $status): array => [
                $status,
                number_format($this->byStatus[$status]['loans']),
                Money::format($this->byStatus[$status]['owed']),
            ], $statuses));
        }

        $this->newLine();
        $count = count($this->rows);
        $this->line(sprintf('Closed loans showing premium owed: %s %s, %s', number_format($count), $count === 1 ? 'loan' : 'loans', Money::format($this->total)));
    }
}
