<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Services\Penalties\BracketRate;
use App\Services\Penalties\PenaltyTerms;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The loan page's Ledger (GET /loans/{id}/ledger): every event that moved
 * what the loan owes, in date order and then posting order, each with its own
 * figures and the running balances after it, all worked out here so the
 * browser only displays them. Reads only.
 *
 * The rows:
 *  - `release`: the principal, the original schedule's interest and its share
 *    capital build-up;
 *  - `repayment` / `extension_payment`: a payment's own parts, from the
 *    repayment's `*_applied` columns, and the share capital it credited. An
 *    extension's collection is the repayment the extension names
 *    (`new_values.repayment_id`, or for older ones its credit entry); the
 *    credit entry itself is not counted again;
 *  - `void`: a voided payment put back, on the day it was voided;
 *  - `extension_charge`: the fresh interest an extension charged (its debit
 *    ledger entry);
 *  - `penalty_charge`: the late penalty charged on a period, on the first day
 *    past its grace (never before the release);
 *  - `adjustment`: a penalty waiver, the penalty a closure wrote off with a
 *    period, a balance adjustment, a restructure or term extension of the
 *    schedule, a balance moved to a restructured loan, what an imported loan
 *    was paid before the import, and a balance restated to the schedule.
 *
 * Every figure is whole centavos. Payment, void and charge rows state their
 * figures as positive amounts and `kind` says which way they move the
 * balances; an `adjustment` row's figures are the signed change it made
 * (negative lowers the balance). The balances leave out the insurance premium
 * owed, which payment rows show in their own `insurance` column.
 *
 * The last row's balances are the summary's outstanding principal, interest
 * and penalty (Loan::owedCentavos()). Every row but two is a recorded fact:
 *  - a restructure or term extension of the schedule records no interest
 *    figure, so the row of the last such rebuild carries the interest the
 *    schedule changed by (only interest: a rebuild carries the principal it
 *    moves, and the penalty it closes is its own row). With two or more
 *    rebuilds the earlier ones show none: no record says what each changed,
 *    since the periods a rebuild deletes leave no trace;
 *  - a balance moved to a restructured loan clears whatever was left, so its
 *    row takes every column.
 * Anything else left between the recorded rows and the schedule (data from
 * before the rows recorded what they did, such as an extension that deleted
 * partly paid periods) is stated on an `adjustment` row of its own, "Balance
 * restated to the schedule", dated today, and logged, rather than spread over
 * earlier balances.
 */
final class LoanLedgerService
{
    private const RANKS = [
        'release' => 0,
        'penalty_charge' => 1,
        'imported' => 2,
        'repayment' => 3,
        'extension_payment' => 3,
        // An extension closes its periods (a write-off row) before it charges
        // the fresh interest, and both carry the same posting time.
        'adjustment' => 4,
        'extension_charge' => 5,
        'void' => 6,
    ];

    /** Adjustments that rebuild the schedule and record no interest figure. */
    private const REBUILD_TYPES = ['restructure', 'term_extension'];

    public const RESTATED = 'Balance restated to the schedule';

    public function __construct(private LoanService $loanService) {}

    /**
     * @return array{rows: list<array<string, mixed>>, closing: array{principal: float, interest: float, penalty: float, scb: float}}
     */
    public function forLoan(Loan $loan): array
    {
        $schedules = $loan->amortizationSchedules()->get();
        $owed = $loan->owedCentavos($schedules);

        if ($loan->released_at === null) {
            return [
                'rows' => [],
                'closing' => ['principal' => $owed['principal'] / 100, 'interest' => $owed['interest'] / 100, 'penalty' => $owed['penalty'] / 100, 'scb' => 0.0],
            ];
        }

        // reorder(): the relation carries latest(), which an orderBy only adds to.
        $adjustments = $loan->adjustments()->where('status', 'applied')->reorder('applied_at')->orderBy('id')->get();
        $entries = LoanLedgerEntry::where('loan_id', $loan->id)->orderBy('id')->get();
        $repayments = $loan->repayments()->orderBy('id')->get();
        $closures = $this->closures($adjustments);
        $debits = $entries->where('type', 'debit')->values();

        $events = [
            $this->releaseEvent($loan, $schedules, $adjustments, $debits),
            ...$this->importedEvents($loan, $schedules, $repayments),
            ...$this->penaltyEvents($loan, $schedules, $adjustments, $closures),
            ...$this->paymentEvents($repayments, $adjustments, $entries),
            ...$this->extensionChargeEvents($debits),
            ...$this->adjustmentEvents($loan, $adjustments, $closures),
        ];

        usort($events, fn (array $a, array $b): int => [$a['date'], $a['at'], $a['rank'], $a['seq']] <=> [$b['date'], $b['at'], $b['rank'], $b['seq']]);

        return $this->walk($this->landOnTheSchedule($loan, $events, $owed), $owed);
    }

    /**
     * The contract the loan was released with, in centavos: its principal and
     * the interest of the schedule it was released with
     * (originalInterestCentavos()), never a build-up or penalty. The Ledger's
     * release line holds the same two figures. It is how a restructured
     * source, whose unpaid periods its restructure deleted, still shows its
     * whole contract (LoanResource `contract_total` and `contract_interest`).
     *
     * @return array{principal: int, interest: int}
     */
    public function originalContractParts(Loan $loan): array
    {
        $adjustments = $loan->adjustments()->where('status', 'applied')->reorder('applied_at')->orderBy('id')->get();
        $debits = LoanLedgerEntry::where('loan_id', $loan->id)->where('type', 'debit')->orderBy('id')->get();

        return [
            'principal' => $this->centavos($loan->principal_amount),
            'interest' => $this->originalInterestCentavos($loan, $loan->amortizationSchedules()->get(), $adjustments, $debits),
        ];
    }

    /**
     * The release: the principal, the original schedule's interest
     * (originalInterestCentavos()) and the schedule's build-up.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @param  EloquentCollection<int, LoanLedgerEntry>  $debits
     * @return array<string, mixed>
     */
    private function releaseEvent(Loan $loan, EloquentCollection $schedules, EloquentCollection $adjustments, EloquentCollection $debits): array
    {
        $scb = $schedules->sum(fn (AmortizationSchedule $s): int => PersistedScheduleFigures::shareCapitalBuildUpCentavos($loan, $s));
        $principal = $this->centavos($loan->principal_amount);
        $interest = $this->originalInterestCentavos($loan, $schedules, $adjustments, $debits);

        return $this->event($loan->released_at, $loan->released_at, 'release', $loan->id, [
            'kind' => 'release',
            'description' => 'Loan released',
            'principal' => $principal,
            'interest' => $interest,
            'scb' => $scb,
        ], ['principal' => $principal, 'interest' => $interest, 'scb' => $scb]);
    }

    /**
     * The interest of the schedule the loan was released with, never solved
     * backwards from what is owed now.
     *
     * Its periods' interest is still on the rows unless a restructure or term
     * extension rebuilt the schedule, or a restructure into a new loan cleared
     * it: payments and waivers never change `interest_due`, a closed period's
     * unpaid interest moves into the period that carries it, and an extension
     * adds only its fresh interest (its debit entry, taken off here). After a
     * rebuild the original schedule is built again by the engine that built
     * it, from the loan as it stood before the first rebuild: that
     * adjustment's `old_values` (rate, term, its unit, frequency, method). A
     * rebuild recorded before `old_values` carried the term's unit takes the
     * unit the loan was created with, its product's (a rebuild to a weekly or
     * shorter frequency turns a months term into days).
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @param  EloquentCollection<int, LoanLedgerEntry>  $debits
     */
    private function originalInterestCentavos(Loan $loan, EloquentCollection $schedules, EloquentCollection $adjustments, EloquentCollection $debits): int
    {
        $firstRebuild = $adjustments->first(fn (LoanAdjustment $a) => in_array($a->adjustment_type, self::REBUILD_TYPES, true));

        if ($firstRebuild === null && $loan->restructured_at === null) {
            return $schedules->sum(fn (AmortizationSchedule $s): int => $this->centavos($s->interest_due))
                - $debits->sum(fn (LoanLedgerEntry $e): int => $this->centavos($e->amount));
        }

        $original = $loan->replicate();
        $before = $firstRebuild?->old_values ?? [];

        foreach (['interest_rate', 'term', 'frequency', 'interest_method', 'term_unit'] as $field) {
            if (($before[$field] ?? null) !== null) {
                $original->{$field} = $before[$field];
            }
        }

        if ($firstRebuild !== null && ($before['term_unit'] ?? null) === null && $loan->loanProduct?->term_unit !== null) {
            $original->term_unit = $loan->loanProduct->term_unit;
        }

        return collect($this->loanService->buildAmortizationPreview($original))
            ->sum(fn (array $row): int => $this->centavos($row['interest_due']));
    }

    /**
     * On a loan migrated in from an existing book, what its periods were paid
     * before the import, which no repayment here records.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @param  EloquentCollection<int, Repayment>  $repayments
     * @return list<array<string, mixed>>
     */
    private function importedEvents(Loan $loan, EloquentCollection $schedules, EloquentCollection $repayments): array
    {
        if (! $loan->isImported()) {
            return [];
        }

        $posted = $repayments->where('status', 'posted');
        $effect = [];

        foreach (['principal', 'interest', 'penalty'] as $column) {
            $effect[$column] = $posted->sum(fn (Repayment $r): int => $this->centavos($r->{"{$column}_applied"}))
                - $schedules->sum(fn (AmortizationSchedule $s): int => $this->centavos($s->{"{$column}_paid"}));
        }

        if ($effect === ['principal' => 0, 'interest' => 0, 'penalty' => 0]) {
            return [];
        }

        return [$this->event($loan->released_at, $loan->released_at, 'imported', $loan->id, [
            'kind' => 'adjustment',
            'description' => 'Paid before the loan was imported',
            ...$effect,
        ], $effect)];
    }

    /**
     * The late penalty on each period, one line per monthly charge, each on
     * the day it fell (RepaymentService::penaltyCharges(); never before the
     * release, for a loan released after its periods fell late, nor after
     * today).
     *
     * The period's total is what it carries now (its stamp is recalculated,
     * never below what was paid on it), plus what a waiver later forgave on it
     * and what a closure wrote off with it, which their own rows take off
     * again. The monthly charges are laid out in order until they make up
     * that total (penaltyLines()). A period a later extension or rebuild
     * deleted is charged what its waiver or closure recorded, on that
     * adjustment's day.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @param  array<int, array{waived: array<int, int>, written_off: array<int, int>, periods: array<int, int>}>  $closures
     * @return list<array<string, mixed>>
     */
    private function penaltyEvents(Loan $loan, EloquentCollection $schedules, EloquentCollection $adjustments, array $closures): array
    {
        $recorded = [];
        $periodNumbers = [];
        $firstRecordedOn = [];

        foreach ($adjustments as $adjustment) {
            foreach (['waived', 'written_off'] as $part) {
                foreach ($closures[$adjustment->id][$part] ?? [] as $scheduleId => $centavos) {
                    $recorded[$scheduleId] = ($recorded[$scheduleId] ?? 0) + $centavos;
                    $firstRecordedOn[$scheduleId] ??= $adjustment->applied_at;
                }
            }

            $periodNumbers += $closures[$adjustment->id]['periods'] ?? [];
        }

        $events = [];
        $today = Carbon::today();
        $releasedAt = Carbon::parse($loan->released_at);
        $schedules->loadMissing(['postedPrincipalPayments', 'voidedPrincipalPayments', 'principalChanges']);

        foreach ($schedules->sortBy('period_number') as $schedule) {
            $charged = $this->centavos($schedule->penalty_amount) + ($recorded[$schedule->id] ?? 0);
            unset($recorded[$schedule->id]);

            if ($charged <= 0) {
                continue;
            }

            foreach ($this->penaltyLines($loan, $schedule, $charged, $today) as $line) {
                $date = $line['on']->gt($today) ? $today : $line['on'];
                $postedAt = $date->lt($releasedAt) ? $releasedAt : $date;

                $events[] = $this->penaltyCharge($postedAt, $schedule->id, (int) $schedule->period_number, $line['centavos'], $line['number'], $line['rate'] ?? null);
            }
        }

        // Periods gone since: charged on the day their waiver or closure ran.
        foreach ($recorded as $scheduleId => $charged) {
            if ($charged > 0) {
                $day = Carbon::parse($firstRecordedOn[$scheduleId])->startOfDay();
                $events[] = $this->penaltyCharge($day->lt($releasedAt) ? $releasedAt : $day, $scheduleId, $periodNumbers[$scheduleId] ?? null, $charged);
            }
        }

        return $events;
    }

    /**
     * A period's penalty total (`$charged`, centavos: what it carries plus
     * what waivers forgave and closures wrote off) as one line per monthly
     * charge counted in it (`penalty_months`), each on the day it fell
     * (RepaymentService::penaltyCharges()). Every line is ₱0.00 or more and
     * the lines add up to the total, so the closing and the running penalty
     * balance hold.
     *
     * - One line for the whole total, on the first day past grace, as before
     *   the monthly penalty: a period waived for good (option a), and one with
     *   at most one charge in it (late before the switch with none since, or
     *   stamped by the once-only rule). No monthly line is made up for them.
     * - Otherwise the charges after the period's waiver checkpoint (or after
     *   its first charge, when it has none) are shown as worked. The charges
     *   up to it share what is left of the total in proportion to their
     *   amounts as charged on their days (the period's principal history, and
     *   a payment voided since still counted until its void, as the Ledger
     *   shows it), so the split is what was charged and the last of them
     *   takes any rounding.
     *
     * On a loan created under a penalty configuration each line carries the
     * rate of its charge (`rate`), for its wording; the single line takes the
     * rate of the bracket covering its day.
     *
     * @return list<array{on: Carbon, centavos: int, number: int, rate?: float}>
     */
    private function penaltyLines(Loan $loan, AmortizationSchedule $schedule, int $charged, Carbon $today): array
    {
        $lateFrom = $schedule->due_date->copy()->addDays(max(0, (int) $loan->grace_period_days) + 1)->startOfDay();
        $brackets = PenaltyTerms::bracketsOf($loan);
        $single = [[
            'on' => $lateFrom,
            'centavos' => $charged,
            'number' => 1,
            ...($brackets === null ? [] : ['rate' => BracketRate::rateFor($brackets, (int) $schedule->due_date->copy()->startOfDay()->diffInDays($lateFrom))]),
        ]];
        $months = (int) $schedule->penalty_months;

        if ($schedule->isWaivedForGood() || $months <= 1) {
            return $single;
        }

        $repayments = app(RepaymentService::class);
        $charges = array_slice($repayments->penaltyCharges($loan, $schedule, $today), 0, $months);
        // The months up to the checkpoint are weighed as they were charged,
        // beside the payments the Ledger shows with them.
        $asCharged = array_column(array_slice($repayments->penaltyCharges($loan, $schedule, $today, asCharged: true), 0, $months), 'centavos', 'number');

        if (count($charges) < 2) {
            return $single;
        }

        $checkpointOn = $schedule->penalty_checkpoint_on?->copy()->startOfDay();
        $upTo = [];
        $after = [];

        foreach ($charges as $index => $charge) {
            $isBefore = $checkpointOn === null ? $index === 0 : $charge['on']->lte($checkpointOn);

            if ($isBefore) {
                $upTo[] = [...$charge, 'centavos' => $asCharged[$charge['number']] ?? $charge['centavos']];
            } elseif ($charge['centavos'] > 0) {
                $after[] = $charge;
            }
        }

        $upToTotal = $charged - array_sum(array_column($after, 'centavos'));

        if ($upTo === [] || $upToTotal < 0) {
            return $single;
        }

        return [...$this->shareOut($upTo, $upToTotal), ...$after];
    }

    /**
     * `$total` centavos over `$charges` in proportion to their worked amounts,
     * the last taking the rounding; all on the first when none was worked.
     * Lines of ₱0.00 are left out, but one line always stays.
     *
     * @param  non-empty-list<array{on: Carbon, centavos: int, number: int}>  $charges
     * @return non-empty-list<array{on: Carbon, centavos: int, number: int}>
     */
    private function shareOut(array $charges, int $total): array
    {
        $worked = array_sum(array_column($charges, 'centavos'));

        if ($worked <= 0) {
            return [[...$charges[0], 'centavos' => $total]];
        }

        $lines = [];
        $left = $total;
        $last = array_key_last($charges);

        foreach ($charges as $index => $charge) {
            $amount = $index === $last ? $left : intdiv($total * $charge['centavos'], $worked);
            $left -= $amount;

            if ($amount > 0) {
                $lines[] = [...$charge, 'centavos' => $amount];
            }
        }

        return $lines === [] ? [[...$charges[0], 'centavos' => $total]] : $lines;
    }

    /**
     * "Late penalty, period 2, month 3". A line of a loan created under a
     * penalty configuration adds its bracket's rate, never rounded: "Late
     * penalty, period 2, month 3, at 2.5%".
     *
     * @return array<string, mixed>
     */
    private function penaltyCharge(CarbonInterface $postedAt, int $scheduleId, ?int $periodNumber, int $charged, int $number = 1, ?float $rate = null): array
    {
        $description = match (true) {
            $periodNumber === null => 'Late penalty',
            $number > 1 => "Late penalty, period {$periodNumber}, month {$number}",
            default => "Late penalty, period {$periodNumber}",
        };

        if ($periodNumber !== null && $rate !== null) {
            $description .= ', at '.BracketRate::percent($rate);
        }

        return $this->event($postedAt, $postedAt, 'penalty_charge', $scheduleId, [
            'kind' => 'penalty_charge',
            'description' => $description,
            'penalty' => $charged,
        ], ['penalty' => $charged]);
    }

    /**
     * Each payment from its own `*_applied` columns, and each voided one put
     * back on the day it was voided.
     *
     * @param  EloquentCollection<int, Repayment>  $repayments
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @param  EloquentCollection<int, LoanLedgerEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function paymentEvents(EloquentCollection $repayments, EloquentCollection $adjustments, EloquentCollection $entries): array
    {
        // An extension's collection: the repayment the extension names, or for
        // one recorded before that link, the repayment its credit entry names.
        $extensionOf = $entries->where('type', 'credit')->whereNotNull('repayment_id')
            ->mapWithKeys(fn (LoanLedgerEntry $e): array => [(int) $e->repayment_id => $e->loan_adjustment_id])
            ->all();

        foreach ($adjustments->where('adjustment_type', 'extension') as $extension) {
            if (($extension->new_values['repayment_id'] ?? null) !== null) {
                $extensionOf[(int) $extension->new_values['repayment_id']] = $extension->id;
            }
        }

        $scbCredited = ShareCapitalLedger::whereIn('repayment_id', $repayments->pluck('id'))
            ->where('credit', '>', 0)
            ->get()
            ->groupBy('repayment_id')
            ->map(fn ($rows): int => $rows->sum(fn ($row): int => $this->centavos($row->credit)));

        $events = [];

        foreach ($repayments as $repayment) {
            $parts = [
                'principal' => $this->centavos($repayment->principal_applied),
                'interest' => $this->centavos($repayment->interest_applied),
                'penalty' => $this->centavos($repayment->penalty_applied),
                'insurance' => $this->centavos($repayment->insurance_applied),
                'scb' => (int) ($scbCredited[$repayment->id] ?? 0),
            ];
            $lowers = ['principal' => -$parts['principal'], 'interest' => -$parts['interest'], 'penalty' => -$parts['penalty'], 'scb' => -$parts['scb']];
            $isExtension = array_key_exists($repayment->id, $extensionOf);
            $kind = $isExtension ? 'extension_payment' : 'repayment';

            $events[] = $this->event($repayment->payment_date, $repayment->created_at, $kind, $repayment->id, [
                'kind' => $kind,
                'repayment_id' => $repayment->id,
                'loan_adjustment_id' => $isExtension ? $extensionOf[$repayment->id] : null,
                'description' => ($isExtension ? 'Extension payment ' : 'Payment ').$repayment->receipt_number
                    .($repayment->status === 'voided' ? ' (voided)' : ''),
                ...$parts,
            ], $lowers);

            if ($repayment->status === 'voided' && $repayment->voided_at !== null) {
                $events[] = $this->event($repayment->voided_at, $repayment->voided_at, 'void', $repayment->id, [
                    'kind' => 'void',
                    'repayment_id' => $repayment->id,
                    'description' => "Payment {$repayment->receipt_number} voided",
                    ...$parts,
                ], array_map(fn (int $c): int => -$c, $lowers));
            }
        }

        return $events;
    }

    /**
     * @param  EloquentCollection<int, LoanLedgerEntry>  $debits
     * @return list<array<string, mixed>>
     */
    private function extensionChargeEvents(EloquentCollection $debits): array
    {
        return $debits->map(function (LoanLedgerEntry $entry): array {
            $interest = $this->centavos($entry->amount);

            return $this->event($entry->entry_date, $entry->created_at, 'extension_charge', $entry->id, [
                'kind' => 'extension_charge',
                'loan_adjustment_id' => $entry->loan_adjustment_id,
                'description' => $entry->description ?: 'Interest charged for the extended term',
                'interest' => $interest,
            ], ['interest' => $interest]);
        })->values()->all();
    }

    /**
     * Penalty waivers (what each forgave, from its audit row), the penalty a
     * closure wrote off with its periods (from its `periods_closed` audit
     * row), balance adjustments (their signed principal change), schedule
     * rebuilds, and the balance moved to a restructured loan. A rebuild and a
     * move record no interest figure; landOnTheSchedule() gives them theirs.
     *
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @param  array<int, array{waived: array<int, int>, written_off: array<int, int>, periods: array<int, int>}>  $closures
     * @return list<array<string, mixed>>
     */
    private function adjustmentEvents(Loan $loan, EloquentCollection $adjustments, array $closures): array
    {
        $events = [];

        foreach ($adjustments as $adjustment) {
            $writtenOff = array_sum($closures[$adjustment->id]['written_off'] ?? []);

            if ($writtenOff > 0) {
                $events[] = $this->event($adjustment->applied_at, $adjustment->applied_at, 'adjustment', $adjustment->id, [
                    'kind' => 'adjustment',
                    'loan_adjustment_id' => $adjustment->id,
                    'description' => trim("Penalty closed with the period {$adjustment->adjustment_number}"),
                    'penalty' => -$writtenOff,
                ], ['penalty' => -$writtenOff]);
            }

            $effect = match ($adjustment->adjustment_type) {
                'penalty_waiver' => ['penalty' => -array_sum($closures[$adjustment->id]['waived'] ?? [])],
                'balance_adjustment' => ['principal' => $this->centavos($adjustment->new_values['adjustment_amount'] ?? 0)],
                'restructure', 'term_extension' => [],
                default => null,
            };

            if ($effect === null) {
                continue;
            }

            $events[] = $this->event($adjustment->applied_at, $adjustment->applied_at, 'adjustment', $adjustment->id, [
                'kind' => 'adjustment',
                'loan_adjustment_id' => $adjustment->id,
                'description' => $this->adjustmentDescription($adjustment),
                ...$effect,
            ], $effect, landing: in_array($adjustment->adjustment_type, self::REBUILD_TYPES, true) ? 'rebuild' : null);
        }

        if ($loan->restructured_at !== null) {
            $events[] = $this->event($loan->restructured_at, $loan->restructured_at, 'adjustment', $loan->id, [
                'kind' => 'adjustment',
                'description' => 'Balance moved to a restructured loan',
            ], [], landing: 'move');
        }

        return $events;
    }

    private function adjustmentDescription(LoanAdjustment $adjustment): string
    {
        $label = match ($adjustment->adjustment_type) {
            'penalty_waiver' => 'Penalty waiver',
            'balance_adjustment' => 'Balance adjustment',
            'restructure' => 'Schedule restructured',
            'term_extension' => 'Term extended',
            default => 'Adjustment',
        };

        return trim("{$label} {$adjustment->adjustment_number}");
    }

    /**
     * Lands the last row on what the schedule says is owed now.
     *
     * A move to a restructured loan takes every column of what is left. A
     * rebuild takes only interest, the last rebuild all of it. Anything else
     * goes on a "Balance restated to the schedule" row dated today, and is
     * logged, since the recorded rows should account for it.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array{principal: int, interest: int, penalty: int}  $owed
     * @return list<array<string, mixed>>
     */
    private function landOnTheSchedule(Loan $loan, array $events, array $owed): array
    {
        $difference = ['principal' => $owed['principal'], 'interest' => $owed['interest'], 'penalty' => $owed['penalty']];

        foreach ($events as $event) {
            foreach ($difference as $column => $centavos) {
                $difference[$column] = $centavos - $event['effect'][$column];
            }
        }

        $move = $rebuild = null;

        foreach ($events as $index => $event) {
            if ($event['landing'] === 'move') {
                $move = $index;
            } elseif ($event['landing'] === 'rebuild') {
                $rebuild = $index;
            }
        }

        $carry = function (int $index, array $columns) use (&$events, &$difference): void {
            foreach ($columns as $column) {
                $events[$index]['effect'][$column] += $difference[$column];
                $events[$index]['row'][$column] += $difference[$column];
                $difference[$column] = 0;
            }
        };

        if ($move !== null) {
            $carry($move, ['principal', 'interest', 'penalty']);
        } elseif ($rebuild !== null) {
            $carry($rebuild, ['interest']);
        }

        if ($difference === ['principal' => 0, 'interest' => 0, 'penalty' => 0]) {
            return $events;
        }

        Log::warning('Loan ledger: recorded rows do not reach the schedule; stating the difference as a restated balance.', [
            'loan_id' => $loan->id,
            'principal' => $difference['principal'] / 100,
            'interest' => $difference['interest'] / 100,
            'penalty' => $difference['penalty'] / 100,
        ]);

        $events[] = $this->event(Carbon::today(), Carbon::now(), 'adjustment', PHP_INT_MAX, [
            'kind' => 'adjustment',
            'description' => self::RESTATED,
        ], []);
        $carry(array_key_last($events), ['principal', 'interest', 'penalty']);

        return $events;
    }

    /**
     * The running balances after each row. Share capital build-up is what the
     * schedule's build-up still awaits, never below zero, from the build-up
     * credited so far.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array{principal: int, interest: int, penalty: int}  $owed
     * @return array{rows: list<array<string, mixed>>, closing: array{principal: float, interest: float, penalty: float, scb: float}}
     */
    private function walk(array $events, array $owed): array
    {
        $balance = ['principal' => 0, 'interest' => 0, 'penalty' => 0, 'scb' => 0];
        $rows = [];

        foreach ($events as $event) {
            foreach ($balance as $column => $sum) {
                $balance[$column] = $sum + $event['effect'][$column];
            }

            $row = $event['row'];

            $rows[] = [
                'date' => $row['date'],
                'kind' => $row['kind'],
                'repayment_id' => $row['repayment_id'],
                'loan_adjustment_id' => $row['loan_adjustment_id'],
                'description' => $row['description'],
                'principal' => $row['principal'] / 100,
                'interest' => $row['interest'] / 100,
                'penalty' => $row['penalty'] / 100,
                'insurance' => $row['insurance'] / 100,
                'scb' => $row['scb'] / 100,
                'balances' => [
                    'principal' => $balance['principal'] / 100,
                    'interest' => $balance['interest'] / 100,
                    'penalty' => $balance['penalty'] / 100,
                    'scb' => max(0, $balance['scb']) / 100,
                ],
            ];
        }

        return [
            'rows' => $rows,
            'closing' => [
                'principal' => $owed['principal'] / 100,
                'interest' => $owed['interest'] / 100,
                'penalty' => $owed['penalty'] / 100,
                'scb' => max(0, $balance['scb']) / 100,
            ],
        ];
    }

    /**
     * What each adjustment recorded about the periods it touched, from its
     * audit rows, read in one query: per schedule, the penalty a waiver
     * forgave (`penalty_waived`) and the unpaid penalty a closure wrote off
     * (`periods_closed`: charged less paid, before it), and each period's
     * number.
     *
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @return array<int, array{waived: array<int, int>, written_off: array<int, int>, periods: array<int, int>}> by adjustment id, centavos by schedule id
     */
    private function closures(EloquentCollection $adjustments): array
    {
        if ($adjustments->isEmpty()) {
            return [];
        }

        $audits = AuditLog::whereIn('action', ['penalty_waived', 'periods_closed'])
            ->where('auditable_type', (new LoanAdjustment)->getMorphClass())
            ->whereIn('auditable_id', $adjustments->pluck('id'))
            ->orderBy('id')
            ->get();

        $closures = [];

        foreach ($audits as $audit) {
            $id = (int) $audit->auditable_id;
            $closures[$id] ??= ['waived' => [], 'written_off' => [], 'periods' => []];

            if ($audit->action === 'penalty_waived') {
                foreach ($audit->new_values['periods'] ?? [] as $period) {
                    $scheduleId = (int) ($period['schedule_id'] ?? 0);
                    $closures[$id]['waived'][$scheduleId] = ($closures[$id]['waived'][$scheduleId] ?? 0) + $this->centavos($period['waived'] ?? 0);
                    $closures[$id]['periods'][$scheduleId] = (int) ($period['period_number'] ?? 0);
                }

                continue;
            }

            foreach ($audit->old_values['periods'] ?? [] as $period) {
                $scheduleId = (int) ($period['schedule_id'] ?? 0);
                $unpaid = $this->centavos($period['penalty_amount'] ?? 0) - $this->centavos($period['penalty_paid'] ?? 0);

                if ($unpaid > 0) {
                    $closures[$id]['written_off'][$scheduleId] = ($closures[$id]['written_off'][$scheduleId] ?? 0) + $unpaid;
                }

                $closures[$id]['periods'][$scheduleId] = (int) ($period['period_number'] ?? 0);
            }
        }

        return $closures;
    }

    /**
     * @param  array<string, mixed>  $row  the row's own fields; money in centavos
     * @param  array<string, int>  $effect  what it moves each balance by, in centavos
     * @param  'rebuild'|'move'|null  $landing  which difference landOnTheSchedule() may give it
     * @return array<string, mixed>
     */
    private function event(
        CarbonInterface|string $date,
        CarbonInterface|string|null $postedAt,
        string $rank,
        int $sequence,
        array $row,
        array $effect,
        ?string $landing = null,
    ): array {
        $day = Carbon::parse($date)->toDateString();

        return [
            'date' => $day,
            'at' => $postedAt === null ? $day.' 00:00:00' : Carbon::parse($postedAt)->toDateTimeString(),
            'rank' => self::RANKS[$rank],
            'seq' => $sequence,
            'landing' => $landing,
            'row' => array_merge([
                'date' => $day,
                'repayment_id' => null,
                'loan_adjustment_id' => null,
                'principal' => 0,
                'interest' => 0,
                'penalty' => 0,
                'insurance' => 0,
                'scb' => 0,
            ], $row),
            'effect' => array_merge(['principal' => 0, 'interest' => 0, 'penalty' => 0, 'scb' => 0], $effect),
        ];
    }

    private function centavos(mixed $pesos): int
    {
        return LoanService::toCentavos((float) ($pesos ?? 0));
    }
}
