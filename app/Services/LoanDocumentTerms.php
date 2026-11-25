<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanDocumentPrint;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The terms a printed loan document states, as one fingerprint, so a print
 * can be checked against the loan later (finish run round 3, "Reprint after a
 * change", R.A. 3765). LoanDocumentPrints stores it with each print and
 * compares it while the loan waits for release.
 *
 * ## Built from the documents themselves
 *
 * The values are read from the payload the document prints:
 * {@see DisclosureService::generateDisclosure()} and
 * {@see PromissoryNoteService::generatePromissoryNote()}, so a figure can't
 * differ between the paper and this check. The disclosure's catalog fees are
 * the provisional ones it computes before release. The document endpoints
 * send {@see fingerprintOf()} of the very payload they answer with, and the
 * print log stores it, so a print records the terms its paper shows even when
 * they changed between opening the document and printing it.
 *
 * Each value is listed by name, never a whole section of a payload: a key
 * added to a document later must not silently change every fingerprint. The
 * values are written as canonical strings: money to 2 decimals, rates to the
 * 4 places they are stored with (never rounded further), dates as `Y-m-d`,
 * whole numbers as digits, words as they are, a missing value as null.
 *
 * ## Never in it
 *
 * The print time, the document's title or number, the account number, the
 * product name, the member's and co-makers' names, codes, addresses and
 * contacts, and branding. None of them is a term of the loan, and the
 * member's own details change without the loan's terms changing.
 *
 * ## VERSION
 *
 * Stored with each print. Changing what goes in, or how it is written, means
 * a new version; a print under another version never blocks.
 */
final class LoanDocumentTerms
{
    public const VERSION = 1;

    public function __construct(
        private DisclosureService $disclosures,
        private PromissoryNoteService $promissoryNotes,
    ) {}

    /**
     * The SHA-256 (hex) of the terms `$document` states for `$loan` now, or
     * null when the document can't be built (for example catalog fees above
     * the principal, the release preview's own 422). Null never blocks.
     */
    public function fingerprint(Loan $loan, string $document): ?string
    {
        $terms = $this->terms($loan, $document);

        return $terms === null ? null : self::hash($terms);
    }

    /**
     * The fingerprint of a document already built: what GET
     * /loans/{loan}/disclosure and /promissory-note send with the payload, so
     * the print records the terms the paper it prints shows, by the same rule
     * as the check.
     *
     * @param  array<string, mixed>  $payload  generateDisclosure() or generatePromissoryNote()
     */
    public function fingerprintOf(string $document, array $payload): string
    {
        return self::hash($this->termsOf($document, $payload));
    }

    /**
     * The named terms the fingerprint is taken over, or null when the document
     * can't be built.
     *
     * Read from a fresh copy of the loan: building a document loads its
     * relations onto the model it is given, and the caller's copy (the loan
     * page's, the release's) keeps its own.
     *
     * @return array<string, mixed>|null
     */
    public function terms(Loan $loan, string $document): ?array
    {
        $copy = Loan::query()->find($loan->getKey());

        if ($copy === null) {
            return null;
        }

        try {
            return $this->termsOf($document, match ($document) {
                LoanDocumentPrint::DISCLOSURE => $this->disclosures->generateDisclosure($copy),
                LoanDocumentPrint::PROMISSORY_NOTE => $this->promissoryNotes->generatePromissoryNote($copy, false),
                default => throw new InvalidArgumentException("Unknown loan document [{$document}]."),
            });
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function termsOf(string $document, array $payload): array
    {
        return match ($document) {
            LoanDocumentPrint::DISCLOSURE => $this->disclosureTerms($payload),
            LoanDocumentPrint::PROMISSORY_NOTE => $this->promissoryNoteTerms($payload),
            default => throw new InvalidArgumentException("Unknown loan document [{$document}]."),
        };
    }

    /** @param  array<string, mixed>  $terms */
    private static function hash(array $terms): string
    {
        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * What the disclosure statement prints as the loan's terms.
     *
     * @param  array<string, mixed>  $disclosure  DisclosureService::generateDisclosure()
     * @return array<string, mixed>
     */
    private function disclosureTerms(array $disclosure): array
    {
        $terms = $disclosure['loan_terms'];
        $totals = $disclosure['totals'];

        return [
            'principal_amount' => self::money($terms['principal_amount']),
            'interest_rate' => self::rate($terms['interest_rate']),
            'interest_rate_frequency' => self::word($terms['interest_rate_frequency']),
            'interest_method' => self::word($terms['interest_method']),
            'term' => self::whole($terms['term']),
            'term_unit' => self::word($terms['term_unit']),
            'frequency' => self::word($terms['frequency']),
            'penalty_rate' => self::rate($terms['penalty_rate']),
            'penalty_config_name' => self::word($terms['penalty_config_name']),
            'penalty_brackets' => self::brackets($terms['penalty_brackets']),
            'grace_period_days' => self::whole($terms['grace_period_days']),
            'start_date' => self::date($terms['start_date']),
            'maturity_date' => self::date($terms['maturity_date']),
            // The stored items, then the catalog fees the release will charge:
            // each by what the statement prints, its name and amount. Never its
            // calculation type (fixed, percentage, formula), which isn't
            // printed; a change of class shows in the finance and non-finance
            // totals.
            'deductions' => array_values(array_map(fn (array $item): array => [
                'name' => self::word($item['name'] ?? null),
                'amount' => self::money($item['amount'] ?? null),
            ], array_filter($disclosure['deductions']['items'], is_array(...)))),
            'total_deductions' => self::money($totals['total_deductions']),
            'net_proceeds' => self::money($totals['net_proceeds']),
            'total_finance_charges' => self::money($totals['total_finance_charges']),
            'total_non_finance_charges' => self::money($totals['total_non_finance_charges']),
            'total_interest' => self::money($totals['total_interest']),
            'total_obligation' => self::money($totals['total_obligation']),
            'schedule' => array_map(fn (array $row): array => [
                'period_number' => self::whole($row['period_number']),
                'due_date' => self::date($row['due_date']),
                'principal_due' => self::money($row['principal_due']),
                'interest_due' => self::money($row['interest_due']),
                'total_due' => self::money($row['total_due']),
            ], array_values($disclosure['amortization_schedule'])),
        ];
    }

    /**
     * What the promissory note prints as the loan's terms: the promise (amount,
     * rate and its basis, total payable), the instalments and the penalty
     * clause.
     *
     * @param  array<string, mixed>  $note  PromissoryNoteService::generatePromissoryNote()
     * @return array<string, mixed>
     */
    private function promissoryNoteTerms(array $note): array
    {
        $terms = $note['loan_terms'];
        $schedule = $note['payment_schedule_summary'];

        return [
            'principal_amount' => self::money($terms['principal_amount']),
            'interest_rate' => self::rate($terms['interest_rate']),
            'interest_rate_frequency' => self::word($terms['interest_rate_frequency']),
            'interest_method' => self::word($terms['interest_method']),
            'term' => self::whole($terms['term']),
            'term_unit' => self::word($terms['term_unit']),
            'frequency' => self::word($terms['frequency']),
            'start_date' => self::date($terms['start_date']),
            'maturity_date' => self::date($terms['maturity_date']),
            'total_interest' => self::money($terms['total_interest']),
            'total_obligation' => self::money($terms['total_obligation']),
            'penalty_rate' => self::rate($terms['penalty_rate']),
            'penalty_config_name' => self::word($terms['penalty_config_name']),
            'penalty_brackets' => self::brackets($terms['penalty_brackets']),
            'grace_period_days' => self::whole($terms['grace_period_days']),
            'number_of_installments' => self::whole($schedule['number_of_installments']),
            'installment_amount' => self::money($schedule['installment_amount']),
            'first_due_date' => self::date($schedule['first_due_date']),
            'last_due_date' => self::date($schedule['last_due_date']),
        ];
    }

    /**
     * @param  list<array{days_from: int, days_to: int|null, rate: float}>|null  $brackets
     * @return list<array{days_from: string|null, days_to: string|null, rate: string|null}>|null
     */
    private static function brackets(?array $brackets): ?array
    {
        if ($brackets === null) {
            return null;
        }

        return array_values(array_map(fn (array $bracket): array => [
            'days_from' => self::whole($bracket['days_from']),
            'days_to' => self::whole($bracket['days_to']),
            'rate' => self::rate($bracket['rate']),
        ], $brackets));
    }

    /** Pesos to 2 decimals, never a negative zero. */
    private static function money(mixed $value): ?string
    {
        return $value === null ? null : number_format(round((float) $value, 2) + 0.0, 2, '.', '');
    }

    /** A percentage to the 4 places rates are stored with (decimal 8,4 and 7,4). */
    private static function rate(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value + 0.0, 4, '.', '');
    }

    private static function date(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => Carbon::parse((string) $value)->format('Y-m-d'),
        };
    }

    private static function whole(mixed $value): ?string
    {
        return $value === null ? null : (string) (int) $value;
    }

    private static function word(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            default => (string) $value,
        };
    }
}
