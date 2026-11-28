<?php

namespace App\Services\Cic\Capture;

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicSubject;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cic\CicSwitch;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordBuilder;
use App\Services\Cic\Records\CicRecordSet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Freezes every reportable record's CSDF values at a reference date (plan
 * decision 2): files and corrections are built only from a capture.
 *
 * - `month_end`: the schedule's capture. Idempotent: a date that already has
 *   a complete month-end capture gets that capture back, untouched.
 * - `test`: staging only, while `cic.test_captures` is on.
 * - `correction`: for a complete month-end capture's date (or, while test
 *   captures are on, a complete test capture's). Subject and event
 *   records are rebuilt from today's member data for the members it held.
 *   Its contract records keep the corrected capture's record type and money
 *   and date fields, and re-read their branch, key and classification from
 *   today (CI3/CN3, CI7/CN7, CI8/CN8, CI22/CN21, CI23; CicCorrectionOverlay),
 *   so a month whose file couldn't be made can still be filed (prompt §14,
 *   money review M-H1). The loans the month end left out are checked again
 *   (re-review MM1). A correction
 *   file made from it corrects a file made from that capture (or from an
 *   earlier correction of it).
 *
 * The first capture that reports a member gives them a subject register row
 * (subject_no = their member code, first_captured_at set once), and the first
 * that reports a loan freezes its record type (`loans.cic_record_type`) and
 * `loans.cic_contract_no` from its display number, unless that is over 38 characters (an issue) or another loan
 * already holds it.
 *
 * Records are written in batches, one transaction each, and the capture is
 * marked complete with its checksum, or failed with an error in words. Every
 * capture writes audit rows that name ids, counts and the checksum, never a
 * member's values.
 */
final class CicCaptureService
{
    public const BATCH = 500;

    public const KINDS = [CicCapture::KIND_MONTH_END, CicCapture::KIND_TEST, CicCapture::KIND_CORRECTION];

    public const FAILURE = 'The capture couldn\'t finish, so nothing in it can be used. Try again; if it fails again, ask support to check the server log.';

    public function __construct(private readonly CicRecordBuilder $builder) {}

    public function capture(Carbon $date, string $kind, ?User $by, ?CicCapture $corrects = null): CicCapture
    {
        $date = $date->copy()->startOfDay();
        $this->assertAllowed($date, $kind, $corrects);

        if ($kind === CicCapture::KIND_MONTH_END) {
            $existing = $this->completeMonthEnd($date);

            if ($existing !== null) {
                return $existing;
            }
        }

        $capture = new CicCapture;
        $capture->forceFill([
            'reference_date' => $date->toDateString(),
            'kind' => $kind,
            'corrects_capture_id' => $corrects?->id,
            'status' => CicCapture::STATUS_CAPTURING,
            'started_at' => now(),
            'created_by' => $by?->id,
        ])->save();

        try {
            $this->fill($capture, $date, $corrects);
        } catch (Throwable $e) {
            // Never report() the exception itself: a failed insert's message
            // holds its SQL with members' values filled in.
            Log::error('CIC capture failed.', [
                'capture_id' => $capture->id,
                'exception' => $e::class,
                'sqlstate' => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null,
            ]);
            $this->fail($capture, $by);

            return $capture->refresh();
        }

        return $capture->refresh();
    }

    /** The date's complete month-end capture, when it has one. */
    public function completeMonthEnd(Carbon $date): ?CicCapture
    {
        return CicCapture::query()
            ->where('kind', CicCapture::KIND_MONTH_END)
            ->where('status', CicCapture::STATUS_COMPLETE)
            ->whereDate('reference_date', $date->toDateString())
            ->first();
    }

    /**
     * A correction corrects a month-end capture, or, while test captures are
     * on (never in production), a test capture, so staging can rehearse a
     * correction of a file it made.
     */
    public static function correctable(CicCapture $capture): bool
    {
        return $capture->kind === CicCapture::KIND_MONTH_END
            || ($capture->kind === CicCapture::KIND_TEST && app(CicSwitch::class)->testCaptures());
    }

    private function assertAllowed(Carbon $date, string $kind, ?CicCapture $corrects): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a test or correction capture.']);
        }

        if ($kind === CicCapture::KIND_TEST && ! app(CicSwitch::class)->testCaptures()) {
            throw ValidationException::withMessages(['kind' => 'Test captures are off on this server. Month-end figures are captured by the schedule at 23:58 on the last day of each month.']);
        }

        if ($kind === CicCapture::KIND_CORRECTION) {
            if ($corrects === null || ! $corrects->isComplete() || ! self::correctable($corrects)) {
                throw ValidationException::withMessages(['corrects_capture_id' => 'A correction corrects a complete month-end capture. On a server with test captures on, it may correct a complete test capture too. Choose one.']);
            }

            if ($corrects->reference_date->toDateString() !== $date->toDateString()) {
                throw ValidationException::withMessages(['corrects_capture_id' => 'A correction is for the same reference date as the capture it corrects.']);
            }
        } elseif ($corrects !== null) {
            throw ValidationException::withMessages(['corrects_capture_id' => 'Only a correction names a capture to correct.']);
        }
    }

    private function fill(CicCapture $capture, Carbon $date, ?CicCapture $corrects): void
    {
        if ($corrects !== null) {
            $memberIds = DB::table('cic_capture_records')
                ->join('cic_subjects', 'cic_subjects.id', '=', 'cic_capture_records.cic_subject_id')
                ->where('cic_capture_records.capture_id', $corrects->id)
                ->where('cic_capture_records.record_type', 'ID')
                ->whereNotNull('cic_subjects.borrower_id')
                ->pluck('cic_subjects.borrower_id')->map(fn (mixed $id) => (int) $id)->all();

            $frozen = CicCaptureRecord::query()->where('capture_id', $corrects->id)->whereIn('record_type', ['CI', 'CN'])
                ->get(['id', 'record_type', 'cic_subject_id', 'loan_id', 'record_key', 'values', 'build_issues']);
            $loanIds = $frozen->pluck('loan_id')->filter()->map(fn (mixed $id) => (int) $id)->values()->all();

            $live = $this->builder->build($date, withContracts: $loanIds !== [], memberIds: $memberIds, loanIds: $loanIds, force: true);
            $contracts = $this->correctedContracts($frozen, $live);

            // The loans the month end left out are checked again, never
            // dropped (money re-review MM1): each is left out again with its
            // issue, or, once fixed, reported as an added contract.
            $heldBack = array_values(array_diff(array_map('intval', array_filter(array_column($corrects->left_out ?? [], 'loan_id'))), $loanIds));
            $rechecked = $heldBack === [] ? null : $this->builder->build($date, loanIds: $heldBack, onlyReportable: false);

            $set = new CicRecordSet($live->context, [
                'ID' => self::union($live->ofType('ID'), $rechecked?->ofType('ID') ?? []),
                'CI' => [...$contracts['CI'], ...($rechecked?->ofType('CI') ?? [])],
                'CN' => [...$contracts['CN'], ...($rechecked?->ofType('CN') ?? [])],
                'NE' => self::union($live->ofType('NE'), $rechecked?->ofType('NE') ?? []),
            ], self::leftOutOf($corrects, $rechecked));
        } else {
            $set = $this->builder->build($date);
        }

        $subjectIds = $this->registerSubjects($set->ofType('ID'));
        $this->freezeContractNumbers([...$set->ofType('CI'), ...$set->ofType('CN')]);
        $this->freezeRecordTypes([...$set->ofType('CI'), ...$set->ofType('CN')]);

        foreach (['ID', 'CI', 'CN', 'NE'] as $type) {
            foreach (array_chunk($set->ofType($type), self::BATCH) as $batch) {
                DB::transaction(fn () => CicCaptureRecord::query()->insert(array_map(
                    fn (CicRecord $record) => $this->row($capture, $record, $subjectIds),
                    $batch,
                )));
            }
        }

        $leftOut = $set->leftOut;

        DB::transaction(function () use ($capture, $leftOut): void {
            $counts = DB::table('cic_capture_records')->where('capture_id', $capture->id)
                ->groupBy('record_type')->selectRaw('record_type, COUNT(*) as total')->pluck('total', 'record_type');

            $capture->forceFill([
                'subject_count' => (int) ($counts['ID'] ?? 0),
                'contract_count' => (int) ($counts['CI'] ?? 0) + (int) ($counts['CN'] ?? 0),
                'event_count' => (int) ($counts['NE'] ?? 0),
                'left_out' => $leftOut === [] ? null : $leftOut,
                'checksum' => $capture->computeChecksum(),
                'status' => CicCapture::STATUS_COMPLETE,
                'finished_at' => now(),
            ])->save();

            AuditLogService::log('cic_capture_completed', $capture, null, [
                'capture_id' => $capture->id,
                'reference_date' => $capture->reference_date->toDateString(),
                'kind' => $capture->kind,
                'corrects_capture_id' => $capture->corrects_capture_id,
                'subject_count' => $capture->subject_count,
                'contract_count' => $capture->contract_count,
                'event_count' => $capture->event_count,
                'checksum' => $capture->checksum,
            ], sprintf(
                'CIC %s capture #%d for %s completed: %d subjects, %d contracts, %d events (checksum %s).',
                self::kindWords($capture->kind),
                $capture->id,
                $capture->reference_date->format('M j, Y'),
                $capture->subject_count,
                $capture->contract_count,
                $capture->event_count,
                substr((string) $capture->checksum, 0, 12),
            ), $capture->created_by, asSystem: $capture->created_by === null);
        });
    }

    /**
     * Each subject's register row, made the first time (subject_no = member
     * code, first captured now). Returns member id → register id.
     *
     * @param  list<CicRecord>  $subjects
     * @return array<int, int>
     */
    private function registerSubjects(array $subjects): array
    {
        $ids = [];

        foreach (array_chunk($subjects, self::BATCH) as $batch) {
            $memberIds = array_map(fn (CicRecord $r) => (int) $r->borrowerId, $batch);
            $existing = CicSubject::query()->whereIn('borrower_id', $memberIds)->pluck('id', 'borrower_id')->all();

            DB::transaction(function () use ($batch, $existing, &$ids): void {
                foreach ($batch as $record) {
                    $memberId = (int) $record->borrowerId;

                    if (isset($existing[$memberId])) {
                        $ids[$memberId] = (int) $existing[$memberId];

                        continue;
                    }

                    $subject = new CicSubject;
                    $subject->forceFill([
                        'subject_type' => CicSubject::TYPE_MEMBER,
                        'borrower_id' => $memberId,
                        'subject_no' => $record->key,
                        'first_captured_at' => now(),
                    ])->save();

                    $ids[$memberId] = $subject->id;
                }
            });
        }

        return $ids;
    }

    /**
     * Freezes `loans.cic_contract_no` the first time a loan is reported. A
     * number over 38 characters is never a record key (the builder keys that
     * record by the loan's id), and a number another loan holds, or two of
     * this build's loans share, isn't frozen: the validator reports it.
     *
     * @param  list<CicRecord>  $contracts
     */
    private function freezeContractNumbers(array $contracts): void
    {
        $byNumber = [];

        // Case-folded, as the unique index compares (re-review SN2).
        $numbers = [];

        foreach ($contracts as $record) {
            if ($record->loanId !== null && $record->key !== "loan-{$record->loanId}") {
                $folded = mb_strtolower($record->key);
                $byNumber[$folded][] = $record->loanId;
                $numbers[$folded] = $record->key;
            }
        }

        foreach (array_chunk(array_keys($byNumber), self::BATCH) as $folded) {
            $held = DB::table('loans')->whereIn('cic_contract_no', array_map(fn (string $key) => $numbers[$key], $folded))->pluck('cic_contract_no')
                ->mapWithKeys(fn (mixed $number) => [mb_strtolower((string) $number) => true])->all();

            DB::transaction(function () use ($folded, $byNumber, $numbers, $held): void {
                foreach ($folded as $key) {
                    $loanIds = $byNumber[$key];

                    if (count($loanIds) !== 1 || isset($held[$key])) {
                        continue;
                    }

                    DB::table('loans')->where('id', $loanIds[0])->whereNull('cic_contract_no')
                        ->update(['cic_contract_no' => (string) $numbers[$key]]);
                }
            });
        }
    }

    /**
     * Freezes `loans.cic_record_type` the first time a loan is reported: the
     * record type its own structure gave it then (CicRecord::$structure).
     *
     * @param  list<CicRecord>  $contracts
     */
    private function freezeRecordTypes(array $contracts): void
    {
        $byType = [];

        foreach ($contracts as $record) {
            if ($record->loanId !== null && in_array($record->structure, ['CI', 'CN'], true)) {
                $byType[$record->structure][] = $record->loanId;
            }
        }

        foreach ($byType as $type => $loanIds) {
            foreach (array_chunk($loanIds, self::BATCH) as $chunk) {
                DB::table('loans')->whereIn('id', $chunk)->whereNull('cic_record_type')->update(['cic_record_type' => $type]);
            }
        }
    }

    /**
     * A correction's contracts: each of the corrected capture's contract
     * records, its figures kept and its classification and key re-read from
     * today's build (CicCorrectionOverlay). A record whose loan today's build
     * can't read is kept as it was.
     *
     * @param  Collection<int, CicCaptureRecord>  $frozen
     * @return array{CI: list<CicRecord>, CN: list<CicRecord>}
     */
    private function correctedContracts(Collection $frozen, CicRecordSet $live): array
    {
        $byLoan = [];

        foreach ([...$live->ofType('CI'), ...$live->ofType('CN')] as $record) {
            $byLoan[$record->loanId] = $record;
        }

        $borrowers = DB::table('loans')->whereIn('id', $frozen->pluck('loan_id')->filter()->all())->pluck('borrower_id', 'id')->all();
        $contracts = ['CI' => [], 'CN' => []];

        foreach ($frozen as $row) {
            $today = $row->loan_id === null ? null : ($byLoan[$row->loan_id] ?? null);
            $record = $today === null
                ? new CicRecord($row->record_type, $row->record_key, $row->values, $row->build_issues ?? [], isset($borrowers[$row->loan_id]) ? (int) $borrowers[$row->loan_id] : null, $row->loan_id)
                : CicCorrectionOverlay::merge($row->record_type, $row->values, $row->build_issues ?? [], $today);

            $contracts[$record->type][] = $record;
        }

        return $contracts;
    }

    /**
     * Records of two builds, each key once.
     *
     * @param  list<CicRecord>  $first
     * @param  list<CicRecord>  $second
     * @return list<CicRecord>
     */
    private static function union(array $first, array $second): array
    {
        $byKey = [];

        foreach ([...$first, ...$second] as $record) {
            $byKey[$record->key] ??= $record;
        }

        return array_values($byKey);
    }

    /**
     * What a correction leaves out: the re-check of the loans its month end
     * left out, plus any of the month end's left-out issues that name no loan
     * (kept as they were).
     *
     * @return list<array<string, mixed>>
     */
    private static function leftOutOf(CicCapture $corrects, ?CicRecordSet $rechecked): array
    {
        $unnamed = array_values(array_filter($corrects->left_out ?? [], fn (array $issue) => empty($issue['loan_id'])));

        return [...$unnamed, ...($rechecked?->leftOut ?? [])];
    }

    /**
     * @param  array<int, int>  $subjectIds
     * @return array<string, mixed>
     */
    private function row(CicCapture $capture, CicRecord $record, array $subjectIds): array
    {
        $now = now();

        return [
            'capture_id' => $capture->id,
            'record_type' => $record->type,
            'cic_subject_id' => $record->borrowerId === null ? null : ($subjectIds[$record->borrowerId] ?? null),
            'loan_id' => $record->loanId,
            'record_key' => $record->key,
            'values' => self::json($record->values),
            'build_issues' => $record->buildIssues === [] ? null : self::json($record->buildIssues),
            'checksum' => $record->checksum(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function fail(CicCapture $capture, ?User $by): void
    {
        $capture->refresh();

        if ($capture->status !== CicCapture::STATUS_CAPTURING) {
            return;
        }

        // Nothing of a failed capture is kept: its partial records hold
        // members' data and can never be filed. They go while it's still
        // capturing, the only time a record can be removed.
        CicCaptureRecord::query()->where('capture_id', $capture->id)->select(['id', 'capture_id'])
            ->lazyById(self::BATCH)->each(fn (CicCaptureRecord $record) => $record->delete());

        $capture->forceFill([
            'status' => CicCapture::STATUS_FAILED,
            'finished_at' => now(),
            'error' => self::FAILURE,
        ])->save();

        AuditLogService::log('cic_capture_failed', $capture, null, [
            'capture_id' => $capture->id,
            'reference_date' => $capture->reference_date->toDateString(),
            'kind' => $capture->kind,
            'status' => CicCapture::STATUS_FAILED,
        ], sprintf('CIC %s capture #%d for %s failed; nothing in it can be used.', self::kindWords($capture->kind), $capture->id, $capture->reference_date->format('M j, Y')),
            $by?->id, asSystem: $by === null);
    }

    public static function kindWords(string $kind): string
    {
        return match ($kind) {
            CicCapture::KIND_MONTH_END => 'month-end',
            CicCapture::KIND_TEST => 'test',
            CicCapture::KIND_CORRECTION => 'correction',
            default => $kind,
        };
    }

    /** @param  array<mixed>  $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
