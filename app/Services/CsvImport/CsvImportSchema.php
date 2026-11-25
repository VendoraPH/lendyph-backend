<?php

namespace App\Services\CsvImport;

use InvalidArgumentException;

/**
 * The single declaration of what the three migration CSVs look like.
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
 * positional file means. A column may only ever be appended, and an appended
 * one is declared in self::APPENDED_FROM so a file made before it still reads.
 */
final class CsvImportSchema
{
    public const CUSTOMERS = 'customers';

    public const LOANS = 'loans';

    /**
     * The share capital file (finish run round 2, decision 13). Each shape is
     * named as its file's kind is (`csv_import_files.kind`).
     */
    public const SHARE_CAPITAL = 'share_capital';

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
     * The 26 Customer Profile columns, in file order: the workbook's 22, then
     * the four Member Profile fields it lacked (Date Hired from the
     * registration form, and the CDA membership columns), appended so a file
     * in the workbook's own 22-column layout still reads by position. See
     * self::APPENDED_FROM.
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
        ['key' => 'date_hired', 'label' => 'Date Hired', 'type' => self::TYPE_DATE, 'aliases' => ['hire date', 'date of hiring', 'date employed', 'employment date']],
        ['key' => 'membership_date', 'label' => 'Membership Date', 'type' => self::TYPE_DATE, 'aliases' => ['date of membership', 'member since', 'date admitted', 'date of admission']],
        ['key' => 'member_type', 'label' => 'Member Type', 'type' => self::TYPE_ENUM, 'aliases' => ['membership type', 'type of member', 'type of membership']],
        ['key' => 'membership_number', 'label' => 'Membership Number', 'type' => self::TYPE_TEXT, 'aliases' => ['membership no', 'membership num', 'cda membership number']],
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
     * The 6 Share Capital columns, in file order: who, when, which reference,
     * which way, how much, and the entry's description. Its own template, not
     * the client's workbook, so the labels are the app's words.
     *
     * The first column matches a member by their account number (the members
     * file's Account No., `borrowers.external_account_no`) or their member ID
     * (`borrowers.borrower_code`). Its key is `account_no`, like the other two
     * files', because the stager files every staged row under that value for
     * erasure (CsvImportStager::externalAccountNo()).
     *
     * @var list<array{key: string, label: string, type: string, aliases: list<string>}>
     */
    private const SHARE_CAPITAL_COLUMNS = [
        ['key' => 'account_no', 'label' => 'Account No. or Member ID', 'type' => self::TYPE_TEXT, 'aliases' => ['account no', 'account number', 'account', 'acct no', 'member id', 'member no', 'member number', 'member code', 'member account', 'member account no']],
        ['key' => 'transaction_date', 'label' => 'Transaction Date', 'type' => self::TYPE_DATE, 'aliases' => ['date', 'trans date', 'txn date', 'entry date', 'posting date', 'date of transaction']],
        ['key' => 'reference_number', 'label' => 'Reference Number', 'type' => self::TYPE_TEXT, 'aliases' => ['reference', 'reference no', 'ref', 'ref no', 'ref number', 'or no', 'or number', 'receipt no', 'receipt number']],
        ['key' => 'transaction_type', 'label' => 'Transaction Type', 'type' => self::TYPE_ENUM, 'aliases' => ['type', 'trans type', 'txn type', 'entry type', 'type of transaction']],
        ['key' => 'amount', 'label' => 'Amount', 'type' => self::TYPE_MONEY, 'aliases' => ['amt', 'transaction amount']],
        ['key' => 'remarks', 'label' => 'Remarks', 'type' => self::TYPE_TEXT, 'aliases' => ['remark', 'description', 'particulars', 'notes', 'memo']],
    ];

    /**
     * Columns appended to a shape after its first layout, by the key of the
     * first one appended.
     *
     * A file made before them is the same file without its last columns:
     * every column it has keeps its position, so it is still read
     * positionally, and the appended fields read as blank (not set). That is
     * the ONLY way a column may be added. One inserted anywhere else would move
     * an earlier file's values into the wrong fields, which is the outcome
     * this whole package exists to prevent.
     */
    private const APPENDED_FROM = [
        self::CUSTOMERS => 'date_hired',
    ];

    /**
     * @return list<string>
     */
    public static function shapes(): array
    {
        return [self::CUSTOMERS, self::LOANS, self::SHARE_CAPITAL];
    }

    /**
     * The shape a file of this kind is read as: its own, since a kind is its
     * shape's name, or the members file's for a kind this schema doesn't know.
     */
    public static function shapeOf(?string $kind): string
    {
        return in_array($kind, self::shapes(), true) ? (string) $kind : self::CUSTOMERS;
    }

    /**
     * The record widths a file of this shape may have: today's layout first,
     * then the earlier one (the layout before the appended columns), if any.
     *
     * The file's own width decides which layout it is, and every row of the
     * file is then held to that one width. Accepting either width row by row
     * would read a row of an old file that grew four stray separators as a
     * row of the new layout, with every value after them in the wrong field.
     *
     * @return list<int>
     */
    public static function acceptedWidths(string $shape): array
    {
        $first = self::APPENDED_FROM[$shape] ?? null;

        return $first === null
            ? [self::width($shape)]
            : [self::width($shape), self::indexOf($shape, $first)];
    }

    /**
     * The labels of the appended columns, which an earlier file does not have.
     *
     * @return list<string>
     */
    public static function appendedLabels(string $shape): array
    {
        $first = self::APPENDED_FROM[$shape] ?? null;

        return $first === null ? [] : array_slice(self::labels($shape), self::indexOf($shape, $first));
    }

    /**
     * @return list<array{key: string, label: string, type: string, aliases: list<string>}>
     */
    public static function columns(string $shape): array
    {
        return match ($shape) {
            self::CUSTOMERS => self::CUSTOMER_COLUMNS,
            self::LOANS => self::LOAN_COLUMNS,
            self::SHARE_CAPITAL => self::SHARE_CAPITAL_COLUMNS,
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
     * The keys of a shape's date columns, which a run may carry a date order
     * for (CsvImportRun::dateOrdersFor()).
     *
     * @return list<string>
     */
    public static function dateKeys(string $shape): array
    {
        return array_values(array_column(array_filter(
            self::columns($shape),
            static fn (array $column): bool => $column['type'] === self::TYPE_DATE,
        ), 'key'));
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
