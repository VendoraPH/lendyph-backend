<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\CreditScoring\PaymentHistory;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Recovery payments on defaulted loans (round Y): a defaulted loan takes
 * payments like a released or ongoing one, stays Defaulted until it is paid
 * in full, charges no new penalty while defaulted, and a void puts back
 * exactly the status the loan had.
 *
 * The default loan (BuildsReleasedLoans): ₱60,000 over 6 monthly periods at
 * 3% straight, ₱10,000 principal and ₱1,800 interest a period, 2% penalty,
 * 3 days grace. Released on 2026-01-10, so period 1 falls due on 2026-02-10.
 * On 2026-02-20 period 1 is charged one month of penalty (2% of ₱10,000 =
 * ₱200.00), then the loan defaults. By 2026-05-20 four periods are late; the
 * monthly rule would have charged ₱1,400.00 by then, but a defaulted loan's
 * penalty stays where it was at the default.
 */
class DefaultedLoanRecoveryPaymentTest extends TestCase
{
    use BuildsReleasedLoans, PostsJournals, SetupLendyPH;

    private const RECOVERY_DAY = '2026-05-20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_defaulted_loan_takes_a_payment_and_its_preview(): void
    {
        $loan = $this->defaultedLoan();

        $preview = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => 1000.37,
            'payment_date' => self::RECOVERY_DAY,
        ])->assertOk()->json('data');

        $this->assertEqualsWithDelta(1000.37, (float) $preview['total_paid'], 0.001);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => 1000.37,
            'payment_date' => self::RECOVERY_DAY,
        ])->assertCreated();

        $this->assertSame(1, $loan->repayments()->where('status', 'posted')->count());
    }

    public function test_the_summary_gives_presets_payoff_and_the_amount_due_now(): void
    {
        $loan = $this->defaultedLoan();

        $summary = $this->summaryOf($loan);

        // One preset per open period, and the payoff: everything owed, the
        // penalty charged before the default included, and nothing more.
        $this->assertCount(6, $summary['advance_presets']);
        $this->assertEqualsWithDelta(71000.00, (float) $summary['payoff_amount'], 0.001);
        $this->assertEqualsWithDelta(71000.00, (float) $summary['total_outstanding'], 0.001);

        // Periods 1 to 4 are due by today: 4 × ₱11,800.00 plus the ₱200.00 penalty.
        $show = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
        $this->assertNotNull($show['amount_due_now']);
        $this->assertEqualsWithDelta(47400.00, (float) $show['amount_due_now']['amount'], 0.001);

        $row = collect($this->getJson("/api/loans?borrower_id={$loan->borrower_id}")->assertOk()->json('data'))->firstWhere('id', $loan->id);
        $this->assertNotNull($row['amount_due_now']);
    }

    public function test_a_part_payment_leaves_it_defaulted_and_the_payoff_completes_it(): void
    {
        $loan = $this->defaultedLoan();

        $this->payLoan($loan, 1000.37, self::RECOVERY_DAY);
        $this->assertSame('defaulted', $loan->fresh()->status);

        $payoff = (float) $this->summaryOf($loan)['payoff_amount'];
        $this->assertEqualsWithDelta(71000.00 - 1000.37, $payoff, 0.001);

        $this->payLoan($loan, $payoff, self::RECOVERY_DAY);
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_no_catch_up_penalty_is_charged_on_a_defaulted_loan(): void
    {
        $loan = $this->defaultedLoan();
        $penaltyBefore = $this->penaltyState($loan);
        $ledgerPenaltyLinesBefore = $this->ledgerPenaltyLines($loan);

        $this->assertSame([1 => ['200.00', 1], 2 => ['0.00', 0], 3 => ['0.00', 0], 4 => ['0.00', 0], 5 => ['0.00', 0], 6 => ['0.00', 0]], $penaltyBefore, 'setup: only the ₱200.00 charged before the default');
        $this->assertSame(1, $ledgerPenaltyLinesBefore, 'setup: one penalty line on the Ledger');

        $preview = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => 1000.37,
            'payment_date' => self::RECOVERY_DAY,
        ])->assertOk()->json('data');

        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => 1000.37,
            'payment_date' => self::RECOVERY_DAY,
        ])->assertCreated()->json('data.id');
        $repayment = Repayment::with('allocations')->findOrFail($id);

        // The penalty charged before the default is paid first, then interest.
        $this->assertEqualsWithDelta(200.00, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(800.37, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->principal_applied, 0.001);

        // What the preview quoted is what was recorded.
        $this->assertEqualsWithDelta((float) $preview['total_penalty'], (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta((float) $preview['total_interest'], (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta((float) $preview['total_principal'], (float) $repayment->principal_applied, 0.001);
        $this->assertSame(
            collect($preview['allocations'])->map(fn (array $a) => [$a['period'], (float) $a['penalty'], (float) $a['interest'], (float) $a['principal']])->all(),
            $repayment->allocations->sortBy('period_number')->values()->map(fn ($a) => [$a->period_number, (float) $a->penalty, (float) $a->interest, (float) $a->principal])->all(),
        );
        $this->assertEqualsWithDelta(71000.00, (float) $preview['total_outstanding_before'], 0.001);

        // No new charge: not on the periods, not on the Ledger, not in the summary.
        $this->assertSame($penaltyBefore, $this->penaltyState($loan));
        $this->assertSame($ledgerPenaltyLinesBefore, $this->ledgerPenaltyLines($loan));
        $this->assertEqualsWithDelta(0.00, (float) $this->summaryOf($loan)['outstanding_penalty'], 0.001);
    }

    public function test_voiding_the_payment_that_completed_a_defaulted_loan_restores_defaulted(): void
    {
        $loan = $this->defaultedLoan();
        $penaltyBefore = $this->penaltyState($loan);
        $payoff = (float) $this->summaryOf($loan)['payoff_amount'];
        $repayment = $this->payLoan($loan, $payoff, self::RECOVERY_DAY);
        $this->assertSame('completed', $loan->fresh()->status, 'setup: the recovery completed the loan');
        $this->assertSame('defaulted', $repayment->fresh()->loan_status_before);

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        $this->assertSame('defaulted', $loan->fresh()->status);
        // Reopened, still frozen: the void charges no missed months either.
        $this->assertSame($penaltyBefore, $this->penaltyState($loan));
        $this->assertEqualsWithDelta($payoff, (float) $this->summaryOf($loan)['payoff_amount'], 0.001);
    }

    public function test_voiding_an_earlier_payment_on_a_loan_completed_while_defaulted_restores_defaulted(): void
    {
        $loan = $this->defaultedLoan(beforeDefault: fn (Loan $loan) => $this->payLoan($loan, 500.00, '2026-02-01'));
        $first = $loan->repayments()->firstOrFail();
        $this->assertSame('released', $first->loan_status_before);
        $this->payLoan($loan, (float) $this->summaryOf($loan)['payoff_amount'], self::RECOVERY_DAY);
        $this->assertSame('completed', $loan->fresh()->status, 'setup: the recovery completed the loan');

        $this->patchJson("/api/repayments/{$first->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        $this->assertSame('defaulted', $loan->fresh()->status);
    }

    public function test_voiding_a_part_payment_on_a_defaulted_loan_leaves_it_defaulted(): void
    {
        $loan = $this->defaultedLoan();
        $penaltyBefore = $this->penaltyState($loan);
        $repayment = $this->payLoan($loan, 1000.37, self::RECOVERY_DAY);

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        $this->assertSame('defaulted', $loan->fresh()->status);
        $this->assertSame($penaltyBefore, $this->penaltyState($loan));
    }

    public function test_voiding_the_payment_that_completed_an_ongoing_loan_behaves_as_before(): void
    {
        $this->travelTo(Carbon::parse('2026-01-10 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-01-10');
        $first = $this->payLoan($loan, 1000.00);
        $this->assertSame('ongoing', $loan->fresh()->status);
        $last = $this->payLoan($loan, (float) $this->summaryOf($loan)['payoff_amount']);
        $this->assertSame('completed', $loan->fresh()->status);
        $this->assertSame('ongoing', $last->fresh()->loan_status_before);

        $this->patchJson("/api/repayments/{$last->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();
        $this->assertSame('ongoing', $loan->fresh()->status);

        $this->patchJson("/api/repayments/{$first->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();
        $this->assertSame('ongoing', $loan->fresh()->status);

        // A loan paid off in one payment goes back to released.
        $single = $this->releasedLoanFor(startDate: '2026-01-10');
        $only = $this->payLoan($single, (float) $this->summaryOf($single)['payoff_amount']);
        $this->assertSame('released', $only->fresh()->loan_status_before);
        $this->patchJson("/api/repayments/{$only->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();
        $this->assertSame('released', $single->fresh()->status);
    }

    public function test_status_collectible_lists_released_ongoing_and_defaulted_loans(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $product = LoanProduct::factory()->create();
        $loans = collect(['released', 'ongoing', 'defaulted', 'completed', 'draft', 'restructured'])
            ->mapWithKeys(fn (string $status) => [$status => Loan::factory()->create([
                'borrower_id' => $borrower->id,
                'loan_product_id' => $product->id,
                'branch_id' => $this->branch->id,
                'created_by' => $this->admin->id,
                'status' => $status,
            ])->id]);

        $collectible = $this->getJson("/api/loans?status=collectible&borrower_id={$borrower->id}&per_page=100")->assertOk();
        $this->assertEqualsCanonicalizing(
            [$loans['released'], $loans['ongoing'], $loans['defaulted']],
            array_column($collectible->json('data'), 'id'),
        );

        // `active` is unchanged: released and ongoing only.
        $active = $this->getJson("/api/loans?status=active&borrower_id={$borrower->id}&per_page=100")->assertOk();
        $this->assertEqualsCanonicalizing([$loans['released'], $loans['ongoing']], array_column($active->json('data'), 'id'));
    }

    public function test_credit_scoring_counts_a_loan_recovered_from_default_as_ended_badly(): void
    {
        $recovered = $this->defaultedLoan();
        $this->payLoan($recovered, (float) $this->summaryOf($recovered)['payoff_amount'], self::RECOVERY_DAY);
        $this->assertSame('completed', $recovered->fresh()->status, 'setup: the recovery completed the loan');

        $history = PaymentHistory::from(MemberCreditData::load($recovered->borrower_id));

        // Ended badly, but not defaulted now (CreditScoring/Engine/RecoveredFromDefaultTest).
        $this->assertSame(1, $history->recoveredFromDefaultLoans);
        $this->assertSame(0, $history->defaultedLoans);

        // A loan completed without a default counts as none.
        $this->travelTo(Carbon::parse('2026-01-10 09:00'));
        $clean = $this->releasedLoanFor(startDate: '2026-01-10');
        $this->payLoan($clean, (float) $this->summaryOf($clean)['payoff_amount']);
        $this->assertSame('completed', $clean->fresh()->status);

        $cleanHistory = PaymentHistory::from(MemberCreditData::load($clean->borrower_id));
        $this->assertSame(0, $cleanHistory->defaultedLoans);
        $this->assertSame(0, $cleanHistory->recoveredFromDefaultLoans);
    }

    public function test_the_receipt_and_the_collection_journal_are_the_same_as_on_an_ongoing_loan(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->defaultedLoan();

        $data = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => 1000.37,
            'payment_date' => self::RECOVERY_DAY,
        ])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^RCP-\d{6,}$/', (string) $data['receipt_number']);

        $journal = AccountingJournal::query()
            ->where('postable_type', (new Repayment)->getMorphClass())
            ->where('postable_id', $data['id'])
            ->where('source', 'loan_collection')
            ->firstOrFail();
        // Accounting lines are whole centavos.
        $this->assertSame([100037, 100037], [(int) $journal->lines()->sum('debit'), (int) $journal->lines()->sum('credit')]);
    }

    /**
     * The default loan, released on 2026-01-10, charged one month of penalty
     * on period 1 on 2026-02-20, then defaulted; the clock left on the
     * recovery day, 2026-05-20.
     */
    private function defaultedLoan(?\Closure $beforeDefault = null): Loan
    {
        $this->travelTo(Carbon::parse('2026-01-10 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-01-10');

        if ($beforeDefault !== null) {
            $this->travelTo(Carbon::parse('2026-02-01 09:00'));
            $beforeDefault($loan);
        }

        $this->travelTo(Carbon::parse('2026-02-20 09:00'));
        app(RepaymentService::class)->applyPenalties($loan->fresh(), Carbon::today());
        $loan->fresh()->update(['status' => 'defaulted']);

        $this->travelTo(Carbon::parse(self::RECOVERY_DAY.' 09:00'));

        return $loan->fresh();
    }

    /** @return array<int, array{0: string, 1: int}> period => [penalty_amount, penalty_months] */
    private function penaltyState(Loan $loan): array
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')->get()
            ->mapWithKeys(fn (AmortizationSchedule $s) => [$s->period_number => [number_format((float) $s->penalty_amount, 2, '.', ''), (int) $s->penalty_months]])
            ->all();
    }

    private function ledgerPenaltyLines(Loan $loan): int
    {
        return collect($this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data.rows'))
            ->where('kind', 'penalty_charge')
            ->count();
    }
}
