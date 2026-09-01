<?php

namespace App\Services\CsvImport;

use InvalidArgumentException;

/**
 * The single declaration of what the two migration CSVs look like.
 *
 * Labels below are VERBATIM from the client's workbook (sharedStrings.xml),
 * parenthetical qualifiers and all. Do not tidy them:
 *
 *  - `Pledge Amt(If Applicable)` has no space before the bracket while the five
 *    spouse columns do. Both survive comparison because matching is done on
 *    self::labelKey(), which strips punctuation and whitespace entirely.
 *  - `email` is lowercase in the sheet while every other label is title case.
 *    That is what the file says, so that is what is written here.
 *  - Several cells carry leading spaces (" Last Name", "  Province"). Also
 *    absorbed by labelKey().
 *
 * Every positional decision in this package derives from here: the expected
 * record width, the header label sequence, the token set used to decide whether
 * the first record IS a header, the index -> field-key map the normalizers
 * read, and the per-column type used to round-trip a staged row. Nothing else
 * may hardcode a column count or an ordinal — that is what makes a
 * column-shifted import (Birthdate landing in Gender, Pledge Amt in Spouse
 * FName) possible, and a shifted import is the single worst outcome this
 * feature can produce: it writes plausible-looking garbage onto real members
 * and nothing downstream can tell.
 *
 * ORDER IS CONTRACT. Re-ordering, adding or removing an entry changes what a
 * positional file means.
 */
final class CsvImportSchema
{
    public const CUSTOMERS = 'customers';

    public const LOANS = 'loans';

    /**
     * Per-column value types, which drive normalisation AND the staged-payload
     * round trip. See NormalizedRow::toPayload().
     */
    public const TYPE_TEXT = 'text';

    public const TYPE_MONEY = 'money';

    public const TYPE_INT = 'int';

    public const TYPE_DATE = 'date';

    public const TYPE_RATE = 'rate';

    public const TYPE_ENUM = 'enum';

    /**
     * The 22 Customer Profile columns, in file order.
     *
     * @var list<array{key: string, label: string, type: string, aliases: list<string>}>
     */
    private const CUSTOMER_COLUMNS = [
        ['key' => 'account_no', 'label' => 'Account No.', 'type' => self::TYPE_TEXT, 'aliases' => ['account number', 'account', 'acct no', 'acct number', 'member no', 'member number', 'membership no']],
        ['key' => 'last_name', 'label' => 'Last Name', 'type' => self::TYPE_TEXT, 'aliases' => ['lastname', 'surname', 'family name']],
        ['key' => 'first_name', 'label' => 'First Name', 'type' => self::TYPE_TEXT, 'aliases' => ['firstname', 'given name', 'fname']],
        ['key' => 'middle_name', 'label' => 'Middle Name', 'type' => self::TYPE_TEXT, 'aliases' => ['middlename', 'mname', 'middle initial']],
        ['key' => 'suffix', 'label' => 'Suffix', 'type' => self::TYPE_TEXT, 'aliases' => ['name suffix', 'ext', 'extension', 'name ext']],
        ['key' => 'birthdate', 'label' => 'Birthdate', 'type' => self::TYPE_DATE, 'aliases' => ['birth date', 'date of birth', 'dob', 'birthday']],
        ['key' => 'gender', 'label' => 'Gender', 'type' => self::TYPE_ENUM, 'aliases' => ['sex']],
        ['key' => 'civil_status', 'label' => 'Civil Status', 'type' => self::TYPE_ENUM, 'aliases' => ['marital status', 'status']],
        ['key' => 'contact_number', 'label' => 'Contact Number', 'type' => self::TYPE_TEXT, 'aliases' => ['contact no', 'contact', 'mobile', 'mobile no', 'mobile number', 'cellphone', 'cell no', 'phone', 'phone no', 'phone number', 'tel no']],
        ['key' => 'email', 'label' => 'email', 'type' => self::TYPE_TEXT, 'aliases' => ['email address', 'e mail', 'e mail address']],
        ['key' => 'street_address', 'label' => 'Street Address', 'type' => self::TYPE_TEXT, 'aliases' => ['address', 'street', 'house no and street']],
        ['key' => 'barangay', 'label' => 'Barangay', 'type' => self::TYPE_TEXT, 'aliases' => ['brgy', 'baranggay']],
        ['key' => 'city', 'label' => 'City/Municipality', 'type' => self::TYPE_TEXT, 'aliases' => ['city', 'municipality', 'town', 'city municipality']],
        ['key' => 'province', 'label' => 'Province', 'type' => self::TYPE_TEXT, 'aliases' => ['state']],
        ['key' => 'employer_or_business', 'label' => 'Employer/Business Name', 'type' => self::TYPE_TEXT, 'aliases' => ['employer', 'business', 'employer business', 'employer or business', 'employer business name', 'occupation', 'source of income']],
        ['key' => 'monthly_income', 'label' => 'Monthly Income', 'type' => self::TYPE_MONEY, 'aliases' => ['income', 'gross monthly income']],
        ['key' => 'pledge_amount', 'label' => 'Pledge Amt(If Applicable)', 'type' => self::TYPE_MONEY, 'aliases' => ['pledge amt', 'pledge amount', 'pledge', 'share capital pledge', 'subscribed share capital']],
        ['key' => 'spouse_first_name', 'label' => 'Spouse FName (If Married)', 'type' => self::TYPE_TEXT, 'aliases' => ['spouse fname', 'spouse first name', 'spouse first', 'spouse given name']],
        ['key' => 'spouse_middle_name', 'label' => 'Spouse MName (If Married)', 'type' => self::TYPE_TEXT, 'aliases' => ['spouse mname', 'spouse middle name', 'spouse middle']],
        ['key' => 'spouse_last_name', 'label' => 'Spouse LName (If Married)', 'type' => self::TYPE_TEXT, 'aliases' => ['spouse lname', 'spouse last name', 'spouse last', 'spouse surname']],
        ['key' => 'spouse_contact_number', 'label' => 'Spouse Contact No (If Married)', 'type' => self::TYPE_TEXT, 'aliases' => ['spouse contact no', 'spouse contact number', 'spouse contact', 'spouse mobile']],
        ['key' => 'spouse_occupation', 'label' => 'Spouse Occupation (If Married)', 'type' => self::TYPE_TEXT, 'aliases' => ['spouse occupation', 'spouse work', 'spouse job', 'spouse employer', 'spouse business']],
    ];

    /**
     * The 18 Loans columns, in file order.
     *
     * Note that the loans sheet leads with Account No. and only then Loan No. —
     * the reverse of the obvious reading — and that the last four columns are
     * fees rather than the status/officer/remarks a loan export usually
     * carries. Both are exactly why this list is transcribed rather than
     * inferred.
     *
     * @var list<array{key: string, label: string, type: string, aliases: list<string>}>
     */
    private const LOAN_COLUMNS = [
        ['key' => 'account_no', 'label' => 'Account No.', 'type' => self::TYPE_TEXT, 'aliases' => ['account number', 'account', 'acct no', 'member no', 'member number']],
        ['key' => 'loan_no', 'label' => 'Loan No.', 'type' => self::TYPE_TEXT, 'aliases' => ['loan number', 'loan account no', 'loan account number', 'loan acct no', 'ln no']],
        ['key' => 'loan_amount', 'label' => 'Loan Amount', 'type' => self::TYPE_MONEY, 'aliases' => ['principal', 'principal amount', 'amount granted', 'loan amt']],
        ['key' => 'loan_balance', 'label' => 'Loan Balance', 'type' => self::TYPE_MONEY, 'aliases' => ['principal balance', 'balance', 'outstanding balance', 'loan bal']],
        ['key' => 'interest_rate', 'label' => 'Interest Rate', 'type' => self::TYPE_RATE, 'aliases' => ['rate', 'int rate']],
        ['key' => 'interest_amount', 'label' => 'Interest Amount', 'type' => self::TYPE_MONEY, 'aliases' => ['total interest', 'interest amt']],
        ['key' => 'interest_balance', 'label' => 'Interest Balance', 'type' => self::TYPE_MONEY, 'aliases' => ['interest bal', 'unpaid interest', 'remaining interest']],
        ['key' => 'purpose', 'label' => 'Purpose', 'type' => self::TYPE_TEXT, 'aliases' => ['loan purpose', 'purpose of loan']],
        ['key' => 'loan_product', 'label' => 'Loan Product', 'type' => self::TYPE_TEXT, 'aliases' => ['product', 'loan type', 'type of loan']],
        ['key' => 'term_in_months', 'label' => 'Term in Months', 'type' => self::TYPE_INT, 'aliases' => ['term months', 'term', 'no of months', 'months', 'term mos']],
        ['key' => 'payment_frequency', 'label' => 'Payment Frequency', 'type' => self::TYPE_ENUM, 'aliases' => ['frequency', 'mode of payment', 'payment mode', 'terms of payment', 'payment terms']],
        ['key' => 'interest_type', 'label' => 'Interest Type', 'type' => self::TYPE_ENUM, 'aliases' => ['interest method', 'int type', 'method']],
        ['key' => 'date_released', 'label' => 'Date Released', 'type' => self::TYPE_DATE, 'aliases' => ['release date', 'date granted', 'date of release']],
        ['key' => 'maturity_date', 'label' => 'Maturity Date', 'type' => self::TYPE_DATE, 'aliases' => ['date matured', 'maturity', 'due date', 'date of maturity']],
        ['key' => 'processing_fee', 'label' => 'Processing Fee', 'type' => self::TYPE_MONEY, 'aliases' => ['processing', 'processing fees', 'processing charge']],
        ['key' => 'service_fee', 'label' => 'Service Fee', 'type' => self::TYPE_MONEY, 'aliases' => ['service', 'service fees', 'service charge']],
        ['key' => 'other_fee_detail', 'label' => 'Other Fee Detail', 'type' => self::TYPE_TEXT, 'aliases' => ['other fee details', 'other fees detail', 'other fee description', 'other charges detail']],
        ['key' => 'other_fee_amount', 'label' => 'Other Fee Amount', 'type' => self::TYPE_MONEY, 'aliases' => ['other fee', 'other fees', 'other fee amt', 'other charges', 'other charges amount']],
    ];

    /**
     * @return list<string>
     */
    public static function shapes(): array
    {
        return [self::CUSTOMERS, self::LOANS];
    }

    /**
     * @return list<array{key: string, label: string, type: string, aliases: list<string>}>
     */
    public static function columns(string $shape): array
    {
        return match ($shape) {
            self::CUSTOMERS => self::CUSTOMER_COLUMNS,
            self::LOANS => self::LOAN_COLUMNS,
            default => throw new InvalidArgumentException("Unknown CSV shape [{$shape}]."),
        };
    }

    /**
     * The one true record width for a shape — derived, never written down twice.
     */
    public static function width(string $shape): int
    {
        return count(self::columns($shape));
    }

    /**
     * @return list<string>
     */
    public static function keys(string $shape): array
    {
        return array_column(self::columns($shape), 'key');
    }

    /**
     * @return list<string>
     */
    public static function labels(string $shape): array
    {
        return array_column(self::columns($shape), 'label');
    }

    /**
     * @return array<string, string>
     */
    public static function types(string $shape): array
    {
        return array_combine(self::keys($shape), array_column(self::columns($shape), 'type'));
    }

    public static function typeOf(string $shape, string $key): string
    {
        return self::types($shape)[$key] ?? self::TYPE_TEXT;
    }

    /**
     * Zero-based position of a field key, for the normalizers.
     */
    public static function indexOf(string $shape, string $key): int
    {
        $index = array_search($key, self::keys($shape), true);

        if ($index === false) {
            throw new InvalidArgumentException("Unknown [{$shape}] column [{$key}].");
        }

        return $index;
    }

    /**
     * Every label key a given position will accept.
     *
     * @return list<string>
     */
    public static function acceptedLabelsAt(string $shape, int $index): array
    {
        $column = self::columns($shape)[$index] ?? null;

        if ($column === null) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $label): string => self::labelKey($label),
            [$column['label'], ...$column['aliases']],
        )));
    }

    /**
     * Every label token this shape knows about, for header DETECTION (as
     * opposed to header VERIFICATION, which is positional).
     *
     * @return list<string>
     */
    public static function headerTokens(string $shape): array
    {
        $tokens = [];

        foreach (self::columns($shape) as $column) {
            foreach ([$column['label'], ...$column['aliases']] as $label) {
                $tokens[] = self::labelKey($label);
            }
        }

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => $t !== '')));
    }

    /**
     * The comparison key for a header cell: case-folded with every character
     * that is not a letter or a digit removed outright.
     *
     * Stripping rather than collapsing is deliberate, and the workbook is why.
     * "Pledge Amt(If Applicable)" has no space before its bracket while
     * "Spouse FName (If Married)" does; several cells carry leading spaces.
     * Collapsing to single spaces would make those two spacing conventions
     * different strings and reject a file for a typographic detail nobody can
     * see. Removing the characters entirely means spacing and punctuation
     * cannot fail a header at all — only the actual words can.
     */
    public static function labelKey(string $value): string
    {
        $value = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $value);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }

    /**
     * Human-readable normalisation, for enum vocabulary matching and for
     * messages. Collapses runs of non-alphanumerics to a single space rather
     * than removing them, so "Bi-Weekly" reads as "bi weekly".
     */
    public static function normalizeLabel(string $value): string
    {
        $value = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $value);
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
