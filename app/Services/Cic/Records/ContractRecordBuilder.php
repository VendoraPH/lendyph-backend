<?php

namespace App\Services\Cic\Records;

use App\Models\AmortizationSchedule;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Services\Accounting\LoanAgingBuilder;
use App\Services\CollateralValuation;
use App\Services\LoanListTotals;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The CI (Installment) or CN (Non-Installment) record of a reportable loan,
 * per notes/cic/mapping/cic-to-lendy-mapping.csv.
 *
 * - **Classification:** CicContractClassifier, the rule the loan override's
 *   checks use too. The record type is the override's, else the loan's own
 *   structure (one lump sum is CN, else CI). The product mapping gives the
 *   contract type, transaction type and purpose of credit only when its
 *   record type matches; a product mapped to the other type is a warning
 *   (unless the override set the record type itself), and its codes count as
 *   missing (an error) unless the override supplies them.
 *   An unmapped product is an error on the contract.
 * - **Figures** come from the existing services, never new money logic:
 *   outstanding is LoanListTotals::byLoan()'s `total_outstanding`; overdue
 *   periods are AmortizationSchedule::lateUnpaid() from each bare due date;
 *   what a period still owes is Loan::owedCentavos(); overdue days are
 *   LoanAgingBuilder's `days_past_due`; pledge values are
 *   CollateralValuation::pledgeValues(). CIC adds only whole-peso formatting.
 * - Figures are read as of now; dates compare with the reference date. A
 *   capture freezes them.
 * - Each chunk of loans is read in a fixed number of queries (no N+1).
 */
final class ContractRecordBuilder
{
    public const GUARANTEE_BLOCKS = 6;

    public const LINKED_SUBJECTS = 6;

    /** A CSDF contract number's limit, which loans.cic_contract_no holds. */
    public const CONTRACT_NO_MAX = 38;

    /** @var array<string, string> loans.frequency → PaymentPeriodicityDomain */
    private const PERIODICITY = ['daily' => 'D', 'weekly' => 'W', 'bi_weekly' => 'F', 'semi_monthly' => 'F', 'monthly' => 'M'];

    /** Field positions that differ between the two layouts. */
    private const LAYOUT = [
        'CI' => ['guarantees' => 42, 'linked' => 126],
        'CN' => ['guarantees' => 26, 'linked' => 110],
    ];

    /** @var list<array{record_type: string, record_key: string, field_id: string|null, severity: string, code: string, message: string}> the loans left out of the file, as issues */
    public array $leftOut = [];

    public function __construct(
        private readonly CicBuildContext $context,
        private readonly LoanListTotals $totals,
        private readonly LoanAgingBuilder $aging,
    ) {}

    /**
     * Whether a closed loan ended inside the reference month (and so is still
     * reported, closed, that month), from its end date (DDMMYYYY or '').
     */
    public function endedInReferenceMonth(string $endDate): bool
    {
        $key = CicFormat::dateKey($endDate);

        return $key !== null
            && $key >= (int) $this->context->reference->copy()->startOfMonth()->format('Ymd')
            && $key <= (int) $this->context->reference->format('Ymd');
    }

    /**
     * Each loan's record, keyed by loan id, from loans loaded with
     * `amortizationSchedules`, `coMakers` (in link order) and `cicOverride`,
     * and `borrower` (at least its code). Closed loans that didn't end in the
     * reference month are left out.
     *
     * @param  EloquentCollection<int, Loan>  $loans
     * @param  array<int, string>  $memberCodes  member id → borrower_code, for member co-makers
     * @return array<int, CicRecord>
     */
    public function buildChunk(EloquentCollection $loans, array $memberCodes): array
    {
        if ($loans->isEmpty()) {
            return [];
        }

        $ids = $loans->modelKeys();
        $facts = [
            'totals' => $this->totals->byLoan($ids),
            'days' => $this->daysPastDue($loans),
            'lastPayments' => $this->lastPayments($ids),
            'endDates' => $this->endDates($loans),
            'inPlaceRestructures' => $this->inPlaceRestructures($ids),
            'completedWhileDefaulted' => $this->completedWhileDefaulted($loans),
            'pledges' => $this->pledges($ids),
        ];

        $records = [];

        foreach ($loans as $loan) {
            $record = $this->build($loan, $facts, $memberCodes);

            if ($record !== null) {
                $records[$loan->id] = $record;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<int, string>  $memberCodes
     */
    private function build(Loan $loan, array $facts, array $memberCodes): ?CicRecord
    {
        $closed = in_array($loan->status, ['completed', 'restructured'], true);
        $endDate = $closed ? ($facts['endDates'][$loan->id] ?? '') : '';
        $forced = $this->context->isForced($loan->id);
        $wasActive = $this->context->wasActive($loan->id);

        if ($closed && ! $forced) {
            if ($endDate === '') {
                $this->leaveOut($loan, $wasActive ? 'error' : 'warning', 'NO_CLOSING_EVENT', $wasActive
                    ? 'This loan was reported active last month and is now closed, but no closing payment or balance adjustment is recorded, so its closure can\'t be reported. Record how it was closed.'
                    : 'This loan is closed, but no closing payment or balance adjustment is recorded, so it has no end date and is left out of the file. Check how it was closed.');

                return null;
            }

            // A closure is reported once: in the month it ended, or in the
            // first file after a capture that still held the loan as active
            // (a backdated last payment), with its true end date.
            if (! $this->endedInReferenceMonth($endDate) && ! $wasActive) {
                return null;
            }

            if (! $wasActive && $endDate === CicFormat::date($loan->released_at)) {
                $this->leaveOut($loan, 'warning', 'SAME_DAY_PAYOFF',
                    'This loan was released and closed on the same day; it is left out until CIC confirms how to send it (Q17).');

                return null;
            }
        }

        $issues = [];
        $schedules = $loan->amortizationSchedules;
        [$type, $contractType, $transactionType, $purposeCode] = $this->classify($loan, $schedules, $issues);
        $t = fn (int $position) => $type.$position;

        [$key, $contractNo] = $this->contractNumber($loan, $type, $issues);
        $maturity = CicFormat::date($loan->maturity_date);
        $phase = match (true) {
            $loan->status === 'restructured' => 'CL',
            $loan->status === 'completed' => CicFormat::dateKey($endDate) !== null && CicFormat::dateKey($maturity) !== null && CicFormat::dateKey($endDate) < CicFormat::dateKey($maturity) ? 'CA' : 'CL',
            default => 'AC',
        };
        $active = $phase === 'AC';
        $reference = $this->context->reference;

        $lastPayment = $facts['lastPayments'][$loan->id] ?? null;
        $overduePeriods = AmortizationSchedule::lateUnpaid($schedules, 0, $reference);
        $overdueOwed = $this->periodsOwed($loan, $overduePeriods);
        $outstanding = (string) ($facts['totals'][$loan->id]['total_outstanding'] ?? 0);

        $v = [
            $t(1) => $type,
            $t(2) => $this->context->providerCode,
            $t(3) => $this->context->branchCode($loan->branch_id),
            $t(4) => $this->context->referenceDate,
            $t(5) => CicFormat::text($loan->borrower?->borrower_code),
            $t(6) => 'B',
            $t(7) => $contractNo,
            $t(8) => $contractType,
            $t(9) => $phase,
            $t(10) => $this->status($loan, $type, $schedules, $facts),
            $t(11) => 'PHP',
            $t(12) => 'PHP',
            $t(13) => CicFormat::date($loan->released_at),
            $t(14) => $loan->isImported() ? '' : CicFormat::date($loan->created_at),
            $t(15) => $maturity,
            $t(16) => $endDate,
            $t(17) => $lastPayment === null ? '' : CicFormat::date($lastPayment->payment_date),
            $t(18) => match (true) {
                $loan->source_loan_id !== null => '2',
                isset($facts['inPlaceRestructures'][$loan->id]) => '1',
                default => '0',
            },
            $t(20) => CicFormat::amount((string) $loan->principal_amount),
        ];

        if ($type === 'CI') {
            $first = $schedules->first();
            $next = $active ? $schedules
                ->filter(fn (AmortizationSchedule $s) => in_array($s->status, AmortizationSchedule::UNPAID_STATUSES, true))
                ->sortBy('due_date')
                ->first() : null;

            $v += [
                'CI21' => (string) $schedules->count(),
                'CI22' => $transactionType,
                'CI23' => $purposeCode,
                'CI24' => self::PERIODICITY[$loan->frequency] ?? '',
                'CI26' => $first === null ? '' : CicFormat::amount((string) BigDecimal::of((string) $first->principal_due)->plus((string) $first->interest_due)),
                'CI27' => $first === null ? '' : CicFormat::date($first->due_date),
                'CI28' => CicFormat::amount($lastPayment === null ? '0' : (string) $lastPayment->amount_paid),
                'CI29' => $next === null ? '' : CicFormat::date($next->due_date),
                'CI30' => $next === null ? '' : CicFormat::amount($this->periodsOwed($loan, collect([$next]))),
                'CI33' => (string) $overduePeriods->count(),
                'CI34' => CicFormat::amount($overdueOwed),
                'CI35' => $this->overdueDays($loan, $schedules, $active, (int) ($facts['days'][$loan->id] ?? 0)),
            ];

            if ($active) {
                $v['CI31'] = (string) $schedules->filter(fn (AmortizationSchedule $s) => in_array($s->status, AmortizationSchedule::UNPAID_STATUSES, true))->count();
                $v['CI32'] = CicFormat::amount($outstanding);
            }
        } else {
            $v += [
                'CN21' => $transactionType,
                'CN23' => $active ? CicFormat::amount($outstanding) : '0',
                'CN24' => CicFormat::amount($overdueOwed),
            ];
        }

        $v += $this->guarantees($type, $facts['pledges'][$loan->id] ?? [], $issues);
        $v += $this->linkedSubjects($type, $loan->coMakers, $memberCodes, $issues);

        return new CicRecord($type, $key, CicFormat::compact($v), $issues, $loan->borrower_id, $loan->id, CicContractClassifier::structure($loan, $schedules->count()));
    }

    /**
     * Records a loan the file leaves out, as an issue under the record type
     * and contract number it would have had.
     */
    private function leaveOut(Loan $loan, string $severity, string $code, string $message): void
    {
        $mapping = $this->context->productMappings[$loan->loan_product_id] ?? null;
        $type = CicContractClassifier::forLoan($loan, $mapping, $loan->amortizationSchedules->count())->recordType;
        $ignored = [];
        [$key] = $this->contractNumber($loan, $type, $ignored);

        $this->leftOut[] = ['record_type' => $type, 'record_key' => $key, 'field_id' => "{$type}16", 'severity' => $severity, 'code' => $code, 'message' => $message];
    }

    /**
     * The record type, contract type, transaction type and purpose of credit
     * (prompt §5.3), by CicContractClassifier.
     *
     * @param  Collection<int, AmortizationSchedule>  $schedules
     * @param  list<array<string, mixed>>  $issues
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function classify(Loan $loan, Collection $schedules, array &$issues): array
    {
        $mapping = $this->context->productMappings[$loan->loan_product_id] ?? null;
        $class = CicContractClassifier::forLoan($loan, $mapping, $schedules->count());
        $type = $class->recordType;
        $repays = $class->structure === 'CN' ? 'in one lump sum' : 'in instalments';
        $product = $this->context->productNames[$loan->loan_product_id] ?? 'this loan\'s product';

        if ($class->overridesRecordType && $type !== $class->structure) {
            $issues[] = self::issue("{$type}1", 'warning', 'OVERRIDE_AGAINST_STRUCTURE',
                "This loan repays {$repays}, but its own CIC classification reports it as a {$type} contract. Check the override is still right.");
        }

        // A record type staff set on the loan already says the product's type
        // doesn't fit, so only a structure that disagrees with it is warned of.
        if ($class->mappingMismatch && ! $class->overridesRecordType) {
            $issues[] = self::issue("{$type}1", 'warning', 'CLASSIFICATION_MISMATCH',
                "This loan repays {$repays}, so it is reported as a {$type} contract, but its product is mapped to {$class->mappingRecordType} contracts, so the product's codes don't apply.");
        }

        $missing = array_keys(array_filter([
            'contract type' => $class->contractType === null,
            'transaction type' => $class->transactionType === null,
        ]));

        if ($missing !== [] && ! $class->hasMapping) {
            $issues[] = self::issue("{$type}8", 'error', 'UNMAPPED_PRODUCT',
                "The loan product \"{$product}\" has no CIC mapping, so this contract has no contract or transaction type. Map the product in CIC Settings, or give the loan its own classification.");
        } elseif ($missing !== []) {
            $issues[] = self::issue("{$type}8", 'error', 'CONTRACT_TYPE_FOR_OTHER_RECORD',
                "The product \"{$product}\" is mapped to {$class->mappingRecordType} contracts, so its codes don't fit this {$type} contract. Give the loan its own ".implode(' and ', $missing).'.');
        }

        return [$type, CicFormat::text($class->contractType), CicFormat::text($class->transactionType), CicFormat::text($class->purposeCode)];
    }

    /**
     * The record key and the Provider Contract No: the frozen number, else the
     * display number. A number CIC can't take is kept whole (an error) and
     * the record is keyed by the loan's id instead.
     *
     * @param  list<array<string, mixed>>  $issues
     * @return array{0: string, 1: string}
     */
    private function contractNumber(Loan $loan, string $type, array &$issues): array
    {
        $number = CicFormat::text($loan->cic_contract_no ?? $loan->displayNumber());

        if ($number === '') {
            return ["loan-{$loan->id}", ''];
        }

        if (mb_strlen($number) > self::CONTRACT_NO_MAX) {
            $issues[] = self::issue("{$type}7", 'error', 'CONTRACT_NO_TOO_LONG', sprintf(
                'The loan number is %d characters; CIC allows %d, so it can\'t be used as the contract number. It is never cut short.',
                mb_strlen($number),
                self::CONTRACT_NO_MAX,
            ));

            return ["loan-{$loan->id}", $number];
        }

        return [$number, $number];
    }

    /**
     * CI10 / CN10. CI: restructured → CR, completed while defaulted → DS,
     * defaulted or past due after grace at the reference date → PD. CN's list
     * has DS only.
     *
     * @param  Collection<int, AmortizationSchedule>  $schedules
     * @param  array<string, mixed>  $facts
     */
    private function status(Loan $loan, string $type, Collection $schedules, array $facts): string
    {
        if ($loan->status === 'completed' && isset($facts['completedWhileDefaulted'][$loan->id])) {
            return 'DS';
        }

        if ($type === 'CN') {
            return '';
        }

        return match (true) {
            $loan->status === 'restructured' => 'CR',
            $loan->status === 'defaulted' => 'PD',
            $loan->status !== 'completed' && AmortizationSchedule::lateUnpaid($schedules, $loan->grace_period_days, $this->context->reference)->isNotEmpty() => 'PD',
            default => '',
        };
    }

    /**
     * CI35 from the aging's day count: N while nothing has fallen due, 0 once
     * closed, then CIC's bands.
     *
     * @param  Collection<int, AmortizationSchedule>  $schedules
     */
    private function overdueDays(Loan $loan, Collection $schedules, bool $active, int $days): string
    {
        if (! $active) {
            return '0';
        }

        $reference = $this->context->reference->copy()->startOfDay();

        if (! $schedules->contains(fn (AmortizationSchedule $s) => $s->due_date->lte($reference))) {
            return 'N';
        }

        return match (true) {
            $days <= 0 => '0',
            $days <= 30 => '1',
            $days <= 60 => '2',
            $days <= 90 => '3',
            $days <= 180 => '4',
            $days <= 365 => '5',
            default => '6',
        };
    }

    /**
     * Principal, interest and penalty the periods still owe (Loan::owedCentavos(),
     * the premium left out), as a 2-decimal peso string.
     *
     * @param  Collection<int, AmortizationSchedule>  $periods
     */
    private function periodsOwed(Loan $loan, Collection $periods): string
    {
        $owed = $loan->owedCentavos($periods);

        return (string) BigDecimal::ofUnscaledValue($owed['principal'] + $owed['interest'] + $owed['penalty'], 2);
    }

    /**
     * Guarantee blocks 1–6: the loan's pledges by value, highest first, then
     * by link. The borrower is the guarantor of their own collateral (Q19).
     *
     * @param  list<object>  $pledges
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, string>
     */
    private function guarantees(string $type, array $pledges, array &$issues): array
    {
        $base = self::LAYOUT[$type]['guarantees'];
        $values = [];

        if (count($pledges) > self::GUARANTEE_BLOCKS) {
            $issues[] = self::issue("{$type}{$base}", 'warning', 'TOO_MANY_GUARANTEES', sprintf(
                'The loan has %d pledged collaterals; CIC takes %d, so the %d highest are reported.',
                count($pledges),
                self::GUARANTEE_BLOCKS,
                self::GUARANTEE_BLOCKS,
            ));
        }

        foreach (array_slice($pledges, 0, self::GUARANTEE_BLOCKS) as $index => $pledge) {
            $b = $base + 14 * $index;
            $f = fn (int $offset) => $type.($b + $offset);
            $code = $this->context->guaranteeCodes[(int) $pledge->collateral_type_id] ?? '';
            $assetCode = CicFormat::text($pledge->detail_value);

            if ($code === '') {
                $issues[] = self::issue($f(7), 'error', 'UNMAPPED_COLLATERAL_TYPE', sprintf(
                    'Guarantee %d: the collateral type "%s" has no CIC guarantee code. Map it in CIC Settings.',
                    $index + 1,
                    CicFormat::text($pledge->type_name) ?: 'without a type',
                ));
            }

            $values += [
                $f(0) => (string) $pledge->id,
                $f(1) => CicFormat::text($pledge->owner_code),
                $f(3) => CicFormat::amount((string) $pledge->snapshot_value),
                $f(4) => 'PHP',
                $f(5) => CicFormat::date($pledge->attached_at),
                $f(7) => $code,
                $f(8) => mb_strlen($assetCode) <= 20 ? $assetCode : '',
                $f(9) => CicFormat::text($pledge->type_name),
                $f(11) => CicFormat::amount($pledge->appraised),
            ];
        }

        return $values;
    }

    /**
     * Linked subjects 1–6: the co-makers in link order, role G. A member
     * co-maker goes by their member code (and has an ID record); anyone else
     * by name only.
     *
     * @param  EloquentCollection<int, CoMaker>  $coMakers
     * @param  array<int, string>  $memberCodes
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, string>
     */
    private function linkedSubjects(string $type, EloquentCollection $coMakers, array $memberCodes, array &$issues): array
    {
        $base = self::LAYOUT[$type]['linked'];
        $values = [];

        if ($coMakers->count() > self::LINKED_SUBJECTS) {
            $issues[] = self::issue("{$type}{$base}", 'warning', 'TOO_MANY_CO_MAKERS', sprintf(
                'The loan has %d co-makers; CIC takes %d, so the first %d linked are reported.',
                $coMakers->count(),
                self::LINKED_SUBJECTS,
                self::LINKED_SUBJECTS,
            ));
        }

        foreach ($coMakers->take(self::LINKED_SUBJECTS)->values() as $index => $coMaker) {
            $b = $base + 3 * $index;
            $memberCode = $coMaker->member_borrower_id === null ? '' : ($memberCodes[(int) $coMaker->member_borrower_id] ?? '');

            $values[$type.$b] = $memberCode;
            $values[$type.($b + 1)] = 'G';
            $values[$type.($b + 2)] = $memberCode === ''
                ? CicFormat::name($coMaker->first_name, $coMaker->middle_name, $coMaker->last_name, $coMaker->suffix)
                : '';
        }

        return $values;
    }

    /**
     * Days past due per loan, from the aging (its oldest unpaid due date,
     * counted at the reference date). Loans it doesn't list owe nothing due.
     *
     * @param  EloquentCollection<int, Loan>  $loans
     * @return array<int, int>
     */
    private function daysPastDue(EloquentCollection $loans): array
    {
        $collectible = $loans->filter(fn (Loan $loan) => in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true));

        if ($collectible->isEmpty()) {
            return [];
        }

        return $this->aging->query($this->context->reference->toDateString(), null, [], Loan::query()->whereIn('loans.id', $collectible->modelKeys()))
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->id => (int) $row->days_past_due])
            ->all();
    }

    /**
     * Each loan's latest posted payment on or before the reference date (the
     * aging's last payment), the latest recorded when two share the date.
     *
     * @param  list<int>  $ids
     * @return array<int, object{payment_date: string, amount_paid: string}>
     */
    private function lastPayments(array $ids): array
    {
        $latest = DB::table('repayments')
            ->whereIn('loan_id', $ids)
            ->where('status', 'posted')
            ->whereDate('payment_date', '<=', $this->context->reference->toDateString())
            ->groupBy('loan_id')
            ->selectRaw('loan_id, MAX(payment_date) as payment_date');

        return DB::table('repayments')
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('latest.loan_id', '=', 'repayments.loan_id')
                ->on('latest.payment_date', '=', 'repayments.payment_date'))
            ->where('repayments.status', 'posted')
            ->orderBy('repayments.id')
            ->get(['repayments.loan_id', 'repayments.payment_date', 'repayments.amount_paid'])
            ->keyBy('loan_id')
            ->all();
    }

    /**
     * When each closed loan ended (DDMMYYYY): a completed loan on its latest
     * posted payment or the balance adjustment that completed it, whichever
     * is later; a restructured loan on its restructure.
     *
     * @param  EloquentCollection<int, Loan>  $loans
     * @return array<int, string>
     */
    private function endDates(EloquentCollection $loans): array
    {
        $dates = [];

        foreach ($loans->where('status', 'restructured') as $loan) {
            $dates[$loan->id] = CicFormat::date($loan->restructured_at);
        }

        $completed = $loans->where('status', 'completed')->modelKeys();

        if ($completed === []) {
            return $dates;
        }

        $payments = DB::table('repayments')->whereIn('loan_id', $completed)->where('status', 'posted')
            ->groupBy('loan_id')->selectRaw('loan_id, MAX(payment_date) as ended')->pluck('ended', 'loan_id');
        $adjustments = DB::table('loan_adjustments')->whereIn('loan_id', $completed)
            ->where('adjustment_type', 'balance_adjustment')->where('status', 'applied')
            ->groupBy('loan_id')->selectRaw('loan_id, MAX(applied_at) as ended')->pluck('ended', 'loan_id');

        foreach ($completed as $id) {
            $ends = array_filter([
                isset($payments[$id]) ? Carbon::parse($payments[$id])->toDateString() : null,
                isset($adjustments[$id]) ? Carbon::parse($adjustments[$id])->toDateString() : null,
            ]);

            $dates[$id] = $ends === [] ? '' : CicFormat::date(max($ends));
        }

        return $dates;
    }

    /**
     * Loans with an applied in-place restructure (CI18 = 1).
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function inPlaceRestructures(array $ids): array
    {
        return DB::table('loan_adjustments')->whereIn('loan_id', $ids)
            ->where('adjustment_type', 'restructure')->where('status', 'applied')
            ->distinct()->pluck('loan_id')
            ->mapWithKeys(fn (mixed $id) => [(int) $id => true])
            ->all();
    }

    /**
     * Completed loans whose completing payment was recorded while defaulted
     * (RepaymentService::completedWhileDefaulted()'s rule: the latest
     * recorded posted payment, by id).
     *
     * @param  EloquentCollection<int, Loan>  $loans
     * @return array<int, true>
     */
    private function completedWhileDefaulted(EloquentCollection $loans): array
    {
        $completed = $loans->where('status', 'completed')->modelKeys();

        if ($completed === []) {
            return [];
        }

        $latest = DB::table('repayments')->whereIn('loan_id', $completed)->where('status', 'posted')
            ->groupBy('loan_id')->selectRaw('MAX(id) as id');

        return DB::table('repayments')->whereIn('id', $latest)
            ->where('loan_status_before', 'defaulted')
            ->pluck('loan_id')
            ->mapWithKeys(fn (mixed $id) => [(int) $id => true])
            ->all();
    }

    /**
     * Each loan's pledges, highest value first, then by link, with the
     * collateral's owner code, type and appraised value.
     *
     * @param  list<int>  $ids
     * @return array<int, list<object>>
     */
    private function pledges(array $ids): array
    {
        // A read only: CollateralActiveLoansTest lists every file that writes
        // loan_collaterals, and this one never does.
        $rows = DB::query()->from('loan_collaterals')
            ->join('collaterals', 'collaterals.id', '=', 'loan_collaterals.collateral_id')
            ->leftJoin('collateral_types', 'collateral_types.id', '=', 'collaterals.collateral_type_id')
            ->leftJoin('borrowers', 'borrowers.id', '=', 'collaterals.borrower_id')
            ->whereIn('loan_collaterals.loan_id', $ids)
            ->orderByDesc('loan_collaterals.snapshot_value')
            ->orderBy('loan_collaterals.id')
            ->get([
                'loan_collaterals.id', 'loan_collaterals.loan_id', 'loan_collaterals.collateral_id',
                'loan_collaterals.snapshot_value', 'loan_collaterals.attached_at',
                'collaterals.collateral_type_id', 'collaterals.detail_value',
                'collateral_types.name as type_name', 'borrowers.borrower_code as owner_code',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $appraised = CollateralValuation::pledgeValues(
            Collateral::query()->with('collateralType')->whereKey($rows->pluck('collateral_id')->unique()->all())->get()
        );

        $byLoan = [];

        foreach ($rows as $row) {
            $row->appraised = number_format($appraised[(int) $row->collateral_id] ?? 0, 2, '.', '');
            $byLoan[(int) $row->loan_id][] = $row;
        }

        return $byLoan;
    }

    /** @return array{field_id: string|null, severity: 'error'|'warning', code: string, message: string} */
    public static function issue(?string $fieldId, string $severity, string $code, string $message): array
    {
        return ['field_id' => $fieldId, 'severity' => $severity, 'code' => $code, 'message' => $message];
    }
}
