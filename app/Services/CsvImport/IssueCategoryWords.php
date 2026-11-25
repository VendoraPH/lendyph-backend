<?php

namespace App\Services\CsvImport;

/**
 * The import report's codes in words, for the downloaded error report.
 *
 * Every issue carries a code (`date_invalid`, `membership_number_taken`) that
 * the report groups by. A code is not for reading, so the download's Category
 * column shows these words, the same words the Data Import screen shows
 * (lendyph-frontend, `import-issue-words.ts`): keep the two in step. A code with
 * no words here still reads as words (its own, spaced), never as a key.
 */
final class IssueCategoryWords
{
    /** @var array<string, string> */
    public const WORDS = [
        // Reading the row
        'row_column_count' => 'Wrong number of columns',
        'required' => 'Required field blank',
        'truncated' => 'Shortened to fit',
        'invalid' => 'Not a value the import accepts',

        // Dates
        'date_invalid' => 'Not a date',
        'date_required' => 'Date blank',
        'ambiguous_date' => 'Date reads two ways',
        'excel_serial_date' => 'Excel date number',
        'birthdate_future' => 'Birthdate in the future',
        'birthdate_implausible' => 'Birthdate before 1900',
        'birthdate_under_18' => 'Member under 18',
        'date_hired_future' => 'Date hired in the future',
        'date_hired_implausible' => 'Date hired too early',
        'date_hired_before_birthdate' => 'Date hired before birthdate',
        'membership_date_future' => 'Membership date in the future',
        'transaction_date_future' => 'Transaction date in the future',
        'transaction_date_implausible' => 'Transaction date before 1900',

        // Contact details
        'contact_number_invalid' => 'Contact number not accepted',
        'contact_number_multiple' => 'More than one contact number',
        'contact_number_unusable' => 'Contact number unusable',
        'email_invalid' => 'Email not accepted',

        // Choices
        'enum_invalid' => 'Value not recognised',
        'enum_required' => 'Choice blank',
        'enum_unmapped' => 'Value not recognised, left blank',
        'member_type_invalid' => 'Member type not recognised',
        'transaction_type_invalid' => 'Transaction type not recognised',
        'transaction_type_required' => 'Transaction type blank',

        // Membership number
        'membership_number_invalid' => 'Membership number has a character it can\'t hold',
        'membership_number_too_long' => 'Membership number too long',
        'membership_number_member_code' => 'Membership number looks like a member code',
        'membership_number_placeholder' => 'Membership number is a placeholder',
        'membership_number_spaces' => 'Membership number stored without spaces',
        'membership_number_taken' => 'Membership number already used',
        'membership_needs_member_edit' => 'Membership details need permission to edit members',

        // Numbers and money
        'integer_invalid' => 'Not a whole number',
        'integer_out_of_range' => 'Number out of range',
        'integer_required' => 'Number blank',
        'money_invalid' => 'Not an amount',
        'money_negative' => 'Negative amount',
        'money_overflow' => 'Amount too large',
        'money_precision' => 'More than two decimal places',
        'money_required' => 'Amount blank',
        'amount_zero' => 'Amount of ₱0.00',
        'amount_above_app_maximum' => 'Amount above the member form\'s limit',
        'rate_invalid' => 'Not an interest rate',
        'rate_overflow' => 'Interest rate too large',
        'rate_required' => 'Interest rate blank',

        // A loan's figures and schedule
        'amount_not_positive' => 'Loan amount not above zero',
        'balance_exceeds_amount' => 'Balance above the loan amount',
        'balance_out_of_range' => 'Balance out of range',
        'interest_negative' => 'Negative interest',
        'interest_balance_exceeds_interest' => 'Interest balance above the interest',
        'interest_balance_out_of_range' => 'Interest balance out of range',
        'maturity_not_after_release' => 'Maturity not after release',
        'maturity_off_schedule' => 'Maturity off the payment schedule',
        'term_disagrees_with_dates' => 'Term doesn\'t match the dates',
        'term_too_long' => 'Term too long',
        'allocation_residue' => 'Payments don\'t spread across the schedule',
        'principal_ladder_fallback' => 'Schedule doesn\'t add up to the loan amount',
        'invariant_violation' => 'Schedule doesn\'t add up',
        'unexplained_fee' => 'Other fee without a description',
        'deductions_exceed_principal' => 'Fees above the principal',
        'schedule_not_reconstructable' => 'Payment schedule can\'t be rebuilt',
        'out_of_product_bounds' => 'Outside the loan product\'s limits',

        // An identifier past its column, refused rather than cut short: a loan's
        // number, and an account number in any file (the share capital file's
        // account number or member ID included).
        'loan_no_too_long' => 'Loan number too long',
        'account_no_too_long' => 'Account number too long',

        // A share capital entry
        'reference_number_too_long' => 'Reference number too long',
        'duplicate_in_file' => 'Repeats an earlier row of the file',
        'duplicate_reference' => 'Reference already on the ledger',
        'duplicate_transaction' => 'Same entry already on the ledger',
        'withdrawal_exceeds_balance' => 'Withdrawal above the balance',
        'cash_reference_too_large' => 'Cash reference number too large',
        'share_capital_needs_permission' => 'Share capital needs permission to record entries',

        // Matching members, and what became of the row
        'borrower_not_found' => 'Member not found',
        'ambiguous_match' => 'More than one matching member',
        'not_a_member' => 'Registration, not a member',
        'member_withdrawn' => 'Member has withdrawn',
        'member_moved' => 'Member moved to another branch',
        'branch_closed' => 'Member\'s branch is closed or closing',
        'account_no_conflict' => 'Account number already on another member',
        'already_imported' => 'Already imported',
        'matched_existing' => 'Matched a member already in Lendyph',
        'imported_with_warnings' => 'Imported with warnings',
        'invalid_row' => 'Not imported, as the row has errors',
        'import_failed' => 'Not imported',
        'skipped' => 'Skipped',
        'abandoned' => 'Given up after repeated attempts',
        'exception' => 'Could not be written',

        // The download's own last line when it stops early
        'export_failed' => 'Report stopped early',
    ];

    /** The prefix of a loan whose product has no match; the product follows it. */
    private const UNMAPPED_PRODUCT = 'unmapped_product:';

    /**
     * A report category in words.
     */
    public static function for(string $code): string
    {
        if (str_starts_with($code, self::UNMAPPED_PRODUCT)) {
            $product = substr($code, strlen(self::UNMAPPED_PRODUCT));

            return $product === '(blank)' ? 'No loan product' : "Loan product not matched: {$product}";
        }

        return self::WORDS[$code] ?? ucfirst(trim((string) preg_replace('/[_:]+/', ' ', $code)));
    }
}
