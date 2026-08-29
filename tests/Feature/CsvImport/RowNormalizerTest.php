<?php

namespace Tests\Feature\CsvImport;

use App\Enums\LoanFrequency;
use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvRow;
use App\Services\CsvImport\CustomerRowNormalizer;
use App\Services\CsvImport\LoanRowNormalizer;
use App\Services\CsvImport\NormalizedRow;
use App\Services\CsvImport\RowNoteBag;
use App\Services\CsvImport\ValueNormalizer;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Tests\TestCase;

class RowNormalizerTest extends TestCase
{
    /**
     * @param  array<string, string>  $values
     */
    private function customerRow(array $values = []): NormalizedRow
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::CUSTOMERS);
        $cells = array_fill(0, count($keys), '');
        $defaults = ['account_no' => 'A-001', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan'];

        foreach (array_merge($defaults, $values) as $key => $value) {
            $cells[CsvImportSchema::indexOf(CsvImportSchema::CUSTOMERS, $key)] = $value;
        }

        return (new CustomerRowNormalizer)->normalize(new CsvRow(1, 1, $cells));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function loanRow(array $values = []): NormalizedRow
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::LOANS);
        $cells = array_fill(0, count($keys), '');
        $defaults = [
            'loan_no' => 'L-001',
            'account_no' => 'A-001',
            'loan_amount' => '10,000.00',
            'interest_rate' => '3',
            'interest_type' => 'Straight (Fixed)',
            'payment_frequency' => 'Monthly',
            'term_in_months' => '6',
            'date_released' => '2025-01-15',
            'maturity_date' => '2025-07-15',
            'interest_amount' => '1,800.00',
            'loan_balance' => '5,000.00',
            'interest_balance' => '900.00',
        ];

        foreach (array_merge($defaults, $values) as $key => $value) {
            $cells[CsvImportSchema::indexOf(CsvImportSchema::LOANS, $key)] = $value;
        }

        return (new LoanRowNormalizer)->normalize(new CsvRow(1, 1, $cells));
    }

    public function test_a_bom_prefixed_account_number_normalises_identically_to_a_bare_one(): void
    {
        // Belt and braces with the reader's own BOM strip. If these two ever
        // stop being equal, a member's loans orphan: the loan sheet says
        // "A-001" and the customer sheet says "\u{FEFF}A-001", and no join
        // between them finds anything.
        $withBom = $this->customerRow(['account_no' => "\u{FEFF}A-001"]);
        $bare = $this->customerRow(['account_no' => 'A-001']);

        $this->assertSame('A-001', $withBom->value('account_no'));
        $this->assertSame($bare->value('account_no'), $withBom->value('account_no'));
    }

    public function test_a_non_breaking_space_is_converted_and_trimmed_away(): void
    {
        // Excel emits U+00A0 wherever a value was pasted from a formatted
        // source. trim() does not touch it, so " A-001 " with NBSP padding
        // stays padded and joins nothing.
        $row = $this->customerRow(['account_no' => "\u{00A0}A-001\u{00A0}", 'first_name' => "Juan\u{00A0}Carlos"]);

        $this->assertSame('A-001', $row->value('account_no'));
        $this->assertSame('Juan Carlos', $row->value('first_name'));
    }

    public function test_an_empty_optional_cell_becomes_null_rather_than_failing_a_nullable_rule(): void
    {
        // ConvertEmptyStringsToNull is REQUEST middleware. It does not run
        // here, so without the explicit '' -> null map every blank optional
        // cell in the workbook fails ['nullable','date'].
        $row = $this->customerRow(['birthdate' => '', 'suffix' => '   ', 'middle_name' => '']);

        $this->assertTrue($row->isValid());
        $this->assertNull($row->value('birthdate'));
        $this->assertNull($row->value('suffix'));
        $this->assertNull($row->value('middle_name'));
        $this->assertSame([], $row->warningsToArray());
    }

    public function test_money_becomes_integer_centavos(): void
    {
        $row = $this->customerRow(['monthly_income' => '₱ 25,000.50', 'pledge_amount' => 'PHP 1,000']);

        $this->assertSame(2500050, $row->value('monthly_income'));
        $this->assertSame(100000, $row->value('pledge_amount'));
    }

    public function test_accounting_negatives_and_excess_precision_are_refused_not_rounded(): void
    {
        $notes = new RowNoteBag;
        $normalizer = new ValueNormalizer;

        $this->assertNull($normalizer->centavos('(1,234.00)', 'loan_balance', $notes));
        $this->assertContains('money_negative', $notes->errorCodes());

        $precision = new RowNoteBag;
        // Rounding silently changes a figure the member can check against their
        // passbook, so a human decides instead.
        $this->assertNull($normalizer->centavos('1234.567', 'loan_amount', $precision));
        $this->assertContains('money_precision', $precision->errorCodes());
    }

    public function test_an_overflowing_date_is_rejected_rather_than_silently_rolled_forward(): void
    {
        // Carbon::createFromFormat('m/d/Y', '13/45/2020') does not fail — it
        // returns 2021-02-14, a date in no cell of the file. Only the
        // round-trip check catches it.
        $this->assertSame('2021-02-14', Carbon::createFromFormat('m/d/Y', '13/45/2020')->toDateString());

        $row = $this->customerRow(['birthdate' => '13/45/2020']);

        $this->assertFalse($row->isValid());
        $this->assertSame(['date_invalid'], array_column($row->errorsToArray(), 'code'));
    }

    public function test_an_ambiguous_slash_date_is_read_us_order_and_warns_naming_both(): void
    {
        $row = $this->customerRow(['birthdate' => '03/04/1990']);

        $this->assertSame('1990-03-04', $row->value('birthdate'));

        $warning = collect($row->warningsToArray())->firstWhere('code', 'ambiguous_date');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('1990-03-04', $warning['message']);
        $this->assertStringContainsString('1990-04-03', $warning['message']);
    }

    public function test_an_unambiguous_slash_date_does_not_warn(): void
    {
        $row = $this->customerRow(['birthdate' => '12/25/1990']);

        $this->assertSame('1990-12-25', $row->value('birthdate'));
        $this->assertSame([], array_column($row->warningsToArray(), 'code'));
    }

    public function test_it_accepts_every_declared_date_format(): void
    {
        $formats = [
            '1990-03-04' => '1990-03-04',
            '1990/03/04' => '1990-03-04',
            '03/04/1990' => '1990-03-04',
            '3/4/1990' => '1990-03-04',
            '04-Mar-1990' => '1990-03-04',
            '4 Mar 1990' => '1990-03-04',
            'Mar 4, 1990' => '1990-03-04',
        ];

        foreach ($formats as $input => $expected) {
            $this->assertSame($expected, $this->customerRow(['birthdate' => (string) $input])->value('birthdate'), "Failed on [{$input}].");
        }
    }

    public function test_an_excel_serial_date_is_converted_with_a_warning(): void
    {
        // The commonest .xlsm artefact: a date column that was never formatted
        // as a date exports as its serial number.
        $row = $this->customerRow(['birthdate' => '32874']);

        $this->assertSame('1990-01-01', $row->value('birthdate'));
        $this->assertContains('excel_serial_date', array_column($row->warningsToArray(), 'code'));
    }

    public function test_all_six_payment_frequencies_map_to_the_enum(): void
    {
        $cases = [
            'Daily' => LoanFrequency::Daily,
            'Weekly' => LoanFrequency::Weekly,
            'Bi-Weekly' => LoanFrequency::BiWeekly,
            'Semi-Monthly' => LoanFrequency::SemiMonthly,
            'Monthly' => LoanFrequency::Monthly,
            'Upon Maturity' => LoanFrequency::UponMaturity,
        ];

        foreach ($cases as $label => $expected) {
            $row = $this->loanRow(['payment_frequency' => (string) $label]);
            $this->assertTrue($row->isValid(), "[{$label}] should normalise cleanly.");
            $this->assertSame($expected->value, $row->value('payment_frequency'));
        }

        // The workbook has no data-validation rules, so shouting and running
        // words together both arrive.
        $this->assertSame('bi_weekly', $this->loanRow(['payment_frequency' => 'BIWEEKLY'])->value('payment_frequency'));
        $this->assertSame('upon_maturity', $this->loanRow(['payment_frequency' => 'UPON MATURITY'])->value('payment_frequency'));

        $this->assertCount(6, array_unique(array_values(LoanRowNormalizer::frequencyMap())));
    }

    public function test_gender_civil_status_and_interest_type_map(): void
    {
        $this->assertSame('male', $this->customerRow(['gender' => 'Male'])->value('gender'));
        $this->assertSame('male', $this->customerRow(['gender' => 'M'])->value('gender'));
        $this->assertSame('female', $this->customerRow(['gender' => 'F'])->value('gender'));
        $this->assertSame('married', $this->customerRow(['civil_status' => 'MARRIED'])->value('civil_status'));
        $this->assertSame('widowed', $this->customerRow(['civil_status' => 'Widowed'])->value('civil_status'));
        $this->assertSame('straight', $this->loanRow(['interest_type' => 'Straight (Fixed)'])->value('interest_type'));
        $this->assertSame('diminishing', $this->loanRow(['interest_type' => 'Diminishing'])->value('interest_type'));
    }

    public function test_an_unmappable_nullable_enum_is_blanked_with_a_warning_but_an_unmappable_required_one_fails(): void
    {
        // "maried" is the typo the un-validated workbook produces. Nulling an
        // optional field keeps the member — and their loans — in the system.
        $customer = $this->customerRow(['civil_status' => 'maried']);
        $this->assertTrue($customer->isValid());
        $this->assertNull($customer->value('civil_status'));
        $this->assertContains('enum_unmapped', array_column($customer->warningsToArray(), 'code'));

        // Frequency is not optional: without it there is no schedule to build.
        $loan = $this->loanRow(['payment_frequency' => 'Whenever']);
        $this->assertFalse($loan->isValid());
        $this->assertContains('enum_invalid', array_column($loan->errorsToArray(), 'code'));
    }

    public function test_a_contact_number_the_app_would_reject_is_downgraded_to_null_with_a_warning(): void
    {
        // Failing the row would orphan every loan this member holds. Importing
        // the value would create a member UpdateBorrowerRequest can never save.
        $row = $this->customerRow(['contact_number' => '0917']);

        $this->assertTrue($row->isValid());
        $this->assertNull($row->value('contact_number'));
        $this->assertContains('contact_number_invalid', array_column($row->warningsToArray(), 'code'));

        $placeholder = $this->customerRow(['contact_number' => 'none']);
        $this->assertNull($placeholder->value('contact_number'));
        $this->assertContains('contact_number_invalid', array_column($placeholder->warningsToArray(), 'code'));

        // The app's own regex accepts a bare 7-digit landline, so this
        // importer does too. Fidelity to UpdateBorrowerRequest is the whole
        // point of the downgrade — being stricter here would reject values the
        // form would happily save.
        $this->assertSame('0917123', $this->customerRow(['contact_number' => '0917-123'])->value('contact_number'));
    }

    public function test_a_contact_number_is_stripped_to_digits_and_a_leading_plus(): void
    {
        $this->assertSame('09171234567', $this->customerRow(['contact_number' => '(0917) 123-4567'])->value('contact_number'));
        $this->assertSame('+639171234567', $this->customerRow(['contact_number' => '+63 917 123 4567'])->value('contact_number'));
    }

    public function test_two_numbers_in_one_cell_keep_the_first_and_warn_about_the_rest(): void
    {
        // contact_number is varchar(20) and two PH mobiles joined by a slash is
        // 23 characters, so this cannot simply be stored.
        $row = $this->customerRow(['contact_number' => '09171234567 / 09281234567']);

        $this->assertSame('09171234567', $row->value('contact_number'));
        $warning = collect($row->warningsToArray())->firstWhere('code', 'contact_number_multiple');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('09281234567', $warning['message']);
    }

    /**
     * A placeholder is not a list of numbers, and must not be reported as one.
     *
     * `/` is one of the separators, so `N/A` splits into `N` and `A` — two
     * non-empty parts — and the cell used to be reported as holding more than
     * one number, of which the import "kept N and dropped A". Nonsense, and then
     * reported as invalid as well, so the operator got two warnings where one is
     * true.
     *
     * The behaviour was never wrong: the member imports with a blank number
     * either way. The REPORT was wrong, and the report is the whole recovery
     * mechanism for a migration — one line an admin can see is nonsense is how
     * they learn to stop reading the rest.
     */
    public function test_a_placeholder_contact_number_warns_once_and_does_not_claim_it_held_two_numbers(): void
    {
        foreach (['N/A', 'n/a', 'none', 'N / A'] as $placeholder) {
            $row = $this->customerRow(['contact_number' => $placeholder]);
            $codes = array_column($row->warningsToArray(), 'code');

            $this->assertTrue($row->isValid(), "[{$placeholder}] must still import the member.");
            $this->assertNull($row->value('contact_number'));

            $this->assertNotContains('contact_number_multiple', $codes,
                "[{$placeholder}] was reported as holding more than one number.");
            $this->assertContains('contact_number_invalid', $codes);
            $this->assertSame(
                ['contact_number_invalid'],
                array_values(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'contact_number_'))),
                "[{$placeholder}] produced more than one contact number warning."
            );
        }
    }

    public function test_an_invalid_email_is_downgraded_to_null_with_a_warning(): void
    {
        $row = $this->customerRow(['email' => 'juan(at)example.com']);

        $this->assertTrue($row->isValid());
        $this->assertNull($row->value('email'));
        $this->assertContains('email_invalid', array_column($row->warningsToArray(), 'code'));

        $this->assertSame('juan@example.com', $this->customerRow(['email' => 'juan@example.com'])->value('email'));
    }

    public function test_an_under_18_birthdate_is_kept_and_flagged_rather_than_blanked(): void
    {
        // The app's -18 rule is underwriting policy for a NEW applicant. A
        // 17-year-old already on the coop's books is a fact. Birthdate is also
        // identity data — the third leg of the match triple — so unlike contact
        // number it is kept, and the operator is told the member cannot be
        // saved in the app until it is corrected.
        $birthdate = Carbon::today()->subYears(17)->toDateString();

        $row = $this->customerRow(['birthdate' => $birthdate]);

        $this->assertTrue($row->isValid());
        $this->assertSame($birthdate, $row->value('birthdate'));
        $this->assertContains('birthdate_under_18', array_column($row->warningsToArray(), 'code'));
    }

    public function test_a_future_birthdate_is_blanked_because_it_cannot_be_a_fact(): void
    {
        $row = $this->customerRow(['birthdate' => Carbon::tomorrow()->toDateString()]);

        $this->assertTrue($row->isValid());
        $this->assertNull($row->value('birthdate'));
        $this->assertContains('birthdate_future', array_column($row->warningsToArray(), 'code'));
    }

    public function test_the_contact_number_regex_still_matches_the_apps_own_rule(): void
    {
        // The regex is copied out of a protected method on a FormRequest, so
        // this reflects the real trait and fails the moment the copy drifts.
        $probe = new class extends FormRequest
        {
            use HasBorrowerRules;

            /**
             * @return array<string, array<int, mixed>>
             */
            public function exposeRules(): array
            {
                return $this->sharedBorrowerRules();
            }
        };

        $rules = $probe->exposeRules()['contact_number'];
        $regexRule = collect($rules)->first(fn ($rule) => is_string($rule) && str_starts_with($rule, 'regex:'));

        $this->assertSame('regex:'.ValueNormalizer::CONTACT_NUMBER_REGEX, $regexRule);
    }

    public function test_loan_preconditions_fail_the_row_with_a_message_naming_both_cells(): void
    {
        $balanceTooBig = $this->loanRow(['loan_balance' => '20,000.00']);
        $this->assertFalse($balanceTooBig->isValid());
        $this->assertContains('balance_exceeds_amount', array_column($balanceTooBig->errorsToArray(), 'code'));

        $interestTooBig = $this->loanRow(['interest_balance' => '5,000.00']);
        $this->assertFalse($interestTooBig->isValid());
        $this->assertContains('interest_balance_exceeds_interest', array_column($interestTooBig->errorsToArray(), 'code'));

        $backwards = $this->loanRow(['date_released' => '2025-07-15', 'maturity_date' => '2025-01-15']);
        $this->assertFalse($backwards->isValid());
        $this->assertContains('maturity_not_after_release', array_column($backwards->errorsToArray(), 'code'));
    }

    public function test_the_spouse_contact_number_is_cleaned_but_never_blanked(): void
    {
        // HasBorrowerRules gives spouse_contact_number only `max:20` — no
        // regex. Nulling a value the app would happily store would destroy
        // data for no reason, so this field is cleaned and kept.
        $this->assertSame('09171234567', $this->customerRow(['spouse_contact_number' => '(0917) 123-4567'])->value('spouse_contact_number'));

        $odd = $this->customerRow(['spouse_contact_number' => 'ask Maria']);
        $this->assertTrue($odd->isValid());
        $this->assertSame('ask Maria', $odd->value('spouse_contact_number'));
    }

    public function test_the_four_fee_columns_normalise_to_centavos(): void
    {
        $row = $this->loanRow([
            'processing_fee' => '₱500.00',
            'service_fee' => '250.50',
            'other_fee_detail' => 'Notarial fee',
            'other_fee_amount' => '1,000',
        ]);

        $this->assertTrue($row->isValid());
        $this->assertSame(50000, $row->value('processing_fee'));
        $this->assertSame(25050, $row->value('service_fee'));
        $this->assertSame(100000, $row->value('other_fee_amount'));
        $this->assertSame('Notarial fee', $row->value('other_fee_detail'));

        // Fees are release-time deductions and must never be netted against
        // principal — the schedule arithmetic does not see them at all.
        $this->assertSame(1000000, $row->value('loan_amount'));
    }

    public function test_an_other_fee_with_no_description_warns(): void
    {
        // The disclosure statement has to itemise every deduction.
        $row = $this->loanRow(['other_fee_amount' => '750.00', 'other_fee_detail' => '']);

        $this->assertTrue($row->isValid());
        $this->assertContains('unexplained_fee', array_column($row->warningsToArray(), 'code'));
    }

    public function test_a_row_of_the_wrong_width_fails_the_row_because_the_file_already_passed(): void
    {
        $row = (new CustomerRowNormalizer)->normalize(new CsvRow(1, 1, ['A-001', 'Dela Cruz']));

        $this->assertFalse($row->isValid());
        $this->assertContains('row_column_count', array_column($row->errorsToArray(), 'code'));
    }
}
