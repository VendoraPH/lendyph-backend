<?php

namespace App\Services\CsvImport;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * The normalisation primitives every imported cell passes through.
 *
 * Normalisation happens BEFORE validation, and the ordering is not stylistic.
 * Laravel's ConvertEmptyStringsToNull is HTTP middleware: it runs on requests,
 * and this importer validates outside the request lifecycle. So `''` never
 * becomes null on its own here, and a rule set of ['nullable', 'date'] fails
 * against `''` — which would turn every blank optional cell in the workbook,
 * of which there are hundreds, into a validation error. Mapping `''` to null
 * here is what makes "nullable" mean what it says.
 */
class ValueNormalizer
{
    /**
     * The app's own contact-number rule, copied from
     * App\Http\Requests\Borrower\Concerns\HasBorrowerRules.
     *
     * It is duplicated rather than referenced because the rule lives in a
     * protected method on a FormRequest and there is no way to read it outside
     * one. ValueNormalizerContactRegexTest reflects the real trait and asserts
     * this constant is still identical, so the copy cannot drift silently.
     */
    public const CONTACT_NUMBER_REGEX = '/^(\+?\d{7,15}|0\d{9,10})$/';

    /**
     * Largest value that fits decimal(12,2), in centavos.
     */
    public const MAX_CENTAVOS = 999999999999;

    /**
     * Date formats tried in order. Slash dates are read US-first because that
     * is how the source workbook was typed; see self::date() for what happens
     * when a value reads validly both ways.
     *
     * @var list<string>
     */
    private const DATE_FORMATS = ['Y-m-d', 'Y/m/d', 'm/d/Y', 'n/j/Y', 'd-M-Y', 'j M Y', 'M j, Y'];

    /**
     * Day-first counterpart of each ambiguous month-first format.
     *
     * @var array<string, string>
     */
    private const DAY_FIRST_COUNTERPARTS = ['m/d/Y' => 'd/m/Y', 'n/j/Y' => 'j/n/Y'];

    /**
     * Range of Excel serial dates we will convert: roughly 1954 to 2064.
     *
     * Bounded on purpose. Outside this window an all-digit cell is far more
     * likely to be a mistyped date or a stray reference number than a serial,
     * and converting it would invent a date nobody typed.
     */
    private const EXCEL_SERIAL_MIN = 20000;

    private const EXCEL_SERIAL_MAX = 60000;

    /**
     * Trim, repair, and map empty to null.
     *
     * Strips a BOM (Excel writes one on the first cell, and a stray U+FEFF
     * elsewhere is a zero-width no-break space that is invisible in every UI
     * but makes "\u{FEFF}A-001" a different string from "A-001") and converts
     * the non-breaking space U+00A0, which Excel emits wherever a value was
     * pasted from a formatted source.
     */
    public function text(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $raw);
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Trim and cap to a column width, noting the truncation.
     */
    public function boundedText(?string $raw, string $field, int $max, RowNoteBag $notes): ?string
    {
        $value = $this->text($raw);

        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $notes->warn($field, 'truncated', "\"{$value}\" is longer than {$max} characters and was shortened to fit.");

        return mb_substr($value, 0, $max);
    }

    /**
     * Money as INTEGER CENTAVOS.
     *
     * Everything downstream of this method is integer arithmetic. Money that
     * enters as a float has already lost the argument: ₱10,000.01 is not
     * representable in binary floating point, and summing 7 periods of it and
     * comparing to a stored balance is how a schedule ends up one centavo short
     * of the member's passbook.
     *
     * Rejections rather than repairs, and each for a reason:
     *  - "(1,234.00)" is accounting notation for NEGATIVE. Stripping the
     *    parentheses would import a debt as a credit.
     *  - "1234.567" has more precision than the column holds. Rounding it
     *    silently changes a figure a member can check against their passbook,
     *    so it is refused and a human decides.
     */
    public function centavos(?string $raw, string $field, RowNoteBag $notes, bool $required = false): ?int
    {
        $value = $this->text($raw);

        if ($value === null) {
            if ($required) {
                $notes->fail($field, 'money_required', 'This amount is required and the cell is blank.');
            }

            return null;
        }

        $original = $value;

        if (str_starts_with($value, '(') || str_ends_with($value, ')')) {
            $notes->fail($field, 'money_negative', "\"{$original}\" is written in accounting notation for a negative amount. Negative amounts cannot be imported.");

            return null;
        }

        $value = str_replace(['₱', ',', ' ', "\u{00A0}"], '', $value);
        $value = (string) preg_replace('/php/i', '', $value);
        $value = ltrim($value, '+');

        if (str_starts_with($value, '-') || str_ends_with($value, '-')) {
            $notes->fail($field, 'money_negative', "\"{$original}\" is a negative amount, which cannot be imported.");

            return null;
        }

        if (preg_match('/^\d*\.\d{3,}$/', $value) === 1) {
            $notes->fail($field, 'money_precision', "\"{$original}\" has more than 2 decimal places. Round it to centavos in the source file — this importer will not round it for you.");

            return null;
        }

        if (preg_match('/^(\d+(\.\d{1,2})?|\.\d{1,2})$/', $value) !== 1) {
            $notes->fail($field, 'money_invalid', "\"{$original}\" is not a valid amount.");

            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $centavos = (int) ($whole === '' ? '0' : $whole) * 100 + (int) str_pad($fraction, 2, '0');

        if ($centavos > self::MAX_CENTAVOS) {
            $notes->fail($field, 'money_overflow', "\"{$original}\" is larger than the maximum this system stores (9,999,999,999.99).");

            return null;
        }

        return $centavos;
    }

    /**
     * A rate as a plain decimal STRING, never a float.
     *
     * The column is decimal(8,4); handing Eloquent a string keeps the value
     * byte-identical to what the coop typed.
     */
    public function rate(?string $raw, string $field, RowNoteBag $notes, bool $required = false): ?string
    {
        $value = $this->text($raw);

        if ($value === null) {
            if ($required) {
                $notes->fail($field, 'rate_required', 'The interest rate is required and the cell is blank.');
            }

            return null;
        }

        $original = $value;
        $value = rtrim(str_replace(['%', ' ', ','], '', $value));

        if (preg_match('/^\d+(\.\d{1,4})?$/', $value) !== 1) {
            $notes->fail($field, 'rate_invalid', "\"{$original}\" is not a valid interest rate.");

            return null;
        }

        if ((float) $value > 9999.9999) {
            $notes->fail($field, 'rate_overflow', "\"{$original}\" is larger than the maximum interest rate this system stores.");

            return null;
        }

        return $value;
    }

    public function integer(?string $raw, string $field, RowNoteBag $notes, int $min, int $max, bool $required = false): ?int
    {
        $value = $this->text($raw);

        if ($value === null) {
            if ($required) {
                $notes->fail($field, 'integer_required', 'This value is required and the cell is blank.');
            }

            return null;
        }

        $original = $value;
        $value = str_replace([',', ' '], '', $value);

        if (preg_match('/^\d+$/', $value) !== 1) {
            $notes->fail($field, 'integer_invalid', "\"{$original}\" is not a whole number.");

            return null;
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            $notes->fail($field, 'integer_out_of_range', "\"{$original}\" is outside the accepted range {$min}–{$max}.");

            return null;
        }

        return $number;
    }

    /**
     * A date as `Y-m-d`, or null.
     *
     * Carbon::createFromFormat is used with an explicit format list and a
     * ROUND-TRIP check, because createFromFormat does not validate — it
     * OVERFLOWS. createFromFormat('m/d/Y', '13/45/2020') happily returns
     * 2021-02-14, a date that appears nowhere in the file and that no operator
     * would ever spot in a success report. Re-formatting the result and
     * comparing it to the input is the only thing that catches it.
     */
    public function date(?string $raw, string $field, RowNoteBag $notes, bool $required = false): ?string
    {
        $value = $this->text($raw);

        if ($value === null) {
            if ($required) {
                $notes->fail($field, 'date_required', 'This date is required and the cell is blank.');
            }

            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $parsed = $this->parseExact($value, $format);

            if ($parsed === null) {
                continue;
            }

            $this->warnIfAmbiguous($value, $format, $parsed, $field, $notes);

            return $parsed;
        }

        $serial = $this->excelSerial($value);

        if ($serial !== null) {
            $converted = Carbon::create(1899, 12, 30)->addDays($serial)->toDateString();

            $notes->warn($field, 'excel_serial_date', "\"{$value}\" is an Excel serial date number and was read as {$converted}. Confirm it against the source workbook.");

            return $converted;
        }

        $notes->fail($field, 'date_invalid', "\"{$value}\" is not a date this importer recognises. Use YYYY-MM-DD.");

        return null;
    }

    /**
     * Parse only if the value re-formats back to exactly what arrived.
     */
    private function parseExact(string $value, string $format): ?string
    {
        try {
            $parsed = Carbon::createFromFormat($format, $value);
        } catch (\Throwable) {
            return null;
        }

        if ($parsed === false) {
            return null;
        }

        return $parsed->format($format) === $value ? $parsed->toDateString() : null;
    }

    /**
     * A slash date whose day is 12 or lower reads validly both ways, and the
     * two readings are different days.
     *
     * We commit to the US reading because that is how the workbook was typed,
     * but we say so — "03/04/2020" imported as 4 March when the member's
     * passbook says 3 April is a silent, plausible, unfindable error, and the
     * only defence is naming both readings where an operator will see them.
     */
    private function warnIfAmbiguous(string $value, string $format, string $chosen, string $field, RowNoteBag $notes): void
    {
        $counterpart = self::DAY_FIRST_COUNTERPARTS[$format] ?? null;

        if ($counterpart === null) {
            return;
        }

        $alternative = $this->parseExact($value, $counterpart);

        if ($alternative === null || $alternative === $chosen) {
            return;
        }

        $notes->warn($field, 'ambiguous_date', "\"{$value}\" could be read as {$chosen} (month first, which is what was used) or as {$alternative} (day first). Check it against the source workbook.");
    }

    private function excelSerial(string $value): ?int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $serial = (int) $value;

        return $serial >= self::EXCEL_SERIAL_MIN && $serial <= self::EXCEL_SERIAL_MAX ? $serial : null;
    }

    /**
     * A contact number the app's own rules will accept, or null with a warning.
     *
     * The downgrade is deliberate. UpdateBorrowerRequest applies the identical
     * regex, so a member imported carrying a number that regex refuses could
     * never be edited again through the UI — every save would fail on a field
     * the operator did not touch. Failing the row instead would leave the member
     * out of the system entirely and orphan every loan that points at them.
     * Importing them with a blank number and a warning is the only outcome that
     * leaves a human able to fix it.
     */
    public function contactNumber(?string $raw, string $field, RowNoteBag $notes, bool $enforceAppFormat = true): ?string
    {
        $value = $this->text($raw);

        if ($value === null) {
            return null;
        }

        $original = $value;
        $parts = array_values(array_filter(
            array_map('trim', (array) preg_split('#[/;,]#', $value)),
            static fn (string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            $notes->warn($field, 'contact_number_unusable', "\"{$original}\" is not a usable contact number and was left blank.");

            return null;
        }

        $candidate = $parts[0];
        $prefix = str_starts_with($candidate, '+') ? '+' : '';
        $digits = (string) preg_replace('/\D/', '', $candidate);
        $normalized = $prefix.$digits;

        /*
         * Only claim the cell held a LIST of numbers if the part that was kept
         * is one.
         *
         * The separators include `/`, so `N/A` splits into `N` and `A` — two
         * non-empty parts — and the cell is reported as holding more than one
         * number, of which Lendyph "kept N and dropped A". It then fails the
         * regex and is reported as invalid as well. The member imports correctly
         * with a blank number either way, so this is purely about the report
         * — and the report is the entire recovery mechanism for a migration.
         * One obviously-wrong line is how an operator learns to stop reading it.
         *
         * If the kept part has no digits in it at all, the value was never a
         * list of numbers; the single contact_number_invalid warning below tells
         * the whole truth on its own.
         */
        if (count($parts) > 1 && $digits !== '') {
            $dropped = implode(', ', array_slice($parts, 1));
            $notes->warn($field, 'contact_number_multiple', "\"{$original}\" holds more than one number. The first was kept and \"{$dropped}\" dropped — the field stores only 20 characters, which is less than two mobile numbers.");
        }

        if ($normalized !== '' && preg_match(self::CONTACT_NUMBER_REGEX, $normalized) === 1) {
            return $normalized;
        }

        // Spouse contact number carries no regex in HasBorrowerRules — only
        // `max:20` — so a value the app WOULD store must not be thrown away
        // here. Only fields the app itself validates get the downgrade.
        if (! $enforceAppFormat) {
            return $this->boundedText($candidate, $field, 20, $notes);
        }

        $notes->warn($field, 'contact_number_invalid', "\"{$original}\" is not a contact number this system accepts, so it was imported as blank. A member carrying a value the borrower form rejects can never be saved again — fix it in the app after the import.");

        return null;
    }

    /**
     * An email the app's own rules will accept, or null with a warning.
     *
     * Same reasoning as self::contactNumber(). Uniqueness is NOT checked here —
     * that is a decision about two rows, not about this one, and belongs to the
     * persistence layer that can see both.
     */
    public function email(?string $raw, string $field, RowNoteBag $notes): ?string
    {
        $value = $this->text($raw);

        if ($value === null) {
            return null;
        }

        $failed = Validator::make(['email' => $value], ['email' => ['email', 'max:255']])->fails();

        if ($failed) {
            $notes->warn($field, 'email_invalid', "\"{$value}\" is not an email address this system accepts, so it was imported as blank. A member carrying a value the borrower form rejects can never be saved again — fix it in the app after the import.");

            return null;
        }

        return $value;
    }

    /**
     * Map a title-case CSV word onto the lowercase snake_case value stored.
     *
     * The source workbook carries no data-validation rules, so the vocabulary
     * is whatever 44 members' worth of hand typing produced: "MARRIED", "F",
     * "Bi-Weekly", "Straight (Fixed)". Keys are matched after folding case and
     * collapsing punctuation, so all four forms land.
     *
     * @param  array<string, string>  $map
     */
    public function enum(?string $raw, array $map, string $field, RowNoteBag $notes, bool $required = false, ?string $label = null): ?string
    {
        $value = $this->text($raw);
        $label ??= $field;

        if ($value === null) {
            if ($required) {
                $notes->fail($field, 'enum_required', "The {$label} is required and the cell is blank.");
            }

            return null;
        }

        $token = CsvImportSchema::normalizeLabel($value);

        if (array_key_exists($token, $map)) {
            return $map[$token];
        }

        $accepted = implode(', ', array_values(array_unique($map)));

        if ($required) {
            $notes->fail($field, 'enum_invalid', "\"{$value}\" is not a {$label} this system recognises. Accepted values: {$accepted}.");

            return null;
        }

        // Nullable columns take the same downgrade as contact number and email,
        // and for the same reason: the value cannot be stored, and refusing the
        // whole row over an optional field would orphan the member's loans.
        $notes->warn($field, 'enum_unmapped', "\"{$value}\" is not a {$label} this system recognises, so it was imported as blank. Accepted values: {$accepted}.");

        return null;
    }
}
