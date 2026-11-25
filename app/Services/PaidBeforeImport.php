<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What a loan migrated in by the CSV importer (Loan::isImported()) was paid
 * before the import, in whole centavos.
 *
 * The file states it as the Loan Amount less the Loan Balance (principal) and
 * the Interest Amount less the Interest Balance (interest). The importer lays
 * those amounts on the schedule's paid columns (LoanScheduleReconstructor) and
 * records no payment for them, so they are read back as each part paid on the
 * periods less what the posted payments applied to it. A payment after the
 * import adds to both sides and its void takes it off both, so the figure
 * never moves. Nothing is stored, so nothing stored is rewritten.
 *
 * One figure for every screen: the loan's Ledger row "Paid before the loan was
 * imported" (LoanLedgerService), and Total Paid wherever it is shown, which
 * adds it to the posted receipts so it agrees with Principal Paid and Interest
 * Paid: the loan summary's `total_repaid`, the Statement of Account, the member
 * overview, the Subsidiary Ledger and the Member Portal. A loan that was not
 * imported always reads 0.
 */
final class PaidBeforeImport
{
    private const PARTS = ['principal', 'interest', 'penalty'];

    /**
     * @param  iterable<AmortizationSchedule>|null  $schedules  the loan's periods, when the caller has them
     * @param  iterable<Repayment>|null  $repayments  the loan's payments, any status, when the caller has them
     * @return array{principal: int, interest: int, penalty: int, total: int}
     */
    public static function forLoan(Loan $loan, ?iterable $schedules = null, ?iterable $repayments = null): array
    {
        $none = ['principal' => 0, 'interest' => 0, 'penalty' => 0, 'total' => 0];

        if (! $loan->isImported()) {
            return $none;
        }

        $paid = self::sumParts($schedules ?? $loan->amortizationSchedules()->get(), '_paid');
        $applied = $repayments === null
            ? self::appliedByLoan([$loan->id])[$loan->id] ?? ['principal' => 0, 'interest' => 0, 'penalty' => 0]
            : self::sumParts(collect($repayments)->where('status', 'posted'), '_applied');

        $parts = [];

        foreach (self::PARTS as $part) {
            $parts[$part] = $paid[$part] - $applied[$part];
        }

        return [...$parts, 'total' => array_sum($parts)];
    }

    /**
     * forLoan()'s total for each imported loan among `$loanIds`, in two grouped
     * queries. A loan with nothing paid before an import has no key.
     *
     * @param  list<int>  $loanIds
     * @return array<int, int> centavos by loan id
     */
    public static function totalsByLoan(array $loanIds): array
    {
        return array_filter(
            array_map(fn (array $parts): int => $parts['total'], self::partsByLoan($loanIds)),
            fn (int $total): bool => $total !== 0,
        );
    }

    /**
     * forLoan() for each imported loan among `$loanIds`, part by part, in two
     * grouped queries. Every imported loan has a key.
     *
     * @param  list<int>  $loanIds
     * @return array<int, array{principal: int, interest: int, penalty: int, total: int}> centavos by loan id
     */
    public static function partsByLoan(array $loanIds): array
    {
        $imported = $loanIds === [] ? [] : Loan::query()
            ->whereIn('id', $loanIds)
            ->where(fn (Builder $q) => $q->whereNotNull('external_loan_no')->orWhereNotNull('imported_arrears_baseline'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($imported === []) {
            return [];
        }

        $paid = self::sumsByLoan(DB::table('amortization_schedules')->whereIn('loan_id', $imported), '_paid');
        $applied = self::appliedByLoan($imported);
        $byLoan = [];

        foreach ($imported as $loanId) {
            $parts = [];

            foreach (self::PARTS as $part) {
                $parts[$part] = ($paid[$loanId][$part] ?? 0) - ($applied[$loanId][$part] ?? 0);
            }

            $byLoan[$loanId] = [...$parts, 'total' => array_sum($parts)];
        }

        return $byLoan;
    }

    /**
     * What the posted payments applied to each part, by loan.
     *
     * @param  list<int>  $loanIds
     * @return array<int, array{principal: int, interest: int, penalty: int}>
     */
    private static function appliedByLoan(array $loanIds): array
    {
        return self::sumsByLoan(
            DB::table('repayments')->whereIn('loan_id', $loanIds)->where('status', 'posted'),
            '_applied',
        );
    }

    /**
     * Each part's column (`principal{$suffix}`, …) added up per loan, in centavos.
     *
     * @return array<int, array{principal: int, interest: int, penalty: int}>
     */
    private static function sumsByLoan(QueryBuilder $rows, string $suffix): array
    {
        $select = implode(', ', array_map(
            fn (string $part): string => "COALESCE(SUM({$part}{$suffix}), 0) as {$part}",
            self::PARTS,
        ));

        return $rows->groupBy('loan_id')
            ->selectRaw("loan_id, {$select}")
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->loan_id => array_combine(
                self::PARTS,
                array_map(fn (string $part): int => LoanService::toCentavos((float) $row->{$part}), self::PARTS),
            )])
            ->all();
    }

    /**
     * @param  iterable<object>  $rows
     * @return array{principal: int, interest: int, penalty: int}
     */
    private static function sumParts(iterable $rows, string $suffix): array
    {
        $sums = ['principal' => 0, 'interest' => 0, 'penalty' => 0];

        foreach ($rows as $row) {
            foreach (self::PARTS as $part) {
                $sums[$part] += LoanService::toCentavos((float) ($row->{$part.$suffix} ?? 0));
            }
        }

        return $sums;
    }
}
