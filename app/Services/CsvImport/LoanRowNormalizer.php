<?php

namespace App\Services\CsvImport;

use App\Enums\LoanFrequency;
use Carbon\Carbon;

/**
 * Turns one loan record into typed, storable values.
 *
 * The four money figures — Loan Amount, Loan Balance, Interest Amount, Interest
 * Balance — leave here as integer centavos and are handed to
 * LoanScheduleReconstructor untouched. They are the member's passbook, so no
 * step between the file and the schedule is allowed to round them.
 *
 * `term_in_months` is normalised but is explicitly NOT `loans.term`. See
 * LoanScheduleReconstructor::reconstruct(), which derives the period count from
 * the dates.
 */
class LoanRowNormalizer
{
    /**
     * Interest Type as the workbook writes it.
     *
     * @var array<string, string>
     */
    private const INTEREST_TYPE_MAP = [
        'straight fixed' => 'straight',
        'straight' => 'straight',
        'fixed' => 'straight',
        'flat' => 'straight',
        'diminishing' => 'diminishing',
        'diminishing balance' => 'diminishing',
        'upon maturity' => 'upon_maturity',
    ];

    /**
     * Longest loan this importer will accept, in months. Ten years covers
     * anything a coop writes and keeps the derived period count inside the
     * unsignedSmallInteger that holds it.
     */
    private const MAX_TERM_MONTHS = 120;

    public function __construct(private readonly ValueNormalizer $values = new ValueNormalizer) {}

    public function normalize(CsvRow $row): NormalizedRow
    {
        $notes = new RowNoteBag;
        $cells = $this->cellsByKey($row, $notes);

        if ($cells === null) {
            return new NormalizedRow(CsvImportSchema::LOANS, $row->rowNumber, [], $notes->warnings(), $notes->errors());
        }

        $values = [
            'account_no' => $this->requiredText($cells['account_no'], 'account_no', 50, $notes),
            'loan_no' => $this->requiredText($cells['loan_no'], 'loan_no', 20, $notes),
            'loan_amount' => $this->values->centavos($cells['loan_amount'], 'loan_amount', $notes, required: true),
            'loan_balance' => $this->values->centavos($cells['loan_balance'], 'loan_balance', $notes, required: true),
            'interest_rate' => $this->values->rate($cells['interest_rate'], 'interest_rate', $notes, required: true),
            'interest_amount' => $this->values->centavos($cells['interest_amount'], 'interest_amount', $notes, required: true),
            'interest_balance' => $this->values->centavos($cells['interest_balance'], 'interest_balance', $notes, required: true),
            'purpose' => $this->values->boundedText($cells['purpose'], 'purpose', 1000, $notes),
            'loan_product' => $this->values->boundedText($cells['loan_product'], 'loan_product', 255, $notes),
            'term_in_months' => $this->values->integer($cells['term_in_months'], 'term_in_months', $notes, min: 1, max: self::MAX_TERM_MONTHS, required: true),
            'payment_frequency' => $this->values->enum($cells['payment_frequency'], self::frequencyMap(), 'payment_frequency', $notes, required: true, label: 'payment frequency'),
            'interest_type' => $this->values->enum($cells['interest_type'], self::INTEREST_TYPE_MAP, 'interest_type', $notes, required: true, label: 'interest type'),
            'date_released' => $this->values->date($cells['date_released'], 'date_released', $notes, required: true),
            'maturity_date' => $this->values->date($cells['maturity_date'], 'maturity_date', $notes, required: true),
            // Fees are deductions taken at release. They do not enter the
            // schedule arithmetic at all — LoanService keeps them in
            // `deductions`/`total_deductions`/`net_proceeds` — so they are
            // normalised and handed on, never netted against principal.
            'processing_fee' => $this->values->centavos($cells['processing_fee'], 'processing_fee', $notes),
            'service_fee' => $this->values->centavos($cells['service_fee'], 'service_fee', $notes),
            'other_fee_detail' => $this->values->boundedText($cells['other_fee_detail'], 'other_fee_detail', 255, $notes),
            'other_fee_amount' => $this->values->centavos($cells['other_fee_amount'], 'other_fee_amount', $notes),
        ];

        $this->checkRelationships($values, $notes);

        return new NormalizedRow(CsvImportSchema::LOANS, $row->rowNumber, $values, $notes->warnings(), $notes->errors());
    }

    /**
     * Payment Frequency vocabulary, generated from App\Enums\LoanFrequency so
     * the accepted set cannot drift from the stored set.
     *
     * Two keys per case cover both ways the workbook writes a compound word —
     * "Bi-Weekly" folds to "bi weekly", "BIWEEKLY" to "biweekly".
     *
     * @return array<string, string>
     */
    public static function frequencyMap(): array
    {
        $map = [];

        foreach (LoanFrequency::cases() as $case) {
            $map[str_replace('_', ' ', $case->value)] = $case->value;
            $map[str_replace('_', '', $case->value)] = $case->value;
        }

        return $map;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function cellsByKey(CsvRow $row, RowNoteBag $notes): ?array
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::LOANS);
        $expected = count($keys);
        $found = count($row->cells);

        if ($found !== $expected) {
            $notes->fail('__row', 'row_column_count', "This row has {$found} columns but {$expected} were expected, so its values cannot be matched to fields.");

            return null;
        }

        return array_combine($keys, $row->cells);
    }

    private function requiredText(?string $raw, string $field, int $max, RowNoteBag $notes): ?string
    {
        $value = $this->values->boundedText($raw, $field, $max, $notes);

        if ($value === null) {
            $notes->fail($field, 'required', 'This field is required and the cell is blank.');
        }

        return $value;
    }

    /**
     * Preconditions the reconstruction arithmetic depends on.
     *
     * These fail the ROW rather than downgrade, and none of them is a
     * formatting quibble: a balance larger than the amount, or an interest
     * balance larger than the interest, cannot be turned into a schedule that
     * adds up. Reconstructing one anyway would put a number on a member's
     * statement that reconciles with nothing.
     *
     * LoanScheduleReconstructor re-checks all of this independently — it is a
     * public entry point and must not trust its caller — but catching it here
     * is what produces a message naming the two cells involved.
     *
     * @param  array<string, mixed>  $values
     */
    private function checkRelationships(array $values, RowNoteBag $notes): void
    {
        $released = $values['date_released'];
        $maturity = $values['maturity_date'];

        if (is_string($released) && is_string($maturity) && Carbon::parse($maturity)->lte(Carbon::parse($released))) {
            $notes->fail('maturity_date', 'maturity_not_after_release', "The maturity date ({$maturity}) is not after the date released ({$released}), so no payment schedule can be built.");
        }

        $amount = $values['loan_amount'];
        $balance = $values['loan_balance'];

        if (is_int($amount) && is_int($balance) && $balance > $amount) {
            $notes->fail('loan_balance', 'balance_exceeds_amount', 'The loan balance is larger than the loan amount. A balance cannot exceed the principal that was released.');
        }

        if (is_int($amount) && $amount <= 0) {
            $notes->fail('loan_amount', 'amount_not_positive', 'The loan amount must be greater than zero.');
        }

        $interest = $values['interest_amount'];
        $interestBalance = $values['interest_balance'];

        if (is_int($interest) && is_int($interestBalance) && $interestBalance > $interest) {
            $notes->fail('interest_balance', 'interest_balance_exceeds_interest', 'The interest balance is larger than the total interest. A balance cannot exceed what was charged.');
        }

        // A deduction with no description cannot be printed on the disclosure
        // statement, which has to itemise what was withheld at release. A
        // warning rather than a failure: the money is real either way, and the
        // wording can be supplied afterwards.
        if (is_int($values['other_fee_amount']) && $values['other_fee_amount'] > 0 && $values['other_fee_detail'] === null) {
            $notes->warn('other_fee_detail', 'unexplained_fee', 'An other-fee amount was given with no description. The disclosure statement has to itemise every deduction, so a description will need to be added.');
        }
    }
}
