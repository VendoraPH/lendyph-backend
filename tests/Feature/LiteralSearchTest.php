<?php

/*
 * Every list search matches `%`, `_` and `\` literally and treats `0` as a
 * real search term.
 *
 * The searches interpolated the raw term into `%…%`, so LIKE's wildcards
 * stayed wildcards: `50%` also found `500`, and `_` (any one character) listed
 * every row. The walk-in search also gated on truthiness, so `?search=0` was
 * dropped and listed every walk-in. All of them now build their pattern with
 * LikePattern::contains() and gate on filled().
 */

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

/**
 * Letters only, so a column it fills can never match `0`, `_` or `50%`.
 */
function literalSearchFiller(): string
{
    return fake()->unique()->lexify('??????????');
}

/**
 * Auto-stamped codes are zero-padded (`BRW-000001`), so every one contains a
 * `0` and matches a `0` search by itself, which would hide whether the search
 * filtered at all. Swapping the zeros out leaves only the text under test to
 * match.
 *
 * @template TModel of Model
 *
 * @param  TModel  $model
 * @return TModel
 */
function literalSearchWithoutZeros(Model $model, string $column): Model
{
    $model->forceFill([$column => strtr($model->{$column}, '0', '1')])->saveQuietly();

    return $model;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function literalSearchBorrower(array $attributes = []): Borrower
{
    $borrower = Borrower::factory()->create([
        'first_name' => literalSearchFiller(),
        'middle_name' => null,
        'last_name' => literalSearchFiller(),
        'contact_number' => literalSearchFiller(),
        'email' => literalSearchFiller().'@example.com',
        ...$attributes,
    ]);

    return literalSearchWithoutZeros($borrower, 'borrower_code');
}

function literalSearchLoan(Borrower $borrower): Loan
{
    return literalSearchWithoutZeros(Loan::factory()->create(['borrower_id' => $borrower->id]), 'application_number');
}

function literalSearchUser(string $firstName): User
{
    return User::factory()->create([
        'first_name' => $firstName,
        'last_name' => literalSearchFiller(),
        'username' => literalSearchFiller(),
        'email' => literalSearchFiller().'@example.com',
    ]);
}

it('matches a percent sign and an underscore literally and filters on 0', function (string $url, Closure $make) {
    $percent = $make('Rate 50% Promo');
    $fiveHundred = $make('Rate 500 Promo');
    $underscore = $make('Ana_Reyes');
    $plain = $make('Ben Cruz');

    $idsFor = fn (string $term): array => collect($this->getJson($url.urlencode($term))->assertOk()->json('data'))
        ->pluck('id')
        ->all();

    expect($idsFor('50%'))->toContain($percent)->not->toContain($fiveHundred)
        ->and($idsFor('_'))->toContain($underscore)->not->toContain($plain)
        ->and($idsFor('0'))->toContain($percent, $fiveHundred)->not->toContain($underscore)->not->toContain($plain);
})->with([
    'borrowers' => [
        '/api/borrowers?per_page=100&search=',
        fn (string $text) => literalSearchBorrower(['last_name' => $text])->id,
    ],
    'loans' => [
        '/api/loans?per_page=100&search=',
        fn (string $text) => literalSearchLoan(literalSearchBorrower(['last_name' => $text]))->id,
    ],
    'repayments' => [
        '/api/repayments?per_page=100&search=',
        fn (string $text) => literalSearchWithoutZeros(
            Repayment::factory()->create(['loan_id' => literalSearchLoan(literalSearchBorrower(['last_name' => $text]))->id]),
            'receipt_number',
        )->id,
    ],
    'users' => [
        '/api/users?per_page=100&search=',
        fn (string $text) => literalSearchUser($text)->id,
    ],
    'staff' => [
        '/api/staff?per_page=100&search=',
        fn (string $text) => literalSearchUser($text)->id,
    ],
    'loan products' => [
        '/api/loan-products?search=',
        fn (string $text) => LoanProduct::factory()->create(['name' => $text])->id,
    ],
    'share-capital ledger' => [
        '/api/share-capital/ledger?per_page=100&search=',
        fn (string $text) => ShareCapitalLedger::factory()->create([
            'borrower_id' => literalSearchBorrower()->id,
            'reference' => strtoupper(literalSearchFiller()),
            'description' => $text,
        ])->id,
    ],
    'share-capital pledges' => [
        '/api/pledges?per_page=100&search=',
        fn (string $text) => literalSearchBorrower(['first_name' => $text])->shareCapitalPledge->id,
    ],
    'audit logs' => [
        '/api/audit-logs?per_page=100&search=',
        fn (string $text) => AuditLog::create(['action' => 'created', 'description' => $text])->id,
    ],
    'GCash walk-ins' => [
        '/api/gcash/non-members?per_page=100&search=',
        fn (string $text) => GCashNonMember::factory()->create([
            'full_name' => $text,
            'mobile_number' => literalSearchFiller(),
            'id_number' => strtoupper(literalSearchFiller()),
        ])->id,
    ],
]);

/**
 * Kept out of the dataset above: the audit list eager-loads `auditable`, and
 * with no morph map a type that is not a class name is a 500, so these rows can
 * only hold real class names, none of which contains a `%`, `_` or `0`. The
 * backslash is what this filter actually receives: unescaped, the `\M` in
 * `App\Models\Loan` was read as an escaped `M` and the full class name matched
 * nothing.
 */
it('matches the audit-log type filter literally, backslashes included', function () {
    $loanRow = AuditLog::create(['action' => 'created', 'auditable_type' => Loan::class, 'description' => literalSearchFiller()])->id;
    $borrowerRow = AuditLog::create(['action' => 'created', 'auditable_type' => Borrower::class, 'description' => literalSearchFiller()])->id;

    $idsFor = fn (string $type): array => collect($this->getJson('/api/audit-logs?per_page=100&auditable_type='.urlencode($type))->assertOk()->json('data'))
        ->pluck('id')
        ->all();

    expect($idsFor(Loan::class))->toContain($loanRow)->not->toContain($borrowerRow);

    foreach (['50%', '_', '0'] as $term) {
        expect($idsFor($term))->not->toContain($loanRow)->not->toContain($borrowerRow);
    }
});
