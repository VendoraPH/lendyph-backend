<?php

namespace Tests\Feature\Accounting\LoanAccounting;

use App\Models\Branch;
use App\Services\Accounting\ReceivablePosition;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The Loan Accounting summary's `books` (ReceivablePosition at `as_of`) and
 * `books_vs_records` (the books' gross receivable today against the loan
 * records' outstanding principal today).
 */
class LoanAccountingBooksTest extends TestCase
{
    use LoanAccountingTestHelpers, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function summary(string $query = ''): array
    {
        return $this->getJson('/api/accounting/loan-accounting/summary?'.$query)->assertOk()->json('data');
    }

    public function test_books_are_the_receivable_position_at_as_of_with_the_review_flag(): void
    {
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('1110', '1010', 6000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('5140', '1200', 500000, ['date' => '2026-09-06']);
        $this->postSimpleJournal('1110', '1210', 300000, ['date' => '2026-09-07']);
        $this->postSimpleJournal('1180', '1110', 20000, ['date' => '2026-09-08']);

        $this->assertSame([
            'gross' => 6280000,
            'allowance' => 500000,
            'unearned_income' => 300000,
            'adjustments_clearing' => 20000,
            'adjustments_clearing_needs_review' => true,
            'net' => 5480000,
        ], $this->summary('as_of=2026-09-30')['books']);

        // Before the clearing entry, 1180 is zero and needs no review.
        $books = $this->summary('as_of=2026-09-07')['books'];
        $this->assertSame(0, $books['adjustments_clearing']);
        $this->assertFalse($books['adjustments_clearing_needs_review']);
        $this->assertSame(6300000, $books['gross']);
    }

    public function test_a_credit_balance_on_adjustments_clearing_also_needs_review(): void
    {
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('1110', '1180', 15000, ['date' => '2026-09-03']);

        $books = $this->summary('as_of=2026-09-30')['books'];

        $this->assertSame(-15000, $books['adjustments_clearing']);
        $this->assertTrue($books['adjustments_clearing_needs_review']);
    }

    public function test_a_loan_released_with_books_reconciles(): void
    {
        $this->seedChartOfAccounts();
        $this->createReleasedLoan(['principal_amount' => 61234.57]);

        $summary = $this->summary('as_of='.Carbon::today()->subYear()->toDateString());
        $check = $summary['books_vs_records'];

        // `books` follows as_of (a year ago: nothing posted yet); the check is today's.
        $this->assertSame(0, $summary['books']['gross']);
        $this->assertSame([
            'as_of' => Carbon::today()->toDateString(),
            'books_principal' => 6123457,
            'records_principal' => 6123457,
            'difference' => 0,
            'is_reconciled' => true,
        ], $check);
        $this->assertSame($summary['records']['outstanding_principal'], $check['records_principal']);
        $this->assertSame(ReceivablePosition::build(Carbon::today()->toDateString(), null)['gross'], $check['books_principal']);
    }

    public function test_a_loan_released_before_the_books_existed_shows_minus_its_principal(): void
    {
        // Released with no chart: the release posts no journal.
        $this->createReleasedLoan(['principal_amount' => 25000.50]);
        $this->seedChartOfAccounts();
        $this->createReleasedLoan(['principal_amount' => 10000]);

        $check = $this->summary()['books_vs_records'];

        $this->assertSame(1000000, $check['books_principal']);
        $this->assertSame(3500050, $check['records_principal']);
        $this->assertSame(-2500050, $check['difference']);
        $this->assertFalse($check['is_reconciled']);
    }

    public function test_books_follow_the_callers_branches(): void
    {
        $this->seedChartOfAccounts();
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->postSimpleJournal('1110', '1010', 100000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1110', '1010', 700000, ['date' => '2026-09-01', 'branch_id' => $far->id]);
        $this->postSimpleJournal('1180', '1110', 3000, ['date' => '2026-09-02', 'branch_id' => $far->id]);

        $this->assertSame(797000, $this->summary('as_of=2026-09-30')['books']['gross']);
        $this->assertSame(697000, $this->summary("as_of=2026-09-30&branch_id={$far->id}")['books']['gross']);

        $this->actingAs($this->branchUser($this->branch));

        $summary = $this->summary('as_of=2026-09-30');
        $this->assertSame(100000, $summary['books']['gross']);
        $this->assertFalse($summary['books']['adjustments_clearing_needs_review']);
        $this->assertSame(100000, $summary['books_vs_records']['books_principal']);
        $this->getJson("/api/accounting/loan-accounting/summary?branch_id={$far->id}")->assertForbidden();
    }

    public function test_the_summary_has_the_contract_shape(): void
    {
        $summary = $this->summary();

        $this->assertSame(['as_of', 'aging', 'books', 'records', 'books_vs_records'], array_keys($summary));
        $this->assertSame(
            ['gross', 'allowance', 'unearned_income', 'adjustments_clearing', 'adjustments_clearing_needs_review', 'net'],
            array_keys($summary['books']),
        );
        $this->assertSame(
            ['as_of', 'books_principal', 'records_principal', 'difference', 'is_reconciled'],
            array_keys($summary['books_vs_records']),
        );
        // No chart at all: every book figure reads 0, and an empty book reconciles.
        $this->assertSame(0, $summary['books']['gross']);
        $this->assertTrue($summary['books_vs_records']['is_reconciled']);
    }
}
