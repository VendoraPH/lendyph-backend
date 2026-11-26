<?php

use App\Models\Loan;

/**
 * A loan's display number (finish run round 7): its own LN- number, else the
 * cooperative file's Loan No. (an imported loan, whose LN- number the importer
 * leaves empty), else its application number. Blank values are skipped; a loan
 * is never named "LN-<id>".
 *
 * No database, no application boot.
 */
it('takes the first number the loan has', function (?string $account, ?string $external, ?string $application, ?string $shown) {
    expect(Loan::displayNumberOf($account, $external, $application))->toBe($shown);
})->with([
    'released here' => ['LN-000042', null, 'LA-000042', 'LN-000042'],
    'imported with a Loan No.' => [null, 'OLD-2023-0041', 'LA-000006', 'OLD-2023-0041'],
    'imported without one' => [null, null, 'LA-000006', 'LA-000006'],
    'a released import keeps its LN- number first' => ['LN-000007', 'OLD-7', 'LA-000007', 'LN-000007'],
    'blank values are skipped' => ['', '  ', 'LA-000009', 'LA-000009'],
    'nothing at all' => [null, null, null, null],
]);

it('reads the model\'s own columns', function () {
    $loan = new Loan;
    $loan->setRawAttributes(['id' => 6, 'loan_account_number' => null, 'external_loan_no' => 'BC-07', 'application_number' => 'LA-000006']);

    expect($loan->displayNumber())->toBe('BC-07');
});
