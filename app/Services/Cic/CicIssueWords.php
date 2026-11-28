<?php

namespace App\Services\Cic;

/**
 * CIC issues in words, for exports and anything else read by people: each
 * issue code's label, and the record types, severities and field
 * classifications. A stored code is never shown; one without a label reads
 * as a general check (a test keeps every code the checks raise labelled).
 */
final class CicIssueWords
{
    /** @var array<string, string> */
    public const CODES = [
        'ADDRESS_TYPE_UNEXPECTED' => 'Unexpected address type',
        'AFTER_FILE_REFERENCE_DATE' => 'Date after the reference date',
        'CLASSIFICATION_MISMATCH' => 'Product mapped to the other record type',
        'CONTRACT_NO_TOO_LONG' => 'Loan number too long',
        'CONTRACT_TYPE_FOR_OTHER_RECORD' => 'Contract codes missing for this record type',
        'DATE_ORDER' => 'Dates out of order',
        'DUPLICATE_CONTRACT' => 'Contract listed twice',
        'DUPLICATE_RECORD' => 'Record listed twice',
        'DUPLICATE_SUBJECT' => 'Subject listed twice',
        'EMPTY_FILE' => 'No records to report',
        'FIXED_VALUE' => 'Wrong fixed value',
        'FULL_ADDRESS_IGNORED' => 'Full address not used',
        'GUARANTEE_CURRENCY' => 'Guarantee currency',
        'GUARANTOR_NOT_IN_FILE' => 'Guarantor not in the file',
        'INVALID_DATE' => 'Not a valid date',
        'INVALID_NUMBER' => 'Not a whole number',
        'LINKED_SUBJECT_NOT_IN_FILE' => 'Linked subject not in the file',
        'MISSING_DEPENDENT' => 'Missing field another field needs',
        'MISSING_MANDATORY' => 'Missing required field',
        'MUST_BE_EMPTY' => 'Field must be empty',
        'NEGATIVE_NUMBER' => 'Negative number',
        'NO_IDENTIFICATION' => 'No identification on file',
        'NOT_IN_DOMAIN' => 'Not one of CIC\'s codes',
        'OPEN_NOTHING_OWED' => 'Open contract with nothing owed',
        'OVERDUE_ABOVE_OUTSTANDING' => 'Overdue above outstanding',
        'OVERDUE_DAYS_INCONSISTENT' => 'Overdue days don\'t match',
        'OVERDUE_INCONSISTENT' => 'Overdue figures don\'t match',
        'OVERRIDE_AGAINST_STRUCTURE' => 'Override differs from the loan\'s repayment',
        'PROVIDER_CODE' => 'Provider code not set',
        'SUBJECT_NOT_IN_FILE' => 'Member not in the file',
        'TOO_LONG' => 'Too long',
        'TOO_MANY_CO_MAKERS' => 'Too many co-makers',
        'TOO_MANY_GUARANTEES' => 'Too many guarantees',
        'UNKNOWN_RECORD_TYPE' => 'Unknown record type',
        'UNMAPPED_COLLATERAL_TYPE' => 'Collateral type not mapped',
        'UNMAPPED_PRODUCT' => 'Loan product not mapped',
    ];

    private const RECORD_TYPES = [
        'ID' => 'Subject (ID)',
        'CI' => 'Instalment contract (CI)',
        'CN' => 'Non-instalment contract (CN)',
        'NE' => 'Event (NE)',
        'HD' => 'File header (HD)',
        'FT' => 'File footer (FT)',
    ];

    private const CLASSIFICATIONS = ['M' => 'Mandatory', 'D' => 'Dependent', 'NM' => 'Optional'];

    public static function code(string $code): string
    {
        return self::CODES[$code] ?? 'CIC format check';
    }

    public static function recordType(string $type): string
    {
        return self::RECORD_TYPES[$type] ?? $type;
    }

    public static function severity(string $severity): string
    {
        return $severity === 'error' ? 'Error' : 'Warning';
    }

    public static function classification(?string $classification): string
    {
        return $classification === null ? '' : (self::CLASSIFICATIONS[$classification] ?? '');
    }
}
