<?php

namespace Tests\Feature\Ledger\Adjustments;

use App\Http\Requests\Loan\ExtendLoanRequest;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Services\LoanAdjustmentService;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * An extension on "pay" takes a method and a reference, as Record Payment
 * does (Phase 2, decision 4): the collection stores them on its repayment,
 * and its journal debits that method's settlement account. The reference is
 * required unless the method is cash, and cash is the default. On "defer"
 * nothing is collected, so both are ignored.
 *
 * The loan: ₱60,000 for one month at 3% upon maturity, so "pay" collects the
 * ₱1,800 interest. Its period is moved a week ahead, so no penalty is owed.
 */
class ExtensionPayMethodTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-06-10 09:00:00');
    }

    public function test_pay_by_gcash_stores_the_method_and_reference_and_debits_1020(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->extendableLoan();

        app(LoanAdjustmentService::class)->extendLoan($loan, null, $this->admin, 'pay', 'gcash', 'REF-1');

        $collection = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertSame('gcash', $collection->method);
        $this->assertSame('REF-1', $collection->reference_number);
        $this->assertEqualsWithDelta(1800.0, (float) $collection->amount_paid, 0.001);

        $journal = $this->collectionJournal($collection);
        $this->assertSame(180_000, $this->sumOn($journal, '1020', 'debit'));
        $this->assertSame(0, $this->sumOn($journal, '1010', 'debit'));
    }

    public function test_pay_with_no_method_is_cash_and_debits_1010(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->extendableLoan();

        app(LoanAdjustmentService::class)->extendLoan($loan, null, $this->admin, 'pay');

        $collection = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertSame('cash', $collection->method);
        $this->assertNull($collection->reference_number);
        $this->assertSame(180_000, $this->sumOn($this->collectionJournal($collection), '1010', 'debit'));
    }

    public function test_defer_collects_nothing_and_posts_nothing_new(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->extendableLoan();
        $before = AccountingJournal::count();

        app(LoanAdjustmentService::class)->extendLoan($loan, null, $this->admin, 'defer', 'gcash', 'REF-1');

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertSame($before, AccountingJournal::count());
    }

    public function test_a_collection_taken_by_gcash_still_cannot_be_voided(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->extendableLoan();
        app(LoanAdjustmentService::class)->extendLoan($loan, null, $this->admin, 'pay', 'gcash', 'REF-1');

        $collection = Repayment::where('loan_id', $loan->id)->sole();
        $extension = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole();
        $journals = AccountingJournal::count();

        $message = $this->patchJson("/api/repayments/{$collection->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertStatus(422)
            ->json('errors.repayment.0');

        $this->assertStringContainsString("when the loan was extended ({$extension->adjustment_number})", $message);
        $this->assertStringContainsString('collected the interest', $message);
        $this->assertStringContainsString('so it cannot be voided', $message);

        $this->assertSame('posted', $collection->fresh()->status);
        $this->assertSame($journals, AccountingJournal::count());
    }

    public function test_the_request_needs_a_reference_for_gcash_on_pay(): void
    {
        try {
            $this->validated(['interest_option' => 'pay', 'method' => 'gcash']);
            $this->fail('A GCash extension payment with no reference was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference_number', $e->errors());
        }

        $this->assertSame(
            ['interest_option' => 'pay', 'method' => 'gcash', 'reference_number' => 'GC-1001'],
            $this->validated(['interest_option' => 'pay', 'method' => 'gcash', 'reference_number' => 'GC-1001']),
        );
    }

    public function test_the_request_with_no_method_is_cash_and_needs_no_reference(): void
    {
        $this->assertSame(['interest_option' => 'pay', 'method' => 'cash'], $this->validated(['interest_option' => 'pay']));
    }

    public function test_the_request_ignores_the_method_and_reference_on_defer(): void
    {
        $this->assertSame(['interest_option' => 'defer'], $this->validated(['interest_option' => 'defer', 'method' => 'gcash']));
        $this->assertSame(['interest_option' => 'defer'], $this->validated(['interest_option' => 'defer', 'method' => 'bitcoin', 'reference_number' => 'X']));
    }

    public function test_the_request_refuses_an_unknown_method_and_a_long_reference_on_pay(): void
    {
        foreach ([
            'method' => ['interest_option' => 'pay', 'method' => 'auto_pay', 'reference_number' => 'R-1'],
            'reference_number' => ['interest_option' => 'pay', 'method' => 'maya', 'reference_number' => str_repeat('9', 101)],
        ] as $field => $input) {
            try {
                $this->validated($input);
                $this->fail("{$field} was accepted.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_the_extend_endpoint_refuses_gcash_with_no_reference_on_pay(): void
    {
        $loan = $this->extendableLoan();

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay', 'method' => 'gcash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_number');

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
    }

    public function test_the_extend_endpoint_accepts_gcash_with_no_reference_on_defer(): void
    {
        $loan = $this->extendableLoan();

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer', 'method' => 'gcash'])->assertOk();
    }

    private function extendableLoan(): Loan
    {
        $loan = $this->createReleasedLoan([
            'product' => [
                'interest_method' => 'upon_maturity',
                'term' => 1,
                'frequency' => 'monthly',
                'interest_rate' => 3.0,
            ],
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ]);
        $loan->amortizationSchedules()->update(['due_date' => now()->addWeek()->toDateString(), 'status' => 'pending']);

        return $loan->fresh();
    }

    /**
     * What ExtendLoanRequest passes on, run as the framework runs it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        $request = ExtendLoanRequest::create('/api/loans/1/extend', 'POST', $input);
        $request->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));
        $request->setUserResolver(fn () => $this->admin);
        $request->validateResolved();

        return $request->validated();
    }

    private function collectionJournal(Repayment $repayment): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $repayment->getMorphClass())
            ->where('postable_id', $repayment->getKey())
            ->where('source', 'loan_collection')
            ->sole();
    }

    private function sumOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }
}
