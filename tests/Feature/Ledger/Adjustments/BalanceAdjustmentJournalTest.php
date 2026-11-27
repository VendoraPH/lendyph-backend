<?php

namespace Tests\Feature\Ledger\Adjustments;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\PostingRules;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Applying a balance adjustment posts it to the books (Phase 2, decision 7):
 * a reduction is Dr 1180 Loan Adjustments Clearing / Cr 1110 Loans
 * Receivable, an increase the mirror, keyed on the LoanAdjustment with source
 * `adjustment`, in the loan's branch, dated the day it was applied, by the
 * user who applied it. A reduction that completes a restructured loan also
 * moves the deferral it still holds, Dr 1210 / Cr 1180.
 *
 * The journal is written inside the apply's own transaction, so an apply that
 * is refused or collides posts nothing. Penalty waivers and term extensions
 * post nothing: interest and penalty are on a cash basis (decision 1).
 *
 * The loan from createReleasedLoan(): ₱60,000 over 6 monthly periods of
 * ₱10,000 principal and ₱1,800 interest. Loans are released before the chart
 * is seeded, so the only journals are the ones under test.
 */
class BalanceAdjustmentJournalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_reduction_posts_dr_1180_cr_1110_for_the_adjustment_amount(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();

        $adjustment = $this->applyByAmount($loan, -2500.37);

        $journal = $this->adjustmentJournal($adjustment);
        $this->assertSame('posted', $journal->status);
        $this->assertSame($loan->branch_id, $journal->branch_id);
        $this->assertSame($adjustment->adjustment_number, $journal->reference);
        $this->assertSame($adjustment->applied_at->toDateString(), $journal->date->toDateString());
        $this->assertSame(250_037, $this->sumOn($journal, '1180', 'debit'));
        $this->assertSame(250_037, $this->sumOn($journal, '1110', 'credit'));
        $this->assertSame(250_037, $journal->total_debit);
        $this->assertSame(250_037, $journal->total_credit);
    }

    public function test_an_increase_posts_dr_1110_cr_1180(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();

        $adjustment = $this->applyByAmount($loan, 1000.55);

        $journal = $this->adjustmentJournal($adjustment);
        $this->assertSame(100_055, $this->sumOn($journal, '1110', 'debit'));
        $this->assertSame(100_055, $this->sumOn($journal, '1180', 'credit'));
        $this->assertSame(100_055, $journal->total_debit);
    }

    public function test_a_reduction_requested_as_a_target_posts_the_amount_the_server_worked_out(): void
    {
        $loan = $this->createReleasedLoan();
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);
        $this->seedChartOfAccounts();

        // ₱56,800 of principal is unpaid after the payment, so a target of
        // ₱50,000 is a reduction of ₱6,800.
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['target_principal_balance' => 50000],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $journal = $this->adjustmentJournal(LoanAdjustment::findOrFail($id));
        $this->assertSame(680_000, $this->sumOn($journal, '1180', 'debit'));
        $this->assertSame(680_000, $this->sumOn($journal, '1110', 'credit'));
    }

    public function test_the_journal_is_posted_by_the_user_who_applied_it(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $id = $this->requestAndApprove($loan, -1000);

        $applier = $this->userWithRole('admin');
        $this->actingAs($applier);
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $this->assertSame($applier->id, $this->adjustmentJournal(LoanAdjustment::findOrFail($id))->created_by);
    }

    public function test_a_reduction_that_completes_a_restructured_loan_also_moves_its_remaining_deferral(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->createReleasedLoan();
        // A1 stores this split at release: ₱50,000 carried and ₱10,000 of
        // interest and penalty capitalised, deferred on 1210.
        $new->update([
            'source_loan_id' => $source->id,
            'restructure_carried_principal' => '50000.00',
            'restructure_capitalised_interest' => '7000.00',
            'restructure_capitalised_penalty' => '3000.00',
            'restructure_written_off_principal' => '0.00',
        ]);
        $this->seedChartOfAccounts();
        $this->postRestructureRelease($new->fresh(), $source);

        // Every period's interest is paid, so a reduction of all the unpaid
        // principal leaves nothing open and completes the loan.
        AmortizationSchedule::where('loan_id', $new->id)->update(['interest_paid' => 1800, 'status' => 'partial']);

        $adjustment = $this->applyByAmount($new, -60000);

        $this->assertSame('completed', $new->fresh()->status);
        $journal = $this->adjustmentJournal($adjustment);
        $this->assertSame(6_000_000, $this->sumOn($journal, '1110', 'credit'));
        $this->assertSame(1_000_000, $this->sumOn($journal, '1210', 'debit'));
        $this->assertSame(1_000_000, $this->described($journal, PostingRules::DEFERRAL_CLEARED_LINE, 'debit'));
        $this->assertSame(6_000_000, $this->sumOn($journal, '1180', 'debit'));
        $this->assertSame(1_000_000, $this->sumOn($journal, '1180', 'credit'));
        $this->assertSame(['interest' => 0, 'penalty' => 0], app(AutomaticPoster::class)->unrecognisedDeferral($new->fresh()));
    }

    public function test_an_adjustment_on_a_loan_with_nothing_unpaid_posts_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        AmortizationSchedule::where('loan_id', $loan->id)->update(['principal_paid' => 10000, 'interest_paid' => 1800, 'status' => 'paid']);
        $this->seedChartOfAccounts();

        $adjustment = $this->applyByAmount($loan, 1000);

        $this->assertSame('applied', $adjustment->status);
        $this->assertNull($this->adjustmentJournalOrNull($adjustment));
    }

    public function test_with_no_chart_the_adjustment_applies_and_nothing_posts(): void
    {
        $loan = $this->createReleasedLoan();

        $adjustment = $this->applyByAmount($loan, -2500);

        $this->assertSame('applied', $adjustment->status);
        $this->assertSame(0, AccountingJournal::count());
    }

    public function test_a_reduction_over_the_unpaid_principal_is_refused_and_posts_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $id = $this->requestAndApprove($loan, -59000);
        // ₱1,800 of interest and ₱3,200 of principal: ₱56,800 is left unpaid.
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $this->patchJson("/api/loan-adjustments/{$id}/apply")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_values.adjustment_amount');

        $this->assertSame('approved', LoanAdjustment::findOrFail($id)->status);
        $this->assertSame(0, AccountingJournal::where('source', 'adjustment')->count());
    }

    public function test_a_second_apply_answers_409_and_posts_nothing_again(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $id = $this->requestAndApprove($loan, -1000);
        $secondRequestsCopy = LoanAdjustment::findOrFail($id);

        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        try {
            app(LoanAdjustmentService::class)->applyAdjustment($secondRequestsCopy, $this->admin->id);
            $this->fail('The second apply was not refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
        }

        $this->assertSame(1, AccountingJournal::where('source', 'adjustment')->count());
    }

    public function test_a_target_request_refused_with_409_at_apply_posts_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['target_principal_balance' => 50000],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        app(RepaymentService::class)->processRepayment($loan->fresh(), 2000, now()->toDateString(), $this->admin);

        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertStatus(409);

        $this->assertSame(0, AccountingJournal::where('source', 'adjustment')->count());
    }

    public function test_penalty_waivers_and_term_extensions_post_nothing(): void
    {
        $waived = $this->createReleasedLoan();
        $extended = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        AmortizationSchedule::where('loan_id', $waived->id)->where('period_number', 1)->update(['penalty_amount' => 250]);
        $before = AccountingJournal::count();

        $waiver = $this->apply($waived, 'penalty_waiver', ['waive_all' => true]);
        $extension = $this->apply($extended, 'term_extension', ['additional_terms' => 2]);

        $this->assertSame('applied', $waiver->status);
        $this->assertSame('applied', $extension->status);
        $this->assertSame($before, AccountingJournal::count());
    }

    /**
     * A ₱0.00 adjustment is now refused when it is requested
     * (ZeroBalanceAdjustmentRequestTest). One requested before that, by amount
     * or by a target equal to the unpaid principal, still applies and posts
     * nothing; it is never left stuck as approved.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function zeroAdjustmentsRequestedBefore(): array
    {
        return [
            'by amount' => [['adjustment_amount' => 0]],
            'by target' => [['target_principal_balance' => 60000, 'adjustment_amount' => 0, 'outstanding_principal_at_request' => 60000]],
        ];
    }

    #[DataProvider('zeroAdjustmentsRequestedBefore')]
    public function test_a_zero_adjustment_requested_before_the_request_refused_it_applies_and_posts_nothing(array $newValues): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -1000],
        ])->assertCreated()->json('data.id');
        LoanAdjustment::whereKey($id)->update(['new_values' => $newValues]);
        $before = $this->periodFigures($loan);

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $adjustment = LoanAdjustment::findOrFail($id);
        $this->assertSame('applied', $adjustment->status);
        $this->assertSame($before, $this->periodFigures($loan));
        $this->assertNull($this->adjustmentJournalOrNull($adjustment));
    }

    public function test_a_posting_that_fails_after_the_restate_rolls_every_period_write_back(): void
    {
        $loan = $this->createReleasedLoan();
        // Every period's interest is paid, so the reduction below would also
        // mark each period paid and complete the loan.
        AmortizationSchedule::where('loan_id', $loan->id)->update(['interest_paid' => 1800, 'status' => 'partial']);
        $this->seedChartOfAccounts();
        $id = $this->requestAndApprove($loan, -60000);
        // 1180 unmapped: the periods are restated, then the poster refuses
        // the posting (an unmapped role fails closed).
        AccountingAccountMapping::where('role', 'loan_adjustments')->delete();
        $periods = $this->periodFigures($loan);

        $this->patchJson("/api/loan-adjustments/{$id}/apply")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accounting');

        $this->assertSame($periods, $this->periodFigures($loan));
        $this->assertSame('released', $loan->fresh()->status);
        $adjustment = LoanAdjustment::findOrFail($id);
        $this->assertSame('approved', $adjustment->status);
        $this->assertNull($adjustment->applied_at);
        $this->assertSame(0, AccountingJournal::where('source', 'adjustment')->count());
    }

    public function test_with_no_approver_and_no_user_the_posting_is_refused_never_made_as_user_0(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $id = $this->requestAndApprove($loan, -1000);
        LoanAdjustment::whereKey($id)->update(['approved_by' => null]);
        $periods = $this->periodFigures($loan);

        try {
            app(LoanAdjustmentService::class)->applyAdjustment(LoanAdjustment::findOrFail($id));
            $this->fail('A balance adjustment posted with no user to post it as.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('no user to post it as', $e->getMessage());
        }

        $this->assertSame($periods, $this->periodFigures($loan));
        $this->assertSame('approved', LoanAdjustment::findOrFail($id)->status);
        $this->assertSame(0, AccountingJournal::where('source', 'adjustment')->count());
    }

    public function test_with_no_approver_no_user_and_no_chart_the_adjustment_still_applies(): void
    {
        $loan = $this->createReleasedLoan();
        $id = $this->requestAndApprove($loan, -1000);
        LoanAdjustment::whereKey($id)->update(['approved_by' => null]);

        $adjustment = app(LoanAdjustmentService::class)->applyAdjustment(LoanAdjustment::findOrFail($id));

        $this->assertSame('applied', $adjustment->status);
        $this->assertSame(0, AccountingJournal::count());
    }

    /**
     * Each period's principal due, remaining balance and status, in period order.
     *
     * @return list<array<string, mixed>>
     */
    private function periodFigures(Loan $loan): array
    {
        return AmortizationSchedule::where('loan_id', $loan->id)
            ->orderBy('period_number')
            ->get(['principal_due', 'remaining_balance', 'status'])
            ->map(fn (AmortizationSchedule $s): array => $s->only(['principal_due', 'remaining_balance', 'status']))
            ->all();
    }

    private function applyByAmount(Loan $loan, float $amount): LoanAdjustment
    {
        return $this->apply($loan, 'balance_adjustment', ['adjustment_amount' => $amount]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function apply(Loan $loan, string $type, array $newValues): LoanAdjustment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => $type,
            'new_values' => $newValues,
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        return LoanAdjustment::findOrFail($id);
    }

    private function requestAndApprove(Loan $loan, float $amount): int
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $amount],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();

        return $id;
    }

    /** J1 for `$new`, inside a transaction as A1's release posts it. */
    private function postRestructureRelease(Loan $new, Loan $source): void
    {
        DB::transaction(
            fn () => app(AutomaticPoster::class)->restructureRelease($new, $source, $this->admin->id, 'cash'),
        );
    }

    private function adjustmentJournalOrNull(LoanAdjustment $adjustment): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $adjustment->getMorphClass())
            ->where('postable_id', $adjustment->getKey())
            ->where('source', 'adjustment')
            ->first();
    }

    private function adjustmentJournal(LoanAdjustment $adjustment): AccountingJournal
    {
        $journal = $this->adjustmentJournalOrNull($adjustment);
        $this->assertNotNull($journal, "{$adjustment->adjustment_number} posted no adjustment journal.");

        return $journal;
    }

    private function sumOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    private function described(AccountingJournal $journal, string $description, string $side): int
    {
        return (int) $journal->lines()->where('description', $description)->sum($side);
    }
}
