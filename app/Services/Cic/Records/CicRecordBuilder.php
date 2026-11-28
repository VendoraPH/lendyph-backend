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
     * @param  bool  $withContracts  false: subjects and events only (a correction keeps its month-end contracts)
     * @param  list<int>|null  $memberIds  the members to report when contracts aren't built
     */
    public function build(Carbon $reference, bool $withContracts = true, ?array $memberIds = null): CicRecordSet
    {
        $reference = $reference->copy()->startOfDay();
        $context = new CicBuildContext($reference, $this->previouslyActive($reference));
        $contracts = new ContractRecordBuilder($context, $this->totals, $this->aging);
        $subjectIds = $memberIds === null ? [] : array_fill_keys($memberIds, true);
        $contractRecords = [];

        if ($withContracts) {
            $this->reportableLoans($context)->chunkById(self::LOAN_CHUNK, function ($loans) use ($contracts, &$contractRecords, &$subjectIds): void {
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

    /** @return Builder<Loan> */
    private function reportableLoans(CicBuildContext $context): Builder
    {
        $monthStart = $context->reference->copy()->startOfMonth();
        $endOfDay = $context->endOfReferenceDay();
        $previouslyActive = array_keys($context->previouslyActive);

        return Loan::query()
            ->with([
                'borrower:id,borrower_code',
                'amortizationSchedules',
                'coMakers' => fn ($query) => $query->orderBy('co_maker_loan.id'),
                'cicOverride',
            ])
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
                ->when($previouslyActive !== [], fn (Builder $query) => $query->orWhere(fn (Builder $closed) => $closed
                    ->whereIn('status', ['completed', 'restructured'])
                    ->whereIn('loans.id', $previouslyActive))));
    }
}
