<?php

namespace Tests\Feature\Ledger\Deferral;

use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Services\Accounting\PostingRules;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A balance reduction on a loan holding deferral (Arvin, 2026-11-09): at the
 * reduction, the matching share is cleared, Dr 1210 / Cr 1180, by part. The
 * share is the deferral × the principal reduced ÷ the principal outstanding
 * before the reduction. Later recognition targets only what's left and still
 * ends at exactly zero. A reduction that completes the loan clears all of it;
 * an increase clears nothing.
 *
 * N is a ₱60,000.00 restructure holding ₱7,000.00 interest and ₱3,000.00
 * penalty, one ₱10,000.00 collection in: ₱5,833.33 and ₱2,500.00 left on
 * ₱50,000.00 of principal.
 */
class BalanceReductionClearingTest extends TestCase
{
    use DeferralScenarios, PostsJournals, SetupLendyPH;

    private Loan $new;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $source = $this->createReleasedLoan();
        $this->new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 700_000, 300_000, 0);
        $this->seedChartOfAccounts();

        $this->release($this->new, $source);
        $this->pay($this->new);
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($this->new->fresh()));
    }

    public function test_a_partial_reduction_clears_its_share_and_later_recognition_ends_at_zero(): void
    {
        // ₱10,000.00 of the ₱50,000.00 outstanding: a fifth of what is left.
        $journal = $this->applyBalanceAdjustment(-1_000_000);

        $this->assertSame(1_000_000, $this->sumOn($journal, '1180', 'debit'));
        $this->assertSame(1_000_000, $this->sumOn($journal, '1110', 'credit'));
        $this->assertSame(116_667, $this->described($journal, PostingRules::DEFERRED_INTEREST_CLEARED_LINE, 'debit'));
        $this->assertSame(50_000, $this->described($journal, PostingRules::DEFERRED_PENALTY_CLEARED_LINE, 'debit'));
        $this->assertSame(166_667, $this->sumOn($journal, '1210', 'debit'));
        $this->assertSame(166_667, $this->sumOn($journal, '1180', 'credit'));
        $this->assertSame(0, $this->sumOn($journal, '4010', 'credit'), 'a cleared share is never income');
        $this->assertSame(['interest' => 466_666, 'penalty' => 200_000], $this->poster()->unrecognisedDeferral($this->new->fresh()));
        $this->assertSame(666_666, $this->unearnedBalance());

        $this->payUntilCompleted($this->new);

        $this->assertEndsAtZero(116_667, 50_000);
    }

    /**
     * The apply endpoint alone posts the share (A2 on A0b): balanceAdjustment()
     * runs after the periods are restated, so the principal reduced is the
     * drop in unpaid principal, and the share is measured on what was unpaid
     * before it.
     */
    public function test_applying_a_partial_reduction_posts_its_share_measured_on_the_drop_in_unpaid_principal(): void
    {
        $before = $this->unpaidPrincipalOf($this->new);
        $this->assertSame(5_000_000, $before);

        $adjustment = $this->applyReduction($this->new, -1_000_000);

        $this->assertSame(1_000_000, $before - $this->unpaidPrincipalOf($this->new));
        $this->assertSame(1, AccountingJournal::where('source', 'adjustment')->count());
        $journal = $this->journalFor($adjustment, 'adjustment');
        $this->assertNotNull($journal);
        $this->assertSame(
            PostingRules::deferralShare(583_333, 250_000, 1_000_000, $before),
            [
                'interest' => $this->described($journal, PostingRules::DEFERRED_INTEREST_CLEARED_LINE, 'debit'),
                'penalty' => $this->described($journal, PostingRules::DEFERRED_PENALTY_CLEARED_LINE, 'debit'),
            ],
        );
        $this->assertSame(166_667, $this->sumOn($journal, '1210', 'debit'));
        $this->assertSame(166_667, $this->sumOn($journal, '1180', 'credit'));
        $this->assertSame(1_000_000, $this->sumOn($journal, '1180', 'debit'));
        $this->assertSame(1_000_000, $this->sumOn($journal, '1110', 'credit'));
        $this->assertSame(['interest' => 466_666, 'penalty' => 200_000], $this->poster()->unrecognisedDeferral($this->new->fresh()));
    }

    /** A second, completing reduction clears all that is left, as before. */
    public function test_a_completing_reduction_after_a_partial_one_clears_the_rest(): void
    {
        $this->applyBalanceAdjustment(-1_000_000);
        $this->pay($this->new);
        $left = $this->poster()->unrecognisedDeferral($this->new->fresh());
        $this->assertGreaterThan(0, $left['interest']);

        // Every open period has closed by the time it posts.
        $this->new->amortizationSchedules()->whereIn('status', ['pending', 'partial', 'overdue'])->update(['status' => 'paid']);
        $adjustment = LoanAdjustment::factory()->create([
            'loan_id' => $this->new->id,
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -30_000],
            'status' => 'applied',
            'applied_at' => now(),
            'adjusted_by' => $this->admin->id,
        ]);

        $journal = $this->poster()->balanceAdjustment($adjustment, -3_000_000, $this->admin->id);

        $this->assertSame($left['interest'] + $left['penalty'], $this->described($journal, PostingRules::DEFERRAL_CLEARED_LINE, 'debit'));
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($this->new->fresh()));
        $this->assertSame(0, $this->unearnedBalance());
        $this->assertEveryJournalBalances();
    }

    public function test_an_increase_clears_nothing(): void
    {
        $journal = $this->applyBalanceAdjustment(500_000);

        $this->assertSame(500_000, $this->sumOn($journal, '1110', 'debit'));
        $this->assertSame(500_000, $this->sumOn($journal, '1180', 'credit'));
        $this->assertSame(0, $this->sumOn($journal, '1210', 'debit'));
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($this->new->fresh()));

        $this->payUntilCompleted($this->new);

        $this->assertEndsAtZero(0, 0);
    }

    /**
     * A payment voided after a partial reduction reverses exactly what it
     * recognised; the clearing stands, and the rest still ends at zero.
     */
    public function test_a_void_after_a_partial_reduction_reverses_exactly(): void
    {
        $this->applyBalanceAdjustment(-1_000_000);
        $second = $this->pay($this->new);
        $recognised = $this->journalFor($second, 'loan_collection');
        $interest = $this->described($recognised, PostingRules::DEFERRED_INTEREST_LINE, 'credit');
        $penalty = $this->described($recognised, PostingRules::DEFERRED_PENALTY_LINE, 'credit');
        $this->assertGreaterThan(0, $interest);

        $this->patchJson("/api/repayments/{$second->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();

        $reversal = AccountingJournal::findOrFail($recognised->fresh()->reversed_by_journal_id);
        $this->assertSame($interest, $this->described($reversal, PostingRules::DEFERRED_INTEREST_LINE, 'debit'));
        $this->assertSame($penalty, $this->described($reversal, PostingRules::DEFERRED_PENALTY_LINE, 'debit'));
        $this->assertSame($interest + $penalty, $this->sumOn($reversal, '1210', 'credit'));
        $this->assertSame(['interest' => 466_666, 'penalty' => 200_000], $this->poster()->unrecognisedDeferral($this->new->fresh()));

        $this->payUntilCompleted($this->new);

        $this->assertEndsAtZero(116_667, 50_000);
    }

    /**
     * Request, approve and apply a balance adjustment by amount through the
     * real endpoints, so the periods are restated, then post it as A2 will.
     */
    private function applyBalanceAdjustment(int $signedCentavos): AccountingJournal
    {
        $id = $this->postJson("/api/loans/{$this->new->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $signedCentavos / 100],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        return $this->poster()->balanceAdjustment(LoanAdjustment::findOrFail($id), $signedCentavos, $this->admin->id);
    }

    /** Recognised plus cleared is exactly the deferral, by part, and nothing is left on 1210. */
    private function assertEndsAtZero(int $clearedInterest, int $clearedPenalty): void
    {
        $recognised = $this->recognisedOn($this->new);

        $this->assertSame(700_000, $recognised['interest'] + $clearedInterest);
        $this->assertSame(300_000, $recognised['penalty'] + $clearedPenalty);
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($this->new->fresh()));
        $this->assertSame(0, $this->unearnedBalance());
        $this->assertEveryJournalBalances();
    }
}
