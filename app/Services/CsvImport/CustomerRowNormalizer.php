<?php

namespace App\Services\CsvImport;

use App\Models\Borrower;
use App\Services\Portal\Auth\MemberNumber;
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
 *
 * The four appended Member Profile columns (Date Hired and the membership
 * columns) refuse the row instead. A blank is "not set", exactly as in a file
 * made before them, so the only way to hit a refusal is to type a value the
 * member form would never store, and silently blanking a value somebody typed
 * is the drop this importer exists to avoid.
 */
class CustomerRowNormalizer
{
    /**
     * Gender as the workbook writes it, Filipino included: Lalaki (also typed
     * Lalake) is male and Babae female. Matched in any case.
     *
     * @var array<string, string>
     */
    private const GENDER_MAP = [
        'male' => 'male',
        'm' => 'male',
        'lalaki' => 'male',
        'lalake' => 'male',
        'female' => 'female',
        'f' => 'female',
        'babae' => 'female',
    ];

    /**
     * Each gender and civil status in words, as the workbook writes it, for a
     * value the import blanks.
     *
     * @var array<string, string>
     */
    private const GENDER_WORDS = ['male' => 'Male', 'female' => 'Female'];

    /** @var array<string, string> */
    private const CIVIL_STATUS_WORDS = [
        'single' => 'Single',
        'married' => 'Married',
        'widowed' => 'Widowed',
        'separated' => 'Separated',
        'divorced' => 'Divorced',
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

    /**
     * `borrowers.membership_number` is varchar(30).
     */
    private const MEMBERSHIP_NUMBER_MAX = 30;

    /**
     * The CDA membership columns. The member form sets a membership date and
     * type under `borrowers:update`, and the number comes from the member code,
     * so a run carries them only when its starter may edit members.
     */
    public const MEMBERSHIP_FIELDS = ['membership_date', 'member_type', 'membership_number'];

    /**
     * The refusal for a membership column the run may not carry, at staging
     * here and again at the import pass (CsvImportProcessor), in one wording.
     */
    public const MEMBERSHIP_NEEDS_MEMBER_EDIT = 'membership_needs_member_edit';

    public const MEMBERSHIP_NEEDS_MEMBER_EDIT_MESSAGE = 'Only staff who can edit members can import membership details, and the person who started this import can\'t. Leave Membership Date, Member Type and Membership Number blank, or have someone who can edit members start the import.';

    public function __construct(private readonly ValueNormalizer $values = new ValueNormalizer) {}

    /**
     * @param  bool  $mayImportMembership  Whether the run may carry the
     *                                     membership columns (CsvImportStager decides it per run).
     *                                     Without it, a row that fills one is refused.
     * @param  array<string, string>  $dateOrders  The date order the Check step settled
     *                                             per date column, `dmy` or `mdy`
     *                                             (CsvImportRun::dateOrdersFor()).
     */
    public function normalize(CsvRow $row, bool $mayImportMembership = false, array $dateOrders = []): NormalizedRow
    {
        $notes = new RowNoteBag;
        $cells = $this->cellsByKey($row, $notes);

        if ($cells === null) {
            return new NormalizedRow(CsvImportSchema::CUSTOMERS, $row->rowNumber, [], $notes->warnings(), $notes->errors());
        }

        $values = [
            'account_no' => $this->values->identifier($cells['account_no'], 'account_no', LoanRowNormalizer::ACCOUNT_NO_MAX, 'account_no_too_long', LoanRowNormalizer::ACCOUNT_NO_TOO_LONG, $notes),
            'last_name' => $this->requiredText($cells['last_name'], 'last_name', 255, $notes),
            'first_name' => $this->requiredText($cells['first_name'], 'first_name', 255, $notes),
            'middle_name' => $this->values->boundedText($cells['middle_name'], 'middle_name', 255, $notes),
            'suffix' => $this->values->boundedText($cells['suffix'], 'suffix', 20, $notes),
            'birthdate' => $this->birthdate($cells['birthdate'], $notes, $dateOrders['birthdate'] ?? null),
            'gender' => $this->values->enum($cells['gender'], self::GENDER_MAP, 'gender', $notes, label: 'gender', words: self::GENDER_WORDS),
            'civil_status' => $this->values->enum($cells['civil_status'], self::CIVIL_STATUS_MAP, 'civil_status', $notes, label: 'civil status', words: self::CIVIL_STATUS_WORDS),
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

        $values['date_hired'] = $this->dateHired($cells['date_hired'], $values['birthdate'], $notes, $dateOrders['date_hired'] ?? null);

        if ($mayImportMembership) {
            $values['membership_date'] = $this->membershipDate($cells['membership_date'], $notes, $dateOrders['membership_date'] ?? null);
            $values['member_type'] = $this->memberType($cells['member_type'], $notes);
            $values['membership_number'] = $this->membershipNumber($cells['membership_number'], $notes);
        } else {
            $this->refuseMembership($cells, $notes);
        }

        return new NormalizedRow(CsvImportSchema::CUSTOMERS, $row->rowNumber, $values, $notes->warnings(), $notes->errors());
    }

    /**
     * @return array<string, string|null>|null
     */
    private function cellsByKey(CsvRow $row, RowNoteBag $notes): ?array
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::CUSTOMERS);
        // Held to the width of the FILE's layout, never to whichever layout
        // the row happens to match: a row of an old file that grew four stray
        // separators is 26 wide, and reading it as today's layout would put
        // every value after them in the wrong field.
        $expected = $row->fileWidth ?? count($keys);
        $found = count($row->cells);

        // The FILE already passed the modal-width check, so a single row of the
        // wrong width is a problem with that row, not with the upload — a
        // stray delimiter inside an unquoted address, typically.
        if ($found !== $expected || ! in_array($expected, CsvImportSchema::acceptedWidths(CsvImportSchema::CUSTOMERS), true)) {
            $notes->fail('__row', 'row_column_count', "This row has {$found} columns but {$expected} were expected, so its values cannot be matched to fields.");

            return null;
        }

        // A file in the earlier layout has none of the appended columns, and
        // they read as blank: not set.
        return array_combine(array_slice($keys, 0, $expected), $row->cells)
            + array_fill_keys(array_slice($keys, $expected), null);
    }

    /**
     * The member form's rule (HasBorrowerRules): a real date after January 1,
     * 1900 and not after today; and, as this import also requires, not before
     * the member's birthdate. A birthdate the import blanked (in the future,
     * or before 1900) leaves that last check out.
     */
    private function dateHired(?string $raw, ?string $birthdate, RowNoteBag $notes, ?string $order): ?string
    {
        $value = $this->values->date($raw, 'date_hired', $notes, order: $order);

        if ($value === null) {
            return null;
        }

        // Both are `Y-m-d`, so the strings compare as the dates do.
        if ($value <= '1900-01-01') {
            $notes->fail('date_hired', 'date_hired_implausible', "\"{$value}\" is not after January 1, 1900, so it can't be a date hired.");

            return null;
        }

        if ($value > Carbon::today()->toDateString()) {
            $notes->fail('date_hired', 'date_hired_future', "\"{$value}\" is after today, and a date hired can't be in the future.");

            return null;
        }

        if ($birthdate !== null && $value < $birthdate) {
            $notes->fail('date_hired', 'date_hired_before_birthdate', "\"{$value}\" is before this member's birthdate ({$birthdate}), so it can't be the date they were hired.");

            return null;
        }

        return $value;
    }

    /**
     * UpdateBorrowerRequest's rule: a real date, not after today. Every
     * imported member is a member (`active`), so the date always has a member
     * to belong to.
     */
    private function membershipDate(?string $raw, RowNoteBag $notes, ?string $order): ?string
    {
        $value = $this->values->date($raw, 'membership_date', $notes, order: $order);

        if ($value !== null && $value > Carbon::today()->toDateString()) {
            $notes->fail('membership_date', 'membership_date_future', "\"{$value}\" is after today, and a membership date can't be in the future.");

            return null;
        }

        return $value;
    }

    /**
     * Regular or Associate, in any case, stored as the app's own value. Blank
     * is not set: the member takes the app's default, as every new member does.
     */
    private function memberType(?string $raw, RowNoteBag $notes): ?string
    {
        $value = $this->values->text($raw);

        if ($value === null) {
            return null;
        }

        $type = CsvImportSchema::normalizeLabel($value);

        if (in_array($type, Borrower::MEMBER_TYPES, true)) {
            return $type;
        }

        $notes->fail('member_type', 'member_type_invalid', "\"{$value}\" is not a member type. Write Regular or Associate.");

        return null;
    }

    /**
     * A membership number in the form members sign in with it (MemberNumber):
     * capitals and no spaces, plain keyboard characters only, and at most the
     * 30 characters the column holds. Refused, never shortened, since two
     * numbers cut down to one are a clash.
     *
     * Anything that starts like a member code (BRW) is refused as well, its
     * look-alikes included (BRW000123, BRW-00O123). Member codes and
     * membership numbers share one sign-in, where a membership number wins
     * (MemberNumber::find()), and the app gives member codes out itself. An
     * imported one would take over the sign-in of the member who holds that
     * code, or block the member the app gives it to next. Left blank, the
     * member's own code is used.
     *
     * So is a spreadsheet placeholder (`-`, `N/A`, `0`), which would become
     * that member's sign-in number: a number needs a digit, and one that is
     * nothing but zeros is not a number.
     *
     * Whether another member already holds the number is the import pass's
     * question (CsvImportProcessor), since only it can see the rows written
     * before this one.
     */
    private function membershipNumber(?string $raw, RowNoteBag $notes): ?string
    {
        $value = $this->values->text($raw);

        if ($value === null) {
            return null;
        }

        $number = MemberNumber::normalise($value);

        if (preg_match('/^[\x21-\x7E]+$/', $number) !== 1) {
            $notes->fail('membership_number', 'membership_number_invalid', "\"{$value}\" holds a character outside plain letters, numbers and punctuation, which a membership number can't hold.");

            return null;
        }

        if (strlen($number) > self::MEMBERSHIP_NUMBER_MAX) {
            $notes->fail('membership_number', 'membership_number_too_long', "\"{$value}\" is longer than the ".self::MEMBERSHIP_NUMBER_MAX.' characters a membership number holds.');

            return null;
        }

        if (str_starts_with($number, Borrower::CODE_PREFIX)) {
            $notes->fail('membership_number', 'membership_number_member_code', "\"{$value}\" starts like a member code (".Borrower::CODE_PREFIX.'), which the app gives out itself. Leave Membership Number blank and the member\'s own code is used.');

            return null;
        }

        if (preg_match('/\d/', $number) !== 1) {
            $notes->fail('membership_number', 'membership_number_placeholder', "\"{$value}\" has no digits, so it reads as a spreadsheet placeholder rather than a membership number. Leave the cell blank if the member has none.");

            return null;
        }

        // Its digits all zero and no letters: `0`, `000`, `00-00`.
        if (preg_match('/^[^A-Z1-9]+$/', $number) === 1) {
            $notes->fail('membership_number', 'membership_number_placeholder', "\"{$value}\" is nothing but zeros, so it reads as a spreadsheet placeholder rather than a membership number. Leave the cell blank if the member has none.");

            return null;
        }

        if ($number !== mb_strtoupper($value)) {
            $notes->warn(
                'membership_number',
                'membership_number_spaces',
                "\"{$value}\" was stored as {$number}: members sign in with their number without spaces.",
                "\"{$value}\" would be stored as {$number}: members sign in with their number without spaces.",
            );
        }

        return $number;
    }

    /**
     * Importing needs `imports:process`, while the member form sets a
     * membership date and type under `borrowers:update`. The stricter of the
     * two holds for these columns: a run whose starter can't edit members
     * carries none of them. A row that fills one is refused rather than
     * imported without it, so nothing typed in the file is dropped unsaid.
     *
     * @param  array<string, string|null>  $cells
     */
    private function refuseMembership(array $cells, RowNoteBag $notes): void
    {
        foreach (self::MEMBERSHIP_FIELDS as $field) {
            if ($this->values->text($cells[$field]) === null) {
                continue;
            }

            $notes->fail($field, self::MEMBERSHIP_NEEDS_MEMBER_EDIT, self::MEMBERSHIP_NEEDS_MEMBER_EDIT_MESSAGE);

            return;
        }
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
    private function birthdate(?string $raw, RowNoteBag $notes, ?string $order): ?string
    {
        $value = $this->values->date($raw, 'birthdate', $notes, order: $order);

        if ($value === null) {
            return null;
        }

        $date = Carbon::parse($value);

        if ($date->isFuture()) {
            $notes->warn(
                'birthdate',
                'birthdate_future',
                "\"{$value}\" is in the future, which cannot be a birthdate, so it was imported as blank.",
                "\"{$value}\" is in the future, which cannot be a birthdate, so it would be imported as blank.",
            );

            return null;
        }

        if ($date->lt(Carbon::create(1900, 1, 1))) {
            $notes->warn(
                'birthdate',
                'birthdate_implausible',
                "\"{$value}\" is before 1900 and was imported as blank.",
                "\"{$value}\" is before 1900, so it would be imported as blank.",
            );

            return null;
        }

        if ($date->gt(Carbon::today()->subYears(18))) {
            $notes->warn(
                'birthdate',
                'birthdate_under_18',
                "\"{$value}\" makes this member under 18. The record was imported as-is because it is a fact on the coop's books, but the member form requires 18+, so this member cannot be saved in the app until the birthdate is corrected.",
                "\"{$value}\" makes this member under 18. The record would be imported as-is because it is a fact on the coop's books, but the member form requires 18+, so this member could not be saved in the app until the birthdate is corrected.",
            );
        }

        return $value;
    }

    private function cappedAmount(?string $raw, string $field, int $maxCentavos, string $maxLabel, RowNoteBag $notes): ?int
    {
        $value = $this->values->centavos($raw, $field, $notes);

        if ($value === null || $value <= $maxCentavos) {
            return $value;
        }

        $notes->warn(
            $field,
            'amount_above_app_maximum',
            "This amount is above the {$maxLabel} the member form allows, so it was imported as blank rather than leaving a member who cannot be saved.",
            "This amount is above the {$maxLabel} the member form allows, so it would be imported as blank rather than leaving a member who cannot be saved.",
        );

        return null;
    }
}
