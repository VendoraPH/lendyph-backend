<?php

namespace App\Services\Cic\Records;

use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\Loan;

/**
 * The one rule for a reported loan's CIC classification (prompt §3.3, §5.3),
 * used by the contract record builder and by the loan override's checks:
 *
 * - **Structure:** CN when the loan repays in one lump sum (frequency
 *   upon_maturity, or a one-period schedule), else CI.
 * - **Record type:** the loan override's `record_type` when set, else the
 *   structure.
 * - **Codes:** the product mapping gives its contract type, transaction type
 *   and purpose of credit only when its record type matches. A product mapped
 *   to the other record type gives none (`mappingMismatch`), so its contract
 *   type is missing unless the override supplies it. The override's own codes
 *   always win, and are checked against this record type.
 */
final class CicContractClassifier
{
    /** CI or CN by the loan's own repayment structure. */
    public static function structure(Loan $loan, ?int $periods = null): string
    {
        $periods ??= match (true) {
            $loan->relationLoaded('amortizationSchedules') => $loan->amortizationSchedules->count(),
            $loan->getAttribute('amortization_schedules_count') !== null => (int) $loan->getAttribute('amortization_schedules_count'),
            default => $loan->amortizationSchedules()->count(),
        };

        return $loan->frequency === 'upon_maturity' || $periods === 1 ? 'CN' : 'CI';
    }

    /**
     * @param  array{record_type?: string|null, contract_type?: string|null, transaction_type?: string|null}|CicLoanOverride|null  $override
     */
    public static function classify(string $structure, array|CicLoanOverride|null $override, ?CicProductMapping $mapping): CicContractClassification
    {
        $code = fn (string $field): ?string => self::filled($override instanceof CicLoanOverride ? $override->{$field} : ($override[$field] ?? null));

        $recordType = $code('record_type') ?? $structure;
        $applies = $mapping !== null && $mapping->record_type === $recordType;

        return new CicContractClassification(
            structure: $structure,
            recordType: $recordType,
            overridesRecordType: $code('record_type') !== null,
            hasMapping: $mapping !== null,
            mappingRecordType: $mapping?->record_type,
            mappingMismatch: $mapping !== null && ! $applies,
            contractType: $code('contract_type') ?? ($applies ? self::filled($mapping->contract_type) : null),
            transactionType: $code('transaction_type') ?? ($applies ? self::filled($mapping->transaction_type) : null),
            purposeCode: $applies && $recordType === 'CI' ? self::filled($mapping->purpose_code) : null,
        );
    }

    /** The classification of a loan as it stands: its structure, its override and its product's mapping. */
    public static function forLoan(Loan $loan, ?CicProductMapping $mapping, ?int $periods = null): CicContractClassification
    {
        return self::classify(self::structure($loan, $periods), $loan->cicOverride, $mapping);
    }

    private static function filled(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
