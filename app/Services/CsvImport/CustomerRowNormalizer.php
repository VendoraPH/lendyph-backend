<?php

namespace App\Services\CsvImport;

use Carbon\Carbon;

/**
 * Turns one customer record into typed, storable values.
 *
 * Money leaves here as integer centavos; dates as `Y-m-d`; enums as the
 * lowercase snake_case the columns actually hold.
 *
 * Two fields the app treats as validated — contact number and email — are
 * downgraded to a warning and a null rather than failing the row. See
 * ValueNormalizer::contactNumber() for why; the short version is that
 * UpdateBorrowerRequest applies the same rules, so importing a value the form
 * rejects creates a member nobody can ever save again, and failing the row
 * instead orphans every loan that points at them.
 */
class CustomerRowNormalizer
{
    /** @var array<string, string> */
    private const GENDER_MAP = [
        'male' => 'male',
        'm' => 'male',
        'female' => 'female',
        'f' => 'female',
    ];

    /** @var array<string, string> */
    private const CIVIL_STATUS_MAP = [
        'single' => 'single',
        'married' => 'married',
        'widowed' => 'widowed',
        'widow' => 'widowed',
        'widower' => 'widowed',
        'separated' => 'separated',
        'legally separated' => 'separated',
        'divorced' => 'divorced',
    ];

    /**
     * App rule: monthly_income max 99,999,999.99.
     */
    private const MAX_MONTHLY_INCOME_CENTAVOS = 9999999999;

    /**
     * App rule: pledge_amount max 9,999,999.99.
     */
    private const MAX_PLEDGE_CENTAVOS = 999999999;

    public function __construct(private readonly ValueNormalizer $values = new ValueNormalizer) {}

    public function normalize(CsvRow $row): NormalizedRow
    {
        $notes = new RowNoteBag;
        $cells = $this->cellsByKey($row, $notes);

        if ($cells === null) {
            return new NormalizedRow(CsvImportSchema::CUSTOMERS, $row->rowNumber, [], $notes->warnings(), $notes->errors());
        }

        $values = [
            'account_no' => $this->requiredText($cells['account_no'], 'account_no', 50, $notes),
            'last_name' => $this->requiredText($cells['last_name'], 'last_name', 255, $notes),
            'first_name' => $this->requiredText($cells['first_name'], 'first_name', 255, $notes),
            'middle_name' => $this->values->boundedText($cells['middle_name'], 'middle_name', 255, $notes),
            'suffix' => $this->values->boundedText($cells['suffix'], 'suffix', 20, $notes),
            'birthdate' => $this->birthdate($cells['birthdate'], $notes),
            'gender' => $this->values->enum($cells['gender'], self::GENDER_MAP, 'gender', $notes, label: 'gender'),
            'civil_status' => $this->values->enum($cells['civil_status'], self::CIVIL_STATUS_MAP, 'civil_status', $notes, label: 'civil status'),
            'contact_number' => $this->values->contactNumber($cells['contact_number'], 'contact_number', $notes),
            'email' => $this->values->email($cells['email'], 'email', $notes),
            'street_address' => $this->values->boundedText($cells['street_address'], 'street_address', 255, $notes),
            'barangay' => $this->values->boundedText($cells['barangay'], 'barangay', 255, $notes),
            'city' => $this->values->boundedText($cells['city'], 'city', 255, $notes),
            'province' => $this->values->boundedText($cells['province'], 'province', 255, $notes),
            'employer_or_business' => $this->values->boundedText($cells['employer_or_business'], 'employer_or_business', 255, $notes),
            'monthly_income' => $this->cappedAmount($cells['monthly_income'], 'monthly_income', self::MAX_MONTHLY_INCOME_CENTAVOS, '99,999,999.99', $notes),
            'pledge_amount' => $this->cappedAmount($cells['pledge_amount'], 'pledge_amount', self::MAX_PLEDGE_CENTAVOS, '9,999,999.99', $notes),
            'spouse_first_name' => $this->values->boundedText($cells['spouse_first_name'], 'spouse_first_name', 255, $notes),
            'spouse_middle_name' => $this->values->boundedText($cells['spouse_middle_name'], 'spouse_middle_name', 255, $notes),
            'spouse_last_name' => $this->values->boundedText($cells['spouse_last_name'], 'spouse_last_name', 255, $notes),
            // Spouse contact number has no regex in HasBorrowerRules, only
            // max:20, so it is cleaned but never blanked — the app would store
            // whatever this is.
            'spouse_contact_number' => $this->values->contactNumber($cells['spouse_contact_number'], 'spouse_contact_number', $notes, enforceAppFormat: false),
            'spouse_occupation' => $this->values->boundedText($cells['spouse_occupation'], 'spouse_occupation', 255, $notes),
        ];

        return new NormalizedRow(CsvImportSchema::CUSTOMERS, $row->rowNumber, $values, $notes->warnings(), $notes->errors());
    }

    /**
     * @return array<string, string|null>|null
     */
    private function cellsByKey(CsvRow $row, RowNoteBag $notes): ?array
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::CUSTOMERS);
        $expected = count($keys);
        $found = count($row->cells);

        // The FILE already passed the modal-width check, so a single row of the
        // wrong width is a problem with that row, not with the upload — a
        // stray delimiter inside an unquoted address, typically.
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
     * Birthdate is validated against `before_or_equal:today`, NOT the app's
     * `before_or_equal:-18 years`.
     *
     * That rule is underwriting policy for a new applicant. A 17-year-old
     * already on the coop's books is a historical fact, and refusing the fact
     * does not make it untrue — it just leaves the member out of the system
     * and orphans their loans.
     *
     * Note the asymmetry with contact number and email, which ARE nulled when
     * the app would reject them: birthdate is identity data. It is the third
     * leg of the first+last+birthdate triple that BorrowerMatcher and
     * StoreBorrowerRequest::describesSamePersonAs() use, so nulling it would
     * break identity matching for that member permanently and make a re-run of
     * this importer create a duplicate. A wrong contact number is recoverable;
     * a lost identity link is not detectable. So the under-18 value is KEPT and
     * flagged, and the operator is told plainly that the borrower form will
     * refuse to save that member until the birthdate is corrected.
     *
     * A birthdate in the FUTURE is different again: it is not a fact anybody
     * could hold, so it is a typo, and it is nulled.
     */
    private function birthdate(?string $raw, RowNoteBag $notes): ?string
    {
        $value = $this->values->date($raw, 'birthdate', $notes);

        if ($value === null) {
            return null;
        }

        $date = Carbon::parse($value);

        if ($date->isFuture()) {
            $notes->warn('birthdate', 'birthdate_future', "\"{$value}\" is in the future, which cannot be a birthdate, so it was imported as blank.");

            return null;
        }

        if ($date->lt(Carbon::create(1900, 1, 1))) {
            $notes->warn('birthdate', 'birthdate_implausible', "\"{$value}\" is before 1900 and was imported as blank.");

            return null;
        }

        if ($date->gt(Carbon::today()->subYears(18))) {
            $notes->warn('birthdate', 'birthdate_under_18', "\"{$value}\" makes this member under 18. The record was imported as-is because it is a fact on the coop's books, but the borrower form requires 18+, so this member cannot be saved in the app until the birthdate is corrected.");
        }

        return $value;
    }

    private function cappedAmount(?string $raw, string $field, int $maxCentavos, string $maxLabel, RowNoteBag $notes): ?int
    {
        $value = $this->values->centavos($raw, $field, $notes);

        if ($value === null || $value <= $maxCentavos) {
            return $value;
        }

        $notes->warn($field, 'amount_above_app_maximum', "This amount is above the {$maxLabel} the borrower form allows, so it was imported as blank rather than leaving a member who cannot be saved.");

        return null;
    }
}
