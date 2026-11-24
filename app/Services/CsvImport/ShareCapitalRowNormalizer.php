<?php

namespace App\Services\CsvImport;

use App\Services\CashReferences;
use Carbon\Carbon;
use Throwable;

/**
 * Turns one row of the share capital file into typed, storable values
 * (finish run round 2, decision 13; ClickUp 14zfbg0e8ev).
 *
 * - Account No. or Member ID: required, at most 50 characters (the account
 *   number's column), and never shortened, since a shortened one could name
 *   someone else. Which member it names is settled at import
 *   (ShareCapitalRowImporter).
 * - Transaction Date: written year-month-day only, such as 2026-11-24, from
 *   1900 on and not after today in Manila. A slash date reads two ways, and a
 *   money entry is never imported on a guess.
 * - Reference Number: optional, at most the 100 characters the ledger keeps,
 *   and never shortened, since it tells one transaction from another. One of
 *   the app's own cash numbers written another way (csh-12-2026-7) is kept
 *   as the app writes it (CSH-0012-2026-0007), so it is matched as the same
 *   number.
 * - Transaction Type: the ledger's two kinds of entry, in words. Contribution
 *   is a credit and Withdrawal a debit; Credit, Debit, CR and DR read too.
 * - Amount: above ₱0.00, at most two decimals, held as integer centavos.
 * - Remarks: optional; the entry's description, shortened to 255 characters
 *   with a warning.
 *
 * ## Rows that repeat an earlier row of the file
 *
 * One instance reads one file (CsvImportStager makes one per file), and it
 * remembers the file's earlier valid rows. A row is refused here, naming the
 * line it repeats, when:
 *
 * - its reference is on an earlier row (any case); or
 * - it has no reference, and an earlier row has the same member, date, type
 *   and amount.
 *
 * The first row is kept and imported. The import pass then checks the same
 * against the member's ledger, which also catches a member written two ways
 * (account number on one row, member ID on another).
 */
final class ShareCapitalRowNormalizer
{
    /** A contribution: money into the member's share capital. */
    public const CREDIT = 'credit';

    /** A withdrawal: money out of it. */
    public const DEBIT = 'debit';

    /**
     * The transaction types, read after CsvImportSchema::normalizeLabel()
     * (lower case, words split on anything else), and the side of the ledger
     * each one is stored on.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'contribution' => self::CREDIT,
        'share capital contribution' => self::CREDIT,
        'credit' => self::CREDIT,
        'cr' => self::CREDIT,
        'withdrawal' => self::DEBIT,
        'share capital withdrawal' => self::DEBIT,
        'debit' => self::DEBIT,
        'dr' => self::DEBIT,
    ];

    public const DUPLICATE_IN_FILE = 'duplicate_in_file';

    /** `borrowers.external_account_no` is 50; a member ID is shorter. */
    private const IDENTIFIER_MAX = 50;

    /** `share_capital_ledger.reference_number`. */
    private const REFERENCE_MAX = 100;

    /** `share_capital_ledger.description`. */
    private const REMARKS_MAX = 255;

    private const EARLIEST_DATE = '1900-01-01';

    /** @var array<string, int> an earlier valid row's reference, folded, and its line */
    private array $references = [];

    /** @var array<string, int> an earlier valid row's member, date, type and amount, and its line */
    private array $transactions = [];

    public function __construct(private readonly ValueNormalizer $values = new ValueNormalizer) {}

    public function normalize(CsvRow $row): NormalizedRow
    {
        $notes = new RowNoteBag;
        $cells = $this->cellsByKey($row, $notes);

        if ($cells === null) {
            return new NormalizedRow(CsvImportSchema::SHARE_CAPITAL, $row->rowNumber, [], $notes->warnings(), $notes->errors());
        }

        $values = [
            'account_no' => $this->identifier($cells['account_no'], $notes),
            'transaction_date' => $this->transactionDate($cells['transaction_date'], $notes),
            'reference_number' => $this->referenceCell($cells['reference_number'], $notes),
            'transaction_type' => $this->transactionType($cells['transaction_type'], $notes),
            'amount' => $this->amount($cells['amount'], $notes),
            'remarks' => $this->values->boundedText($cells['remarks'], 'remarks', self::REMARKS_MAX, $notes),
        ];

        if (! $notes->hasErrors()) {
            $this->refuseRepeat($values, $row->recordNumber, $notes);
        }

        return new NormalizedRow(CsvImportSchema::SHARE_CAPITAL, $row->rowNumber, $values, $notes->warnings(), $notes->errors());
    }

    /**
     * @return array<string, string|null>|null
     */
    private function cellsByKey(CsvRow $row, RowNoteBag $notes): ?array
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::SHARE_CAPITAL);
        $expected = count($keys);
        $found = count($row->cells);

        if ($found !== $expected) {
            $notes->fail('__row', 'row_column_count', "This row has {$found} columns but {$expected} were expected, so its values cannot be matched to fields.");

            return null;
        }

        return array_combine($keys, $row->cells);
    }

    private function identifier(?string $raw, RowNoteBag $notes): ?string
    {
        $value = $this->values->text($raw);

        if ($value === null) {
            $notes->fail('account_no', 'required', 'The account number or member ID is required and the cell is blank.');

            return null;
        }

        if (mb_strlen($value) > self::IDENTIFIER_MAX) {
            $notes->fail('account_no', 'account_no_too_long', 'This is longer than the '.self::IDENTIFIER_MAX.' characters an account number or member ID can have, so it can\'t name a member.');

            return null;
        }

        return $value;
    }

    private function transactionDate(?string $raw, RowNoteBag $notes): ?string
    {
        $value = $this->values->text($raw);

        if ($value === null) {
            $notes->fail('transaction_date', 'date_required', 'The transaction date is required and the cell is blank.');

            return null;
        }

        if (! $this->isCalendarDate($value)) {
            $notes->fail('transaction_date', 'date_invalid', "\"{$value}\" isn't a date written as year-month-day, such as 2026-11-24.");

            return null;
        }

        // Both are `Y-m-d`, so the strings compare as the dates do.
        if ($value < self::EARLIEST_DATE) {
            $notes->fail('transaction_date', 'transaction_date_implausible', "\"{$value}\" is before 1900, too early for a share capital entry. Check the year.");

            return null;
        }

        if ($value > Carbon::today()->toDateString()) {
            $notes->fail('transaction_date', 'transaction_date_future', "\"{$value}\" is after today, and a share capital entry can't be dated in the future.");

            return null;
        }

        return $value;
    }

    /**
     * A real calendar date written `Y-m-d`, and only that. Read back and
     * compared, because a date that doesn't exist (2026-02-30) is otherwise
     * carried over into the next month rather than refused.
     */
    private function isCalendarDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return false;
        }

        return $date !== null && $date->format('Y-m-d') === $value;
    }

    private function referenceCell(?string $raw, RowNoteBag $notes): ?string
    {
        $value = $this->values->text($raw);

        if ($value !== null && mb_strlen($value) > self::REFERENCE_MAX) {
            $notes->fail('reference_number', 'reference_number_too_long', 'This reference number is longer than the '.self::REFERENCE_MAX.' characters the ledger keeps. Shorten it in the file: a reference is never cut short, since it tells one transaction from another.');

            return null;
        }

        return self::reference($value);
    }

    /**
     * A reference as it is kept and compared: one of the app's own cash
     * numbers (CashReferences::parse()) as the app writes it, any other as
     * typed.
     */
    public static function reference(?string $value): ?string
    {
        $number = $value === null ? null : CashReferences::parse($value);

        return $number === null
            ? $value
            : CashReferences::format($number['prefix'], $number['owner'], $number['year'], $number['sequence']);
    }

    private function transactionType(?string $raw, RowNoteBag $notes): ?string
    {
        $value = $this->values->text($raw);

        if ($value === null) {
            $notes->fail('transaction_type', 'transaction_type_required', 'The transaction type is required and the cell is blank. Write Contribution or Withdrawal.');

            return null;
        }

        $side = self::TYPES[CsvImportSchema::normalizeLabel($value)] ?? null;

        if ($side === null) {
            $notes->fail('transaction_type', 'transaction_type_invalid', "\"{$value}\" is not a transaction type. Write Contribution or Withdrawal.");
        }

        return $side;
    }

    /**
     * The amount in centavos, above zero. ValueNormalizer::centavos() refuses
     * a blank, a negative, a third decimal and anything that isn't an amount.
     */
    private function amount(?string $raw, RowNoteBag $notes): ?int
    {
        $centavos = $this->values->centavos($raw, 'amount', $notes, required: true);

        if ($centavos === 0) {
            $notes->fail('amount', 'amount_zero', 'The amount is ₱0.00. A share capital entry moves money, so its amount must be above ₱0.00.');

            return null;
        }

        return $centavos;
    }

    /**
     * Refuses a row that repeats an earlier valid row of this file, by the
     * rule in the class docblock, and otherwise remembers it.
     *
     * @param  array<string, mixed>  $values  this row's values, every one valid
     */
    private function refuseRepeat(array $values, int $line, RowNoteBag $notes): void
    {
        $reference = $values['reference_number'];
        $transaction = implode("\u{1F}", [
            mb_strtolower((string) $values['account_no']),
            $values['transaction_date'],
            $values['transaction_type'],
            $values['amount'],
        ]);

        if ($reference !== null) {
            $key = mb_strtolower($reference);
            $first = $this->references[$key] ?? null;

            if ($first !== null) {
                $notes->fail('reference_number', self::DUPLICATE_IN_FILE, "Reference {$reference} is also on line {$first} of this file. A reference number belongs to one transaction, so only that first row is imported.");

                return;
            }

            $this->references[$key] = $line;
        } elseif (isset($this->transactions[$transaction])) {
            $notes->fail('__row', self::DUPLICATE_IN_FILE, "This row repeats line {$this->transactions[$transaction]} of this file: the same member, date, type and amount, with no reference number. Only that first row is imported; if both are real, give each its own reference number.");

            return;
        }

        $this->transactions[$transaction] ??= $line;
    }
}
