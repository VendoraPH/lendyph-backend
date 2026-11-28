<?php

namespace App\Services\Cic\Settings;

use App\Services\Cic\CicDomains;
use App\Services\Cic\CicLayouts;

/**
 * The CIC classification a loan product's mapping or a loan's override sets
 * (prompt §3.3), checked against the code lists the record's own fields use:
 *
 * - contract type: CI8 (CI:ContractTypeDomain) or CN8 (CN:ContractTypeDomain);
 * - transaction type: CI22 or CN21 (TransactionTypeDomain);
 * - purpose of credit: CI23 (CreditPurposeDomain). A CN record has no such
 *   field, so a CN classification takes no purpose code.
 *
 * Codes are compared exactly, never substituted (§3.2).
 */
final class CicClassificationCodes
{
    public const RECORD_TYPES = ['CI', 'CN'];

    /** Each record type's field ids for the three codes. */
    private const FIELDS = [
        'CI' => ['contract_type' => 'CI8', 'transaction_type' => 'CI22', 'purpose_code' => 'CI23'],
        'CN' => ['contract_type' => 'CN8', 'transaction_type' => 'CN21', 'purpose_code' => null],
    ];

    private const WORDS = [
        'contract_type' => 'contract type',
        'transaction_type' => 'transaction type',
        'purpose_code' => 'purpose of credit',
    ];

    /** The code list a record type's code is checked against, or null when the record has no such field. */
    public static function domain(string $recordType, string $code): ?string
    {
        $fieldId = self::FIELDS[$recordType][$code] ?? null;

        return $fieldId === null ? null : CicLayouts::field($fieldId)['domain'] ?? null;
    }

    /**
     * Why a code can't be used for the record type, in words, or null when it
     * can. A null or empty value is never checked here.
     */
    public static function refusal(string $recordType, string $code, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $domain = self::domain($recordType, $code);
        $words = self::WORDS[$code];

        if ($domain === null) {
            return "A {$recordType} contract has no {$words}; leave it empty.";
        }

        return CicDomains::contains($domain, $value)
            ? null
            : "\"{$value}\" isn't one of CIC's {$words} codes for a {$recordType} contract.";
    }

    /** A code with its label, "12 Personal Loan", for audit text and screens. */
    public static function words(?string $recordType, string $code, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $domain = $recordType === null ? null : self::domain($recordType, $code);
        $label = $domain === null ? null : CicDomains::label($domain, $value);

        return $label === null ? $value : "{$value} {$label}";
    }

    /** The label of a code, or null. */
    public static function label(?string $recordType, string $code, ?string $value): ?string
    {
        if ($value === null || $value === '' || $recordType === null) {
            return null;
        }

        $domain = self::domain($recordType, $code);

        return $domain === null ? null : CicDomains::label($domain, $value);
    }

    /** The words for a code field ("contract type"). */
    public static function fieldWords(string $code): string
    {
        return self::WORDS[$code];
    }
}
