<?php

namespace App\Services\CsvImport;

/**
 * What one pass of CsvImportStager did to one file.
 *
 * `loanProducts` is the distinct set of "Loan Product" strings seen in a loans
 * file, in first-seen order. It is the whole input to the mapping phase: the
 * admin is shown these and picks a LoanProduct for each, and nothing is written
 * until they have. Empty for a customers file.
 */
final class StagingResult
{
    /**
     * @param  list<string>  $loanProducts
     * @param  list<string>  $notes  File-level observations from the reader — an
     *                               encoding repair, a truncated product list.
     */
    public function __construct(
        public readonly int $staged,
        public readonly int $valid,
        public readonly int $invalid,
        public readonly array $loanProducts = [],
        public readonly array $notes = [],
    ) {}
}
