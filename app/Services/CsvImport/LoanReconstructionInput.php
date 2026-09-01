<?php

namespace App\Services\CsvImport;

use InvalidArgumentException;

/**
 * The figures a loan's schedule is rebuilt from, all money in INTEGER CENTAVOS.
 *
 * P, B, I and IB are the coop's own numbers, straight off the export. The
 * reconstruction exists to reproduce them exactly, not to recompute them.
 */
final class LoanReconstructionInput
{
    public function __construct(
        public readonly int $principalCentavos,
        public readonly int $balanceCentavos,
        public readonly int $interestCentavos,
        public readonly int $interestBalanceCentavos,
        public readonly string $interestRate,
        public readonly string $frequency,
        public readonly string $interestMethod,
        public readonly string $dateReleased,
        public readonly string $maturityDate,
        public readonly ?int $termInMonths = null,
    ) {}

    /**
     * Build from a LoanRowNormalizer result.
     *
     * @throws InvalidArgumentException when the row did not normalise cleanly —
     *                                  callers must check NormalizedRow::isValid() first
     */
    public static function fromNormalizedRow(NormalizedRow $row): self
    {
        // Value keys are CsvImportSchema field keys — i.e. what the COLUMN is
        // called — so that the staged payload stays positional against the
        // schema. The translation to the Loan model's own vocabulary
        // (payment_frequency -> frequency, interest_type -> interest_method)
        // happens here, once.
        $required = [
            'loan_amount', 'loan_balance', 'interest_amount', 'interest_balance',
            'interest_rate', 'payment_frequency', 'interest_type', 'date_released', 'maturity_date',
        ];

        foreach ($required as $key) {
            if ($row->value($key) === null) {
                throw new InvalidArgumentException("Row {$row->rowNumber} cannot be reconstructed: [{$key}] did not normalise.");
            }
        }

        return new self(
            principalCentavos: (int) $row->value('loan_amount'),
            balanceCentavos: (int) $row->value('loan_balance'),
            interestCentavos: (int) $row->value('interest_amount'),
            interestBalanceCentavos: (int) $row->value('interest_balance'),
            interestRate: (string) $row->value('interest_rate'),
            frequency: (string) $row->value('payment_frequency'),
            interestMethod: (string) $row->value('interest_type'),
            dateReleased: (string) $row->value('date_released'),
            maturityDate: (string) $row->value('maturity_date'),
            termInMonths: $row->value('term_in_months') === null ? null : (int) $row->value('term_in_months'),
        );
    }
}
