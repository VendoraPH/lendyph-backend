<?php

namespace App\Services\Cic\Records;

use App\Models\Borrower;
use App\Models\CicCapture;
use App\Models\Loan;
use App\Services\Accounting\LoanAgingBuilder;
use App\Services\LoanListTotals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds every CSDF record a file for the reference date holds, from today's
 * rows (plan decisions 5 and 6):
 *
 * - **CI/CN:** released, ongoing and defaulted loans released before the
 *   reference date (a loan released on it waits for the next month, Q17),
 *   plus loans completed or restructured within the reference month, and
 *   closed loans the previous complete month-end capture still held as
 *   active (a backdated last payment), so every closure is reported once.
 *   Drafts, approved, void and rejected loans are never reported.
 * - **Left out, never silently** (`CicRecordSet::$leftOut`): a loan released
 *   and closed on the same day (a warning, Q17), and a completed loan with no
 *   recorded closing payment or adjustment (a warning; an error when the
 *   previous capture held it as active, since its closure can't be sent).
 * - **ID:** the members of those loans, and members who are co-makers on them.
 * - **NE:** those members recorded as deceased.
 *
 * Loans are read in chunks with their relations eager-loaded, and members in
 * chunks, so the query count grows with the chunks, never with the records.
 */
final class CicRecordBuilder
{
    public const LOAN_CHUNK = 200;

    public const MEMBER_CHUNK = 500;

    public function __construct(
        private readonly LoanListTotals $totals,
        private readonly LoanAgingBuilder $aging,
        private readonly CicIdentifications $identifications,
    ) {}

    /**
     * Every record, in the file's order: ID, CI, CN, NE, each by key.
     *
     * @param  bool  $withContracts  false: subjects and events only
     * @param  list<int>|null  $memberIds  more members to report, whether or not a built contract names them
     * @param  list<int>|null  $loanIds  only these loans' contracts (a single record, or a correction's re-read)
     * @param  bool  $force  build the named loans whatever their state (a correction re-reads every contract it holds)
     * @param  bool  $onlyReportable  with `$loanIds` and no force: false reads the named loans without the reportable-loan query, so each still goes through the left-out checks (a correction re-checks the loans its month end left out)
     */
    public function build(Carbon $reference, bool $withContracts = true, ?array $memberIds = null, ?array $loanIds = null, bool $force = false, bool $onlyReportable = true): CicRecordSet
    {
        $reference = $reference->copy()->startOfDay();
        $forced = $force && $loanIds !== null ? array_fill_keys($loanIds, true) : [];
        $context = new CicBuildContext($reference, $this->previouslyActive($reference), $forced);
        $contracts = new ContractRecordBuilder($context, $this->totals, $this->aging);
        $subjectIds = $memberIds === null ? [] : array_fill_keys($memberIds, true);
        $contractRecords = [];

        if ($withContracts) {
            $loans = $forced !== [] || ($loanIds !== null && ! $onlyReportable) ? $this->loans()->whereKey($loanIds) : $this->reportableLoans($context);

            if ($loanIds !== null && $forced === [] && $onlyReportable) {
                $loans->whereKey($loanIds);
            }

            $loans->chunkById(self::LOAN_CHUNK, function ($loans) use ($contracts, &$contractRecords, &$subjectIds): void {
                $memberCoMakers = $loans->flatMap(fn (Loan $loan) => $loan->coMakers->pluck('member_borrower_id'))->filter()->unique()->values()->all();
                $memberCodes = $memberCoMakers === [] ? [] : DB::table('borrowers')->whereIn('id', $memberCoMakers)->pluck('borrower_code', 'id')->all();

                $built = $contracts->buildChunk($loans, $memberCodes);

                foreach ($built as $record) {
                    $contractRecords[] = $record;
                    $subjectIds[$record->borrowerId] = true;
                }

                foreach ($loans as $loan) {
                    if (! isset($built[$loan->id])) {
                        continue;
                    }

                    foreach ($loan->coMakers as $coMaker) {
                        if ($coMaker->member_borrower_id !== null) {
                            $subjectIds[(int) $coMaker->member_borrower_id] = true;
                        }
                    }
                }
            }, 'loans.id', 'id');
        }

        $contractRecords = $this->withUniqueContractNumbers($contractRecords);

        $subjects = new SubjectRecordBuilder($context);
        $events = new EventRecordBuilder($context);
        $subjectRecords = [];
        $eventRecords = [];

        foreach (array_chunk(array_keys($subjectIds), self::MEMBER_CHUNK) as $chunk) {
            $identifications = $this->identifications->forBorrowers($chunk);

            foreach (Borrower::query()->whereKey($chunk)->get() as $member) {
                $subjectRecords[] = $subjects->build($member, $identifications[$member->id] ?? ['identifications' => [], 'ids' => []]);

                if ($events->applies($member)) {
                    $eventRecords[] = $events->build($member);
                }
            }
        }

        return new CicRecordSet($context, [
            SubjectRecordBuilder::TYPE => $subjectRecords,
            'CI' => array_values(array_filter($contractRecords, fn (CicRecord $r) => $r->type === 'CI')),
            'CN' => array_values(array_filter($contractRecords, fn (CicRecord $r) => $r->type === 'CN')),
            EventRecordBuilder::TYPE => $eventRecords,
        ], $contracts->leftOut);
    }

    /**
     * Keeps every contract number unique (security review S-M1, re-reviews
     * MM3 and SN2, delta re-review), comparing keys folded as the unique
     * indexes do, capitals and accents aside (CicFormat::fold()):
     *
     * - a number another loan froze (`cic_contract_no`), in this build or not,
     *   is taken;
     * - when loans of this build share a number no loan froze, the loan whose
     *   own LN- number it is keeps it;
     * - a number shaped `loan-<digits>` that isn't the loan's own key is
     *   reserved for loans with no number of their own.
     *
     * Every other loan is keyed `loan-{id}` with a DUPLICATE_CONTRACT error, so
     * the capture never breaks on a duplicate key. The Provider Contract No
     * keeps the number until the loan is given its own (an override's number).
     *
     * @param  list<CicRecord>  $records
     * @return list<CicRecord>
     */
    private function withUniqueContractNumbers(array $records): array
    {
        $byKey = [];

        foreach ($records as $index => $record) {
            if ($record->loanId !== null && $record->key !== "loan-{$record->loanId}") {
                $byKey[CicFormat::fold($record->key)][] = $index;
            }
        }

        if ($byKey === []) {
            return $records;
        }

        $keys = array_values(array_unique(array_map(fn (array $indexes) => $records[$indexes[0]]->key, $byKey)));
        $frozen = [];
        $native = [];

        foreach (array_chunk($keys, self::MEMBER_CHUNK) as $chunk) {
            foreach (DB::table('loans')->whereIn('cic_contract_no', $chunk)->get(['id', 'cic_contract_no']) as $row) {
                $frozen[CicFormat::fold((string) $row->cic_contract_no)] = (int) $row->id;
            }

            foreach (DB::table('loans')->whereIn('loan_account_number', $chunk)->get(['id', 'loan_account_number']) as $row) {
                $native[CicFormat::fold((string) $row->loan_account_number)] = (int) $row->id;
            }
        }

        foreach ($byKey as $folded => $indexes) {
            $reserved = preg_match('/^loan-\d+$/i', $folded) === 1;
            $holder = $reserved ? null : ($frozen[$folded] ?? (count($indexes) > 1 ? ($native[$folded] ?? null) : null));

            if (! $reserved && count($indexes) === 1 && ($holder === null || $holder === $records[$indexes[0]]->loanId)) {
                continue;
            }

            foreach ($indexes as $index) {
                $record = $records[$index];

                if ($holder === $record->loanId) {
                    continue;
                }

                $records[$index] = $record->rekeyed("loan-{$record->loanId}", [ContractRecordBuilder::issue("{$record->type}7", 'error', 'DUPLICATE_CONTRACT', $reserved
                    ? 'This loan number reads like the key CIC Reporting gives a loan with no number of its own (loan- and digits), so it can\'t be sent under it. Give this loan its own CIC contract number.'
                    : 'Another loan reported to CIC has the same loan number (capitals and accents aside), so this one can\'t be sent under it. Give this loan its own CIC contract number.')]);
            }
        }

        return $records;
    }

    /**
     * The loans the latest complete month-end capture before the reference
     * date held as active contracts (phase AC), by loan id.
     *
     * @return array<int, true>
     */
    public function previouslyActive(Carbon $reference): array
    {
        $previous = CicCapture::query()
            ->where('kind', CicCapture::KIND_MONTH_END)
            ->where('status', CicCapture::STATUS_COMPLETE)
            ->whereDate('reference_date', '<', $reference->toDateString())
            ->orderByDesc('reference_date')
            ->value('id');

        if ($previous === null) {
            return [];
        }

        return DB::table('cic_capture_records')
            ->where('capture_id', $previous)
            ->whereIn('record_type', ['CI', 'CN'])
            ->whereNotNull('loan_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`values`, CONCAT('$.', record_type, '9'))) = 'AC'")
            ->pluck('loan_id')
            ->mapWithKeys(fn (mixed $id) => [(int) $id => true])
            ->all();
    }

    /** @return Builder<Loan> loans with what the contract builder reads */
    private function loans(): Builder
    {
        return Loan::query()->with([
            'borrower:id,borrower_code',
            'amortizationSchedules',
            'coMakers' => fn ($query) => $query->orderBy('co_maker_loan.id'),
            'cicOverride',
        ]);
    }

    /** @return Builder<Loan> */
    private function reportableLoans(CicBuildContext $context): Builder
    {
        $monthStart = $context->reference->copy()->startOfMonth();
        $endOfDay = $context->endOfReferenceDay();
        $previouslyActive = array_keys($context->previouslyActive);

        return $this->loans()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $open) => $open
                    ->whereIn('status', Loan::COLLECTIBLE_STATUSES)
                    ->where(fn (Builder $released) => $released->whereNull('released_at')->orWhere('released_at', '<', $context->reference)))
                ->orWhere(fn (Builder $restructured) => $restructured
                    ->where('status', 'restructured')
                    ->whereBetween('restructured_at', [$monthStart, $endOfDay]))
                ->orWhere(fn (Builder $completed) => $completed
                    ->where('status', 'completed')
                    ->where(fn (Builder $ended) => $ended
                        ->whereExists(fn ($payments) => $payments->selectRaw('1')->from('repayments')
                            ->whereColumn('repayments.loan_id', 'loans.id')
                            ->where('repayments.status', 'posted')
                            ->whereDate('repayments.payment_date', '>=', $monthStart->toDateString()))
                        ->orWhereExists(fn ($adjustments) => $adjustments->selectRaw('1')->from('loan_adjustments')
                            ->whereColumn('loan_adjustments.loan_id', 'loans.id')
                            ->where('loan_adjustments.adjustment_type', 'balance_adjustment')
                            ->where('loan_adjustments.status', 'applied')
                            ->where('loan_adjustments.applied_at', '>=', $monthStart))
                        // No closing event at all: a completed loan last changed
                        // this month, left out with a warning, never silently.
                        ->orWhere(fn (Builder $silent) => $silent
                            ->whereNotExists(fn ($payments) => $payments->selectRaw('1')->from('repayments')
                                ->whereColumn('repayments.loan_id', 'loans.id')
                                ->where('repayments.status', 'posted'))
                            ->whereNotExists(fn ($adjustments) => $adjustments->selectRaw('1')->from('loan_adjustments')
                                ->whereColumn('loan_adjustments.loan_id', 'loans.id')
                                ->where('loan_adjustments.adjustment_type', 'balance_adjustment')
                                ->where('loan_adjustments.status', 'applied'))
                            ->whereBetween('loans.updated_at', [$monthStart, $endOfDay]))))
                // Released on the previous month end, so held for this month
                // (Q17), and closed since: its same-day payoff is warned of.
                ->orWhere(fn (Builder $held) => $held
                    ->whereIn('status', ['completed', 'restructured'])
                    ->whereDate('released_at', $context->previousMonthEnd()->toDateString()))
                ->when($previouslyActive !== [], fn (Builder $query) => $query->orWhere(fn (Builder $closed) => $closed
                    ->whereIn('status', ['completed', 'restructured'])
                    ->whereIn('loans.id', $previouslyActive))));
    }
}
