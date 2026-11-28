<?php

namespace App\Services\Cic\Settings;

use App\Models\CicCaptureRecord;
use App\Models\CicLoanOverride;
use App\Models\CicSubmission;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cic\Records\CicContractClassifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A loan's own CIC classification over its product's mapping (prompt §3.3),
 * with the reason staff gave. Each create, change and removal writes an audit
 * row naming the loan by its display number, with the reason. The request
 * checks the loan is reportable and the codes are CIC's.
 *
 * An override's `contract_no` (review M-H1) must be unique among other loans'
 * CIC contract numbers and display numbers and other overrides, can't differ
 * from a contract number the loan already reports by, and is kept (no change,
 * no removal of the override) once a file submitted to CIC carries it.
 */
final class CicLoanOverrideService
{
    /** Loans CIC reports: every loan that was ever released. */
    public const REPORTABLE_STATUSES = Loan::EVER_RELEASED_STATUSES;

    private const FIELDS = ['record_type', 'contract_type', 'transaction_type'];

    public const CONTRACT_NO_MAX = 38;

    public const CONTRACT_NO_PATTERN = '/^[A-Za-z0-9-]+$/';

    /** @var list<string> a file's statuses once it has gone to CIC */
    public const FILED_STATUSES = [CicSubmission::STATUS_SUBMITTED, CicSubmission::STATUS_ACCEPTED, CicSubmission::STATUS_REJECTED];

    public const MISSING = 'There is no such CIC override.';

    public const BUSY = 'Someone else changed this loan\'s CIC override at the same moment. Reload the page and try again.';

    /** @param  array{loan_id: int, record_type?: string|null, contract_type?: string|null, transaction_type?: string|null, reason: string}  $values */
    public function create(array $values, User $user): CicLoanOverride
    {
        try {
            return DB::transaction(function () use ($values, $user) {
                $loan = Loan::query()->whereKey($values['loan_id'])->lockForUpdate()->firstOrFail();

                if (CicLoanOverride::query()->where('loan_id', $loan->id)->lockForUpdate()->exists()) {
                    abort(409, 'This loan already has a CIC override. Change or remove that one instead.');
                }

                $contractNo = self::contractNo($values['contract_no'] ?? null);
                $this->assertContractNoUsable($loan, $contractNo, null);

                $override = new CicLoanOverride(['loan_id' => $loan->id, ...$this->codes($values), 'contract_no' => $contractNo, 'reason' => $values['reason']]);
                $override->forceFill(['created_by' => $user->id, 'updated_by' => $user->id])->save();
                $after = $this->words($override);

                AuditLogService::log('created', $override, null, ['loan' => $loan->displayNumber(), ...$after, 'reason' => $override->reason],
                    "CIC override set on loan {$loan->displayNumber()}: ".$this->list($after).". Reason: {$override->reason}", $user->id);

                return $override;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, self::BUSY);
        }
    }

    /** @param  array{record_type?: string|null, contract_type?: string|null, transaction_type?: string|null, contract_no?: string|null, reason: string}  $values */
    public function update(int $overrideId, array $values, User $user): CicLoanOverride
    {
        try {
            return DB::transaction(function () use ($overrideId, $values, $user) {
                $locked = $this->locked($overrideId);
                $before = $this->words($locked);
                $changes = [...$this->codes($values), 'reason' => $values['reason']];

                if (array_key_exists('contract_no', $values)) {
                    $changes['contract_no'] = self::contractNo($values['contract_no']);
                }

                $locked->fill($changes);

                if (! $locked->isDirty()) {
                    return $locked;
                }

                if ($locked->isDirty('contract_no')) {
                    $this->assertContractNoKept($locked);
                    $this->assertContractNoUsable($locked->loan()->firstOrFail(), $locked->contract_no, $locked->id);
                }

                $locked->forceFill(['updated_by' => $user->id])->save();
                $after = $this->words($locked);
                $number = $locked->loan?->displayNumber();

                AuditLogService::log('updated', $locked,
                    ['loan' => $number, ...$before, 'reason' => $locked->getOriginal('reason')],
                    ['loan' => $number, ...$after, 'reason' => $locked->reason],
                    "CIC override on loan {$number} changed: ".$this->list($after).". Reason: {$locked->reason}", $user->id);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, self::BUSY);
        }
    }

    public function delete(int $overrideId, string $reason, User $user): void
    {
        DB::transaction(function () use ($overrideId, $reason, $user) {
            $locked = $this->locked($overrideId);
            $this->assertContractNoKept($locked, removing: true);
            $number = $locked->loan?->displayNumber();
            $before = $this->words($locked);

            AuditLogService::log('deleted', $locked, ['loan' => $number, ...$before, 'reason' => $locked->reason], ['reason' => $reason],
                "CIC override removed from loan {$number}; its product's mapping applies again. Reason: {$reason}", $user->id);

            $locked->delete();
        });
    }

    /**
     * The override contract numbers among these that a file submitted to CIC
     * (submitted, accepted or rejected) carries as a contract, in one query.
     *
     * @param  list<string>  $numbers
     * @return array<string, true>
     */
    public static function reportedContractNos(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter($numbers, fn ($number) => is_string($number) && $number !== '')));

        if ($numbers === []) {
            return [];
        }

        return CicCaptureRecord::query()->toBase()
            ->join('cic_submissions', 'cic_submissions.capture_id', '=', 'cic_capture_records.capture_id')
            ->whereIn('cic_submissions.status', self::FILED_STATUSES)
            ->whereIn('cic_capture_records.record_type', ['CI', 'CN'])
            ->whereIn('cic_capture_records.record_key', $numbers)
            ->distinct()
            ->pluck('cic_capture_records.record_key')
            ->mapWithKeys(fn ($number) => [(string) $number => true])
            ->all();
    }

    private function locked(int $overrideId): CicLoanOverride
    {
        /** @var CicLoanOverride|null $override */
        $override = CicLoanOverride::query()->whereKey($overrideId)->lockForUpdate()->first();

        return $override ?? abort(404, self::MISSING);
    }

    /** A sent contract number, trimmed; blank is none. */
    private static function contractNo(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * The stored contract number is kept once a submitted file carries it:
     * CIC knows the contract by it.
     */
    private function assertContractNoKept(CicLoanOverride $override, bool $removing = false): void
    {
        $stored = $override->getOriginal('contract_no');

        if (! is_string($stored) || self::reportedContractNos([$stored]) === []) {
            return;
        }

        throw ValidationException::withMessages(['contract_no' => [$removing
            ? "This override's contract number {$stored} is in a file already submitted to CIC, so the override stays: CIC knows the contract by that number. Change its codes instead."
            : "The contract number {$stored} is in a file already submitted to CIC, so it can't change: CIC knows the contract by that number."]]);
    }

    /**
     * A new contract number names no other contract: no other loan's CIC
     * contract number or display number, and no other override's. It also
     * can't differ from a contract number the loan already reports by.
     */
    private function assertContractNoUsable(Loan $loan, ?string $contractNo, ?int $overrideId): void
    {
        if ($contractNo === null) {
            return;
        }

        if ($loan->cic_contract_no !== null && $loan->cic_contract_no !== $contractNo) {
            throw ValidationException::withMessages(['contract_no' => ["Loan {$loan->displayNumber()} already reports to CIC as {$loan->cic_contract_no}, and a contract number never changes once set."]]);
        }

        $takenByLoan = Loan::query()->whereKeyNot($loan->id)
            ->where(fn ($query) => $query->where('cic_contract_no', $contractNo)
                ->orWhere('loan_account_number', $contractNo)
                ->orWhere('external_loan_no', $contractNo))
            ->exists();

        $takenByOverride = CicLoanOverride::query()->where('contract_no', $contractNo)
            ->when($overrideId !== null, fn ($query) => $query->whereKeyNot($overrideId))
            ->exists();

        if ($takenByLoan || $takenByOverride) {
            throw ValidationException::withMessages(['contract_no' => ["The contract number {$contractNo} already names another loan's contract. Choose one no other loan uses."]]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    private function codes(array $values): array
    {
        $codes = [];

        foreach (self::FIELDS as $field) {
            $codes[$field] = ($values[$field] ?? null) ?: null;
        }

        return $codes;
    }

    /** @return array<string, string|null> each field with its label; null keeps the product's value */
    private function words(CicLoanOverride $override): array
    {
        $override->loadMissing('loan');
        $recordType = $override->loan === null ? $override->record_type
            : ($override->record_type ?? CicContractClassifier::structure($override->loan));

        return [
            'record_type' => $override->record_type,
            'contract_type' => CicClassificationCodes::words($recordType, 'contract_type', $override->contract_type),
            'transaction_type' => CicClassificationCodes::words($recordType, 'transaction_type', $override->transaction_type),
            'contract_no' => $override->contract_no,
        ];
    }

    /** @param  array<string, string|null>  $words  a contract number shows only when the override sets one */
    private function list(array $words): string
    {
        $parts = [];

        foreach ($words as $field => $value) {
            $parts[] = match (true) {
                $field !== 'contract_no' => CicProductMappingService::fieldWords($field).' '.($value ?? 'the product\'s'),
                $value !== null => "contract number {$value}",
                default => null,
            };
        }

        return implode('; ', array_filter($parts));
    }
}
