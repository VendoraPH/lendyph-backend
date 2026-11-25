<?php

namespace Tests\Feature\CsvImport;

use App\Services\CsvImport\IssueCategoryWords;
use Tests\TestCase;

/**
 * The downloaded error report's Category column reads in words, the same words
 * the Data Import screen shows (lendyph-frontend, import-issue-words.ts).
 */
class IssueCategoryWordsTest extends TestCase
{
    /**
     * Every code the report can carry: the row notes' codes (the normalizers,
     * ValueNormalizer and LoanScheduleReconstructor), the import pass's
     * outcomes and match outcomes, and the report's own categories. The same
     * list as the screen's test, plus the report's `export_failed`.
     */
    private const CODES = [
        'account_no_conflict', 'allocation_residue', 'already_imported', 'ambiguous_date',
        'ambiguous_match', 'amount_above_app_maximum', 'amount_not_positive', 'abandoned',
        'balance_exceeds_amount', 'balance_out_of_range', 'birthdate_future', 'birthdate_implausible',
        'birthdate_under_18', 'borrower_not_found', 'contact_number_invalid', 'contact_number_multiple',
        'contact_number_unusable', 'date_hired_before_birthdate', 'date_hired_future',
        'date_hired_implausible', 'date_invalid', 'date_required', 'deductions_exceed_principal',
        'email_invalid', 'enum_invalid', 'enum_required', 'enum_unmapped', 'excel_serial_date',
        'exception', 'import_failed', 'imported_with_warnings', 'integer_invalid',
        'integer_out_of_range', 'integer_required', 'interest_balance_exceeds_interest',
        'interest_balance_out_of_range', 'interest_negative', 'invalid', 'invalid_row',
        'invariant_violation', 'matched_existing', 'maturity_not_after_release',
        'maturity_off_schedule', 'member_type_invalid', 'membership_date_future',
        'membership_needs_member_edit', 'membership_number_invalid', 'membership_number_member_code',
        'membership_number_placeholder', 'membership_number_spaces', 'membership_number_taken',
        'membership_number_too_long', 'money_invalid', 'money_negative', 'money_overflow',
        'money_precision', 'money_required', 'out_of_product_bounds', 'principal_ladder_fallback',
        'rate_invalid', 'rate_overflow', 'rate_required', 'required', 'row_column_count',
        'schedule_not_reconstructable', 'skipped', 'term_disagrees_with_dates', 'term_too_long',
        'truncated', 'unexplained_fee',
        'account_no_too_long', 'amount_zero', 'branch_closed', 'cash_reference_too_large',
        'duplicate_in_file', 'duplicate_reference', 'duplicate_transaction', 'member_moved',
        'member_withdrawn', 'not_a_member', 'reference_number_too_long', 'share_capital_needs_permission',
        'transaction_date_future', 'transaction_date_implausible', 'transaction_type_invalid',
        'transaction_type_required', 'withdrawal_exceeds_balance',
        'loan_no_too_long', 'loan_no_shortened_earlier', 'export_failed',
    ];

    public function test_every_code_the_report_carries_has_its_words(): void
    {
        $this->assertSame([], array_values(array_diff(self::CODES, array_keys(IssueCategoryWords::WORDS))));

        foreach (self::CODES as $code) {
            $this->assertDoesNotMatchRegularExpression('/[_:]/', IssueCategoryWords::for($code), $code);
        }
    }

    public function test_the_words_are_the_screens(): void
    {
        $this->assertSame('Not a date', IssueCategoryWords::for('date_invalid'));
        $this->assertSame('Member not found', IssueCategoryWords::for('borrower_not_found'));
        $this->assertSame('Loan number too long', IssueCategoryWords::for('loan_no_too_long'));
        $this->assertSame('Account number too long', IssueCategoryWords::for('account_no_too_long'));
        $this->assertSame("Outside the loan product's limits", IssueCategoryWords::for('out_of_product_bounds'));
        $this->assertSame('Report stopped early', IssueCategoryWords::for('export_failed'));
    }

    public function test_an_unmatched_product_reads_with_its_name_and_a_new_code_still_reads_as_words(): void
    {
        $this->assertSame('Loan product not matched: Salary Loan', IssueCategoryWords::for('unmapped_product:Salary Loan'));
        $this->assertSame('No loan product', IssueCategoryWords::for('unmapped_product:(blank)'));
        $this->assertSame('Some new code', IssueCategoryWords::for('some_new_code'));
    }
}
