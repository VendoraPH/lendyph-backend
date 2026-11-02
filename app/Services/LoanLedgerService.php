<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

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
 *    repayment's `*_applied` columns (an extension's collection is the
 *    repayment its credit ledger entry points at; the credit entry itself is
 *    not counted again), and the share capital it credited;
 *  - `void`: a voided payment put back, on the day it was voided;
 *  - `extension_charge`: the fresh interest an extension charged (its debit
 *    ledger entry);
 *  - `penalty_charge`: the late penalty charged on a period, on the first day
 *    past its grace;
 *  - `adjustment`: a penalty waiver, a balance adjustment, a restructure or
 *    term extension of the schedule, a balance moved to a restructured loan,
 *    and, on an imported loan, what was paid before the import.
 *
 * Every figure is whole centavos. Payment, void and charge rows state their
 * figures as positive amounts and `kind` says which way they move the
 * balances; an `adjustment` row's figures are the signed change it made
 * (negative lowers the balance).
 *
 * The last row's balances are the summary's outstanding principal, interest
 * and penalty (Loan::owedCentavos()). Most rows' figures are recorded facts;
 * a restructure or term extension of the schedule records no interest figure,
 * so the row of the last such change (or of a move to a restructured loan)
 * carries what the schedule changed by. With none, a difference left by data
 * from before the rows recorded what they did (an extension that deleted
 * partly paid periods, say) is stated on an `adjustment` row of its own,
 * dated today, rather than spread over earlier balances.
 */
final class LoanLedgerService
{
    private const RANKS = [
        'release' => 0,
        'imported' => 1,
        'penalty_charge' => 2,
        'repayment' => 3,
        'extension_payment' => 3,
        'extension_charge' => 4,
        'adjustment' => 5,
        'void' => 6,
    ];

    /** Adjustments that rebuild the schedule and record no interest figure. */
    private const RESCHEDULING_TYPES = ['restructure', 'term_extension'];

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

        $adjustments = $loan->adjustments()->where('status', 'applied')->orderBy('applied_at')->orderBy('id')->get();
        $debits = LoanLedgerEntry::where('loan_id', $loan->id)->where('type', 'debit')->orderBy('id')->get();
        $waived = $this->waivedCentavos($adjustments);

        $events = [
            $this->releaseEvent($loan, $schedules, $adjustments, $debits),
            ...$this->importedEvents($loan, $schedules),
            ...$this->penaltyEvents($loan, $schedules, $waived),
            ...$this->paymentEvents($loan),
            ...$this->extensionChargeEvents($debits),
            ...$this->adjustmentEvents($loan, $adjustments, $waived),
        ];

        usort($events, fn (array $a, array $b): int => [$a['date'], $a['at'], $a['rank'], $a['seq']] <=> [$b['date'], $b['at'], $b['rank'], $b['seq']]);

        $events = $this->landOnTheSchedule($events, $owed);

        return $this->walk($events, $owed);
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
        $scb = 0;

        foreach ($schedules as $schedule) {
            $scb += PersistedScheduleFigures::shareCapitalBuildUpCentavos($loan, $schedule);
        }

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
     * it, from the loan as it stood before the first rebuild (that
     * adjustment's `old_values`).
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @param  EloquentCollection<int, LoanLedgerEntry>  $debits
     */
    private function originalInterestCentavos(Loan $loan, EloquentCollection $schedules, EloquentCollection $adjustments, EloquentCollection $debits): int
    {
        $firstRebuild = $adjustments->first(fn (LoanAdjustment $a) => in_array($a->adjustment_type, self::RESCHEDULING_TYPES, true));

        if ($firstRebuild === null && $loan->restructured_at === null) {
            return $schedules->sum(fn (AmortizationSchedule $s): int => $this->centavos($s->interest_due))
                - $debits->sum(fn (LoanLedgerEntry $e): int => $this->centavos($e->amount));
        }

        $original = $loan->replicate();
        $before = $firstRebuild?->old_values ?? [];

        foreach (['interest_rate', 'term', 'frequency'] as $field) {
            if (array_key_exists($field, $before) && $before[$field] !== null) {
                $original->{$field} = $before[$field];
            }
        }

        return collect($this->loanService->buildAmortizationPreview($original))
            ->sum(fn (array $row): int => $this->centavos($row['interest_due']));
    }

    /**
     * On a loan migrated in from an existing book, what its periods were paid
     * before the import, which no repayment here records.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @return list<array<string, mixed>>
     */
    private function importedEvents(Loan $loan, EloquentCollection $schedules): array
    {
        if (! $loan->isImported()) {
            return [];
        }

        $posted = $loan->repayments()->where('status', 'posted')->get();
        $before = [
            'principal' => $schedules->sum(fn ($s): int => $this->centavos($s->principal_paid)) - $posted->sum(fn ($r): int => $this->centavos($r->principal_applied)),
            'interest' => $schedules->sum(fn ($s): int => $this->centavos($s->interest_paid)) - $posted->sum(fn ($r): int => $this->centavos($r->interest_applied)),
            'penalty' => $schedules->sum(fn ($s): int => $this->centavos($s->penalty_paid)) - $posted->sum(fn ($r): int => $this->centavos($r->penalty_applied)),
        ];

        if ($before === ['principal' => 0, 'interest' => 0, 'penalty' => 0]) {
            return [];
        }

        $effect = array_map(fn (int $paid): int => -$paid, $before);

        return [$this->event($loan->released_at, $loan->released_at, 'imported', $loan->id, [
            'kind' => 'adjustment',
            'description' => 'Paid before the loan was imported',
            ...$effect,
        ], $effect)];
    }

    /**
     * The late penalty on each period, on the first day past its grace (never
     * before the release, for a loan released after its periods fell late): what
     * the period is charged now (its stamp is recalculated, never below what
     * was paid on it), plus what a waiver later forgave on it, which the
     * waiver's own row takes off again.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @param  array<int, int>  $waived  centavos waived per schedule id
     * @return list<array<string, mixed>>
     */
    private function penaltyEvents(Loan $loan, EloquentCollection $schedules, array $waived): array
    {
        $events = [];
        $today = Carbon::today();
        $releasedAt = Carbon::parse($loan->released_at);

        foreach ($schedules->sortBy('period_number') as $schedule) {
            $charged = $this->centavos($schedule->penalty_amount) + ($waived[$schedule->id] ?? 0);

            if ($charged <= 0) {
                continue;
            }

            $lateFrom = $schedule->due_date->copy()->addDays(max(0, (int) $loan->grace_period_days) + 1)->startOfDay();
            $date = $lateFrom->gt($today) ? $today : $lateFrom;
            $postedAt = $date->lt($releasedAt) ? $releasedAt : $date;

            $events[] = $this->event($postedAt, $postedAt, 'penalty_charge', $schedule->id, [
                'kind' => 'penalty_charge',
                'description' => "Late penalty, period {$schedule->period_number}",
                'penalty' => $charged,
            ], ['penalty' => $charged]);
        }

        return $events;
    }

    /**
     * Each payment from its own `*_applied` columns, and each voided one put
     * back on the day it was voided.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentEvents(Loan $loan): array
    {
        $repayments = $loan->repayments()->orderBy('id')->get();
        $ids = $repayments->pluck('id');

        // An extension's collection: the repayment its credit entry names.
        $extensionCredits = LoanLedgerEntry::where('loan_id', $loan->id)
            ->where('type', 'credit')
            ->whereNotNull('repayment_id')
            ->pluck('loan_adjustment_id', 'repayment_id');

        $scbCredited = ShareCapitalLedger::whereIn('repayment_id', $ids)
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
            $isExtension = $extensionCredits->has($repayment->id);
            $kind = $isExtension ? 'extension_payment' : 'repayment';

            $events[] = $this->event($repayment->payment_date, $repayment->created_at, $kind, $repayment->id, [
                'kind' => $kind,
                'repayment_id' => $repayment->id,
                'loan_adjustment_id' => $isExtension ? $extensionCredits[$repayment->id] : null,
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
     * Penalty waivers (what each forgave, from its audit row), balance
     * adjustments (their signed principal change), schedule rebuilds, and the
     * balance moved to a restructured loan. A rebuild and a move record no
     * interest figure; landOnTheSchedule() gives them theirs.
     *
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @param  array<int, int>  $waived
     * @return list<array<string, mixed>>
     */
    private function adjustmentEvents(Loan $loan, EloquentCollection $adjustments, array $waived): array
    {
        $events = [];
        $waivedBy = $this->waivedByAdjustment($adjustments);

        foreach ($adjustments as $adjustment) {
            $effect = match ($adjustment->adjustment_type) {
                'penalty_waiver' => ['penalty' => -($waivedBy[$adjustment->id] ?? 0)],
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
            ], $effect, rescheduling: in_array($adjustment->adjustment_type, self::RESCHEDULING_TYPES, true));
        }

        if ($loan->restructured_at !== null) {
            $events[] = $this->event($loan->restructured_at, $loan->restructured_at, 'adjustment', $loan->id, [
                'kind' => 'adjustment',
                'description' => 'Balance moved to a restructured loan',
            ], [], rescheduling: true);
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
     * Gives the last schedule rebuild (or move to a restructured loan) what
     * the recorded rows leave between them and what the schedule says is
     * owed now; with none, states any such difference on a row of its own.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array{principal: int, interest: int, penalty: int}  $owed
     * @return list<array<string, mixed>>
     */
    private function landOnTheSchedule(array $events, array $owed): array
    {
        $walked = ['principal' => 0, 'interest' => 0, 'penalty' => 0];

        foreach ($events as $event) {
            foreach ($walked as $column => $sum) {
                $walked[$column] = $sum + $event['effect'][$column];
            }
        }

        $difference = [
            'principal' => $owed['principal'] - $walked['principal'],
            'interest' => $owed['interest'] - $walked['interest'],
            'penalty' => $owed['penalty'] - $walked['penalty'],
        ];

        if ($difference === ['principal' => 0, 'interest' => 0, 'penalty' => 0]) {
            return $events;
        }

        $target = null;

        foreach ($events as $index => $event) {
            if ($event['rescheduling']) {
                $target = $index;
            }
        }

        if ($target === null) {
            $today = Carbon::today();
            $events[] = $this->event($today, Carbon::now(), 'adjustment', PHP_INT_MAX, [
                'kind' => 'adjustment',
                'description' => 'Balance restated to the schedule',
            ], []);
            $target = array_key_last($events);
        }

        foreach ($difference as $column => $centavos) {
            $events[$target]['effect'][$column] += $centavos;
            $events[$target]['row'][$column] += $centavos;
        }

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
     * What each penalty waiver forgave on each period, in centavos, from the
     * audit row it wrote (the period keeps only what is left).
     *
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @return array<int, int> centavos per schedule id
     */
    private function waivedCentavos(EloquentCollection $adjustments): array
    {
        $perSchedule = [];

        foreach ($this->waiverAudits($adjustments) as $audit) {
            foreach ($audit->new_values['periods'] ?? [] as $period) {
                $id = (int) ($period['schedule_id'] ?? 0);
                $perSchedule[$id] = ($perSchedule[$id] ?? 0) + $this->centavos($period['waived'] ?? 0);
            }
        }

        return $perSchedule;
    }

    /**
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @return array<int, int> centavos per adjustment id
     */
    private function waivedByAdjustment(EloquentCollection $adjustments): array
    {
        $perAdjustment = [];

        foreach ($this->waiverAudits($adjustments) as $audit) {
            $perAdjustment[(int) $audit->auditable_id] = ($perAdjustment[(int) $audit->auditable_id] ?? 0)
                + collect($audit->new_values['periods'] ?? [])->sum(fn (array $p): int => $this->centavos($p['waived'] ?? 0));
        }

        return $perAdjustment;
    }

    /**
     * @param  EloquentCollection<int, LoanAdjustment>  $adjustments
     * @return EloquentCollection<int, AuditLog>
     */
    private function waiverAudits(EloquentCollection $adjustments): EloquentCollection
    {
        $ids = $adjustments->where('adjustment_type', 'penalty_waiver')->pluck('id');

        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return AuditLog::where('action', 'penalty_waived')
            ->where('auditable_type', (new LoanAdjustment)->getMorphClass())
            ->whereIn('auditable_id', $ids)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $row  the row's own fields; money in centavos
     * @param  array<string, int>  $effect  what it moves each balance by, in centavos
     * @return array<string, mixed>
     */
    private function event(
        CarbonInterface|string $date,
        CarbonInterface|string|null $postedAt,
        string $rank,
        int $sequence,
        array $row,
        array $effect,
        bool $rescheduling = false,
    ): array {
        $day = Carbon::parse($date)->toDateString();

        return [
            'date' => $day,
            'at' => $postedAt === null ? $day.' 00:00:00' : Carbon::parse($postedAt)->toDateTimeString(),
            'rank' => self::RANKS[$rank],
            'seq' => $sequence,
            'rescheduling' => $rescheduling,
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
