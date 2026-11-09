<?php

namespace Tests\Feature\Ledger;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The posting engine's Phase 2 entry points, wired to real rows: restructure
 * (J1 on the new loan, J2 on the source), deferral recognition on the new
 * loan's collections, balance adjustments, share capital entries, GCash, and
 * the allowance. The rules are unit-tested in AccountingPostingRulesTest;
 * this is what only exists once they meet the database: the document each
 * journal is keyed on, its branch and date, the allowance balance J2 reads,
 * the deferral read back from earlier journals, and "no chart, no books".
 *
 * Loans are released before the chart is seeded, so the ordinary release
 * path posts nothing and each test posts only what it is about.
 */
class PostingRulesNewTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function poster(): AutomaticPoster
    {
        return app(AutomaticPoster::class);
    }

    /** Pesos as the decimal string a `decimal:2` column stores. */
    private function pesos(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    /** Make `$new` a restructure of `$source` with the split A1 stores at release. */
    private function restructureOf(Loan $new, Loan $source, int $carried, int $interest, int $penalty, int $writtenOff): Loan
    {
        $new->update([
            'source_loan_id' => $source->id,
            'restructure_carried_principal' => $this->pesos($carried),
            'restructure_capitalised_interest' => $this->pesos($interest),
            'restructure_capitalised_penalty' => $this->pesos($penalty),
            'restructure_written_off_principal' => $this->pesos($writtenOff),
        ]);

        return $new->fresh();
    }

    private function journalFor(Model $postable, string $source): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $postable->getMorphClass())
            ->where('postable_id', $postable->getKey())
            ->where('source', $source)
            ->first();
    }

    private function sumOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    private function described(AccountingJournal $journal, string $description, string $side): int
    {
        return (int) $journal->lines()->where('description', $description)->sum($side);
    }

    private function pay(Loan $loan, int $pesos = 11800): Repayment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $pesos,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }

    // ── Restructure: J1 on the new loan ──

    public function test_a_restructure_release_carries_principal_and_defers_the_capitalised_part(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 700_000, 300_000, 0);
        $deductions = $this->centavos($new->total_deductions);
        $this->seedChartOfAccounts();

        $result = $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');

        $this->assertNull($result['write_off']);
        $release = $result['release'];
        $this->assertSame($release->id, $this->journalFor($new, 'loan_release')?->id);
        $this->assertSame('posted', $release->status);
        $this->assertSame($new->branch_id, $release->branch_id);
        $this->assertSame(6_000_000, $this->sumOn($release, '1110', 'debit'));
        $this->assertSame(5_000_000, $this->sumOn($release, '1110', 'credit'));
        $this->assertSame(1_000_000, $this->sumOn($release, '1210', 'credit'));
        $this->assertSame($deductions, $this->sumOn($release, '1010', 'debit'));
        $this->assertSame(0, $this->sumOn($release, '1010', 'credit'), 'nothing is paid out on a restructure');
        $this->assertSame($release->total_debit, $release->total_credit);
        $this->assertSame(6_000_000 + $deductions, $release->total_debit);
    }

    public function test_a_restructure_collects_its_deductions_through_the_method_given(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 6_000_000, 0, 0, 0);
        $this->seedChartOfAccounts();

        $release = $this->poster()->restructureRelease($new, $source, $this->admin->id, 'gcash')['release'];

        $this->assertSame($this->centavos($new->total_deductions), $this->sumOn($release, '1020', 'debit'));
        $this->assertSame(0, $this->sumOn($release, '1210', 'credit'));
    }

    public function test_the_restructure_posting_can_be_built_without_writing(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 700_000, 300_000, 0);
        $this->seedChartOfAccounts();

        $posting = $this->poster()->restructureReleasePosting($new, AccountMap::resolve(), 'cash');

        $this->assertSame('loan_release', $posting['source']);
        $this->assertSame(0, AccountingJournal::count());
    }

    public function test_a_restructure_without_its_stored_split_is_refused(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->createReleasedLoan();
        $new->update(['source_loan_id' => $source->id]);
        $this->seedChartOfAccounts();

        $this->expectException(CannotPostToTheBooksException::class);

        $this->poster()->restructureRelease($new->fresh(), $source, $this->admin->id, 'cash');
    }

    /** J2 would land on a loan the new one never came from. */
    public function test_a_restructure_posted_against_a_loan_that_is_not_its_source_is_refused(): void
    {
        $source = $this->createReleasedLoan();
        $other = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 6_000_000, 0, 0, 500_000);
        $this->seedChartOfAccounts();

        try {
            $this->poster()->restructureRelease($new, $other, $this->admin->id, 'cash');
            $this->fail('A restructure posted against the wrong source was accepted.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('is not a restructure of', $e->getMessage());
        }

        $this->assertSame(0, AccountingJournal::count());
    }

    /** WP > 0 only when nothing is capitalised (PostingRules::restructureSplit()). */
    public function test_a_stored_split_that_both_writes_off_and_capitalises_is_refused(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 900_000, 100_000, 200_000);
        $this->seedChartOfAccounts();

        try {
            $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');
            $this->fail('A split that both writes off and capitalises was accepted.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('never both', $e->getMessage());
        }

        $this->assertSame(0, AccountingJournal::count());
    }

    // ── Restructure: J2 on the source ──

    public function test_a_restructure_write_off_takes_the_allowance_then_expense(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 6_000_000, 0, 0, 500_000);
        $this->seedChartOfAccounts();
        // ₱2,000.00 already provided for.
        $this->postSimpleJournal('5140', '1200', 200_000);

        $result = $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');

        $writeOff = $result['write_off'];
        $this->assertSame($writeOff->id, $this->journalFor($source, 'credit_loss')?->id);
        $this->assertSame($source->branch_id, $writeOff->branch_id);
        $this->assertSame($result['release']->date->toDateString(), $writeOff->date->toDateString());
        $this->assertSame(200_000, $this->sumOn($writeOff, '1200', 'debit'));
        $this->assertSame(300_000, $this->sumOn($writeOff, '5140', 'debit'));
        $this->assertSame(500_000, $this->sumOn($writeOff, '1110', 'credit'));
        $this->assertSame(0, $this->sumOn($writeOff, '1210', 'debit'));

        // J1 carried the whole principal: no deferral.
        $this->assertSame(6_000_000, $this->sumOn($result['release'], '1110', 'credit'));
        $this->assertSame(0, $this->sumOn($result['release'], '1210', 'credit'));
    }

    /** S was itself a restructure: its own unrecognised deferral absorbs the write-off first. */
    public function test_a_restructure_of_a_restructure_writes_off_against_its_own_deferral_first(): void
    {
        $first = $this->createReleasedLoan();
        $source = $this->restructureOf($this->createReleasedLoan(), $first, 5_000_000, 700_000, 300_000, 0);
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 6_000_000, 0, 0, 900_000);
        $this->seedChartOfAccounts();

        $this->poster()->restructureRelease($source, $first, $this->admin->id, 'cash');
        $this->pay($source);
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($source->fresh()));

        $writeOff = $this->poster()->restructureRelease($new, $source->fresh(), $this->admin->id, 'cash')['write_off'];

        $this->assertSame(833_333, $this->sumOn($writeOff, '1210', 'debit'));
        $this->assertSame(0, $this->sumOn($writeOff, '1200', 'debit'));
        $this->assertSame(66_667, $this->sumOn($writeOff, '5140', 'debit'));
        $this->assertSame(900_000, $this->sumOn($writeOff, '1110', 'credit'));
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($source->fresh()));
    }

    // ── Deferral recognised on the new loan's collections ──

    public function test_the_deferral_is_recognised_in_proportion_to_principal_collected_and_finishes_at_zero(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 700_000, 300_000, 0);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');

        $steps = [];

        for ($k = 1; $k <= 6; $k++) {
            $repayment = $this->pay($new);
            $this->assertSame(1_000_000, $this->centavos($repayment->principal_applied));
            $journal = $this->journalFor($repayment, 'loan_collection');
            $steps[] = [
                $this->described($journal, PostingRules::DEFERRAL_RECOGNISED_LINE, 'debit'),
                $this->described($journal, PostingRules::DEFERRED_INTEREST_LINE, 'credit'),
                $this->described($journal, PostingRules::DEFERRED_PENALTY_LINE, 'credit'),
            ];
            $this->assertSame($journal->total_debit, $journal->total_credit);
        }

        $this->assertSame([
            [166_667, 116_667, 50_000],
            [166_666, 116_666, 50_000],
            [166_667, 116_667, 50_000],
            [166_667, 116_667, 50_000],
            [166_666, 116_666, 50_000],
            [166_667, 116_667, 50_000],
        ], $steps);
        $this->assertSame('completed', $new->fresh()->status);
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($new->fresh()));
    }

    public function test_voiding_a_collection_reverses_its_recognition_and_the_next_one_catches_up(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 700_000, 300_000, 0);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');

        $this->pay($new);
        $second = $this->pay($new);
        $this->patchJson("/api/repayments/{$second->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();

        $reversal = AccountingJournal::findOrFail($this->journalFor($second, 'loan_collection')->reversed_by_journal_id);
        $this->assertSame(166_666, $this->sumOn($reversal, '1210', 'credit'));
        $this->assertSame(116_666, $this->described($reversal, PostingRules::DEFERRED_INTEREST_LINE, 'debit'));
        $this->assertSame(50_000, $this->described($reversal, PostingRules::DEFERRED_PENALTY_LINE, 'debit'));
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($new->fresh()));

        $third = $this->journalFor($this->pay($new), 'loan_collection');
        $this->assertSame(116_666, $this->described($third, PostingRules::DEFERRED_INTEREST_LINE, 'credit'));
        $this->assertSame(50_000, $this->described($third, PostingRules::DEFERRED_PENALTY_LINE, 'credit'));
        $this->assertSame(['interest' => 466_667, 'penalty' => 200_000], $this->poster()->unrecognisedDeferral($new->fresh()));
    }

    public function test_a_collection_on_an_ordinary_loan_recognises_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();

        $journal = $this->journalFor($this->pay($loan), 'loan_collection');

        $this->assertSame(0, $this->sumOn($journal, '1210', 'debit'));
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($loan));
    }

    // ── Balance adjustments ──

    private function balanceAdjustmentOn(Loan $loan, int $signedCentavos): LoanAdjustment
    {
        return LoanAdjustment::factory()->create([
            'loan_id' => $loan->id,
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $signedCentavos / 100],
            'status' => 'applied',
            'applied_at' => now(),
            'adjusted_by' => $this->admin->id,
        ]);
    }

    public function test_a_balance_reduction_posts_through_loan_adjustments_clearing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $adjustment = $this->balanceAdjustmentOn($loan, -250_000);

        $journal = $this->poster()->balanceAdjustment($adjustment, -250_000, $this->admin->id);

        $this->assertSame($journal->id, $this->journalFor($adjustment, 'adjustment')?->id);
        $this->assertSame($loan->branch_id, $journal->branch_id);
        $this->assertSame($adjustment->adjustment_number, $journal->reference);
        $this->assertSame(250_000, $this->sumOn($journal, '1180', 'debit'));
        $this->assertSame(250_000, $this->sumOn($journal, '1110', 'credit'));
    }

    public function test_an_amount_that_disagrees_with_the_adjustments_own_is_refused(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $adjustment = $this->balanceAdjustmentOn($loan, -250_000);

        try {
            $this->poster()->balanceAdjustment($adjustment, -250_001, $this->admin->id);
            $this->fail('An amount that disagrees with the adjustment was posted.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('states an adjustment of -₱2,500.00', $e->getMessage());
        }

        $this->assertSame(0, AccountingJournal::count());
    }

    /**
     * With no usable `adjustment_amount` there is nothing to check the
     * posted amount against, so it is refused rather than trusted.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableAdjustmentAmounts(): array
    {
        return [
            'missing' => [['target_principal_balance' => 0]],
            'not a number' => [['adjustment_amount' => 'two thousand five hundred']],
            'null' => [['adjustment_amount' => null]],
        ];
    }

    #[DataProvider('unusableAdjustmentAmounts')]
    public function test_an_adjustment_with_no_usable_amount_of_its_own_is_refused(array $newValues): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $adjustment = $this->balanceAdjustmentOn($loan, -250_000);
        $adjustment->update(['new_values' => $newValues]);

        try {
            $this->poster()->balanceAdjustment($adjustment->fresh(), -250_000, $this->admin->id);
            $this->fail('An adjustment with no amount of its own was posted.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('records no adjustment amount', $e->getMessage());
        }

        $this->assertSame(0, AccountingJournal::count());
    }

    public function test_an_adjustment_that_is_not_a_balance_adjustment_is_refused(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $adjustment = $this->balanceAdjustmentOn($loan, -250_000);
        $adjustment->update(['adjustment_type' => 'penalty_waiver']);

        try {
            $this->poster()->balanceAdjustment($adjustment->fresh(), -250_000, $this->admin->id);
            $this->fail('A penalty waiver posted a balance adjustment journal.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('is not a balance adjustment', $e->getMessage());
        }

        $this->assertSame(0, AccountingJournal::count());
    }

    public function test_a_balance_increase_posts_the_mirror(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $adjustment = $this->balanceAdjustmentOn($loan, 250_000);

        $journal = $this->poster()->balanceAdjustment($adjustment, 250_000, $this->admin->id);

        $this->assertSame(250_000, $this->sumOn($journal, '1110', 'debit'));
        $this->assertSame(250_000, $this->sumOn($journal, '1180', 'credit'));
    }

    public function test_a_reduction_that_completes_a_restructured_loan_clears_its_remaining_deferral(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 700_000, 300_000, 0);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');
        $this->pay($new);

        // The adjustment has closed every open period by the time it posts.
        $new->amortizationSchedules()->whereIn('status', ['pending', 'partial', 'overdue'])->update(['status' => 'paid']);
        $adjustment = $this->balanceAdjustmentOn($new, -5_000_000);

        $journal = $this->poster()->balanceAdjustment($adjustment, -5_000_000, $this->admin->id);

        $this->assertSame(5_000_000, $this->sumOn($journal, '1180', 'debit'));
        $this->assertSame(5_000_000, $this->sumOn($journal, '1110', 'credit'));
        $this->assertSame(833_333, $this->sumOn($journal, '1210', 'debit'));
        $this->assertSame(833_333, $this->described($journal, PostingRules::DEFERRAL_CLEARED_LINE, 'debit'));
        $this->assertSame(833_333, $this->sumOn($journal, '1180', 'credit'));
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($new->fresh()));
    }

    /**
     * Since 2026-11-09 a reduction that leaves periods open clears only its
     * share: ₱10,000.00 of ₱60,000.00 outstanding clears a sixth of the
     * deferral (tests/Feature/Ledger/Deferral covers the rest).
     */
    public function test_a_reduction_that_leaves_periods_open_clears_only_its_share_of_the_deferral(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 5_000_000, 700_000, 300_000, 0);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');
        $id = $this->postJson("/api/loans/{$new->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -10_000],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $journal = $this->poster()->balanceAdjustment(LoanAdjustment::findOrFail($id), -1_000_000, $this->admin->id);

        $this->assertSame(166_667, $this->sumOn($journal, '1210', 'debit'));
        $this->assertSame(0, $this->described($journal, PostingRules::DEFERRAL_CLEARED_LINE, 'debit'));
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($new->fresh()));
    }

    // ── Share capital entries ──

    public function test_a_share_capital_contribution_and_withdrawal_post_through_the_method(): void
    {
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $credit = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-11-04', 'credit' => 5000, 'debit' => 0]);
        $debit = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-11-05', 'credit' => 0, 'debit' => 2000]);

        $in = $this->poster()->shareCapitalEntry($credit, 'cash', $this->admin->id);
        $out = $this->poster()->shareCapitalEntry($debit, 'bank_transfer', $this->admin->id);

        $this->assertSame($in->id, $this->journalFor($credit, 'share_capital')?->id);
        $this->assertSame('2026-11-04', $in->date->toDateString());
        $this->assertSame($this->branch->id, $in->branch_id);
        $this->assertSame($credit->reference, $in->reference);
        $this->assertSame(500_000, $this->sumOn($in, '1010', 'debit'));
        $this->assertSame(500_000, $this->sumOn($in, '3060', 'credit'));
        $this->assertSame(200_000, $this->sumOn($out, '3060', 'debit'));
        $this->assertSame(200_000, $this->sumOn($out, '1040', 'credit'));
    }

    /** A build-up row is in its payment's journal and a release row in its release's: never twice. */
    public function test_a_share_capital_row_already_in_another_journal_is_refused(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $row = ShareCapitalLedger::factory()->create(['borrower_id' => $loan->borrower_id, 'loan_id' => $loan->id, 'credit' => 500, 'debit' => 0]);

        $this->expectException(CannotPostToTheBooksException::class);

        $this->poster()->shareCapitalEntry($row, 'cash', $this->admin->id);
    }

    // ── GCash ──

    public function test_a_paid_cash_in_posts_on_its_paid_date_in_the_members_branch(): void
    {
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $tx = GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 5000, 'charge_amount' => 20, 'total_amount' => 5020,
            'status' => 'paid', 'paid_at' => '2026-11-05 10:00:00', 'transaction_date' => '2026-11-03 09:00:00',
            'borrower_id' => $member->id,
        ]);

        $journal = $this->poster()->gcashTransaction($tx, $this->admin->id);

        $this->assertSame($journal->id, $this->journalFor($tx, 'cash_in')?->id);
        $this->assertSame('2026-11-05', $journal->date->toDateString());
        $this->assertSame($this->branch->id, $journal->branch_id);
        $this->assertSame($tx->reference_no, $journal->reference);
        $this->assertSame(502_000, $this->sumOn($journal, '1010', 'debit'));
        $this->assertSame(500_000, $this->sumOn($journal, '1020', 'credit'));
        $this->assertSame(2_000, $this->sumOn($journal, '4090', 'credit'));
    }

    public function test_a_walk_in_cash_out_posts_on_its_date_with_no_branch(): void
    {
        $this->seedChartOfAccounts();
        $tx = GCashTransaction::factory()->forNonMember()->create([
            'type' => 'cash_out', 'amount' => 5000, 'charge_amount' => 20, 'total_amount' => 4980,
            'status' => 'completed', 'transaction_date' => '2026-11-06 09:00:00',
        ]);

        $journal = $this->poster()->gcashTransaction($tx, $this->admin->id);

        $this->assertSame('cash_out', $journal->source);
        $this->assertSame('2026-11-06', $journal->date->toDateString());
        $this->assertNull($journal->branch_id);
        $this->assertSame(500_000, $this->sumOn($journal, '1020', 'debit'));
        $this->assertSame(498_000, $this->sumOn($journal, '1010', 'credit'));
        $this->assertSame(2_000, $this->sumOn($journal, '4090', 'credit'));
    }

    public function test_a_gcash_transaction_with_no_charge_posts_no_income_line(): void
    {
        $this->seedChartOfAccounts();
        $tx = GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 5000, 'charge_amount' => 0, 'total_amount' => 5000,
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $journal = $this->poster()->gcashTransaction($tx, $this->admin->id);

        $this->assertSame(2, $journal->lines()->count());
        $this->assertSame(0, $this->sumOn($journal, '4090', 'credit'));
    }

    public function test_a_cash_in_not_yet_paid_is_refused(): void
    {
        $this->seedChartOfAccounts();
        $tx = GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 5000, 'charge_amount' => 20, 'total_amount' => 5020,
            'status' => 'pending', 'paid_at' => null,
        ]);

        $this->expectException(CannotPostToTheBooksException::class);

        $this->poster()->gcashTransaction($tx, $this->admin->id);
    }

    public function test_a_gcash_transaction_whose_total_disagrees_with_its_parts_is_refused(): void
    {
        $this->seedChartOfAccounts();
        $tx = GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 5000, 'charge_amount' => 20, 'total_amount' => 5000,
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->expectException(CannotPostToTheBooksException::class);

        $this->poster()->gcashTransaction($tx, $this->admin->id);
    }

    // ── Allowance ──

    public function test_an_allowance_adjustment_posts_in_the_direction_of_its_sign(): void
    {
        $this->seedChartOfAccounts();
        $first = ShareCapitalLedger::factory()->create();
        $second = ShareCapitalLedger::factory()->create();

        $up = $this->poster()->allowanceAdjustment($first, 300_000, $this->branch->id, '2026-11-07', $this->admin->id);
        $down = $this->poster()->allowanceAdjustment($second, -100_000, null, '2026-11-08', $this->admin->id);

        $this->assertSame('credit_loss', $up->source);
        $this->assertSame('2026-11-07', $up->date->toDateString());
        $this->assertSame($this->branch->id, $up->branch_id);
        $this->assertSame(300_000, $this->sumOn($up, '5140', 'debit'));
        $this->assertSame(300_000, $this->sumOn($up, '1200', 'credit'));
        $this->assertNull($down->branch_id);
        $this->assertSame(100_000, $this->sumOn($down, '1200', 'debit'));
        $this->assertSame(100_000, $this->sumOn($down, '5140', 'credit'));
    }

    /** A loan's credit_loss slot belongs to its restructure write-off. */
    public function test_an_allowance_adjustment_keyed_on_a_loan_is_refused(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();

        $this->expectException(CannotPostToTheBooksException::class);

        $this->poster()->allowanceAdjustment($loan, 300_000, null, '2026-11-07', $this->admin->id);
    }

    /**
     * The older provision rule takes the same slot: keyed on a loan, it would
     * be handed back as that loan's restructure write-off, and J2 would never
     * post.
     */
    public function test_a_credit_loss_provision_keyed_on_a_loan_is_refused(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();

        try {
            $this->poster()->creditLossProvision($loan, '2500.00', $this->admin->id);
            $this->fail('A provision keyed on a loan was posted.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('restructure write-off', $e->getMessage());
        }

        $this->assertSame(0, AccountingJournal::count());
    }

    // ── The release method ──

    public function test_a_release_settles_through_the_account_for_its_lending_method(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $net = $this->centavos($loan->net_proceeds);
        $map = AccountMap::resolve();

        $credited = fn (string $method, string $code): int => collect($this->poster()->releasePosting($loan, $map, $method)['lines'])
            ->where('account_id', $this->account($code))->sum('credit');

        $this->assertSame($net, $credited('cash', '1010'));
        $this->assertSame($net, $credited('gcash', '1020'));
        $this->assertSame($net, $credited('maya', '1030'));
        $this->assertSame($net, $credited('bank_transfer', '1040'));
        $this->assertSame($net, $credited('online', '1040'));
        $this->assertSame($net, $credited('auto_pay', '1040'));

        $this->expectException(CannotPostToTheBooksException::class);
        $this->poster()->releasePosting($loan, $map, 'crypto');
    }

    // ── No chart, no books; and failing closed ──

    public function test_without_a_chart_no_new_entry_point_posts(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 6_000_000, 0, 0, 500_000);
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $row = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id]);
        $tx = GCashTransaction::factory()->create(['status' => 'paid', 'paid_at' => now()]);
        $poster = $this->poster();

        $this->assertSame(['release' => null, 'write_off' => null], $poster->restructureRelease($new, $source, $this->admin->id, 'cash'));
        $this->assertNull($poster->balanceAdjustment($this->balanceAdjustmentOn($new, -100_000), -100_000, $this->admin->id));
        $this->assertNull($poster->shareCapitalEntry($row, 'cash', $this->admin->id));
        $this->assertNull($poster->gcashTransaction($tx, $this->admin->id));
        $this->assertNull($poster->allowanceAdjustment($row, 100_000, null, '2026-11-07', $this->admin->id));
        $this->pay($new);

        $this->assertSame(0, AccountingJournal::count());
    }

    /** A Loan key is a caller's mistake, so it is refused with or without books, as a missing transaction is. */
    public function test_without_a_chart_a_provision_or_allowance_adjustment_keyed_on_a_loan_is_still_refused(): void
    {
        $loan = $this->createReleasedLoan();
        $calls = [
            'creditLossProvision' => fn () => $this->poster()->creditLossProvision($loan, '2500.00', $this->admin->id),
            'allowanceAdjustment' => fn () => $this->poster()->allowanceAdjustment($loan, 300_000, null, '2026-11-07', $this->admin->id),
        ];

        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail("{$method}() accepted a loan key without books.");
            } catch (CannotPostToTheBooksException $e) {
                $this->assertStringContainsString('restructure write-off', $e->getMessage(), $method);
            }
        }
    }

    // ── The allowance lock order (JournalPoster) ──

    /**
     * A post takes the allowance lock only if the draft it read had an
     * allowance line. If one appears before its journal lock (an edit landing
     * in between), the lock order can no longer be kept, so it is refused
     * rather than posted out of order.
     */
    public function test_a_draft_that_gains_an_allowance_line_while_it_is_being_posted_is_refused(): void
    {
        $this->seedChartOfAccounts();
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5140'), 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100_000],
        ]);
        $edited = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$edited, $draft): void {
            $sql = strtolower($query);

            if ($edited
                || ! str_starts_with($sql, 'select * from `accounting_journals`')
                || ! str_contains($sql, 'for update')
                || (int) ($bindings[0] ?? 0) !== $draft->id) {
                return;
            }

            $edited = true;

            foreach ([[3, '1010', 50_000, 0], [4, '1200', 0, 50_000]] as [$lineNo, $code, $debit, $credit]) {
                DB::table('accounting_journal_lines')->insert([
                    'accounting_journal_id' => $draft->id,
                    'accounting_account_id' => $this->account($code),
                    'line_no' => $lineNo,
                    'debit' => $debit,
                    'credit' => $credit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        try {
            app(JournalPoster::class)->post($draft, $this->admin->id);
            $this->fail('A draft that gained an allowance line mid-post was posted.');
        } catch (ValidationException $e) {
            $this->assertTrue($edited, 'the draft was never edited mid-post, so this test proved nothing');
            $this->assertStringContainsString('changed while it was being posted', $e->getMessage());
        }

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_an_unmapped_new_role_refuses_and_writes_nothing(): void
    {
        $this->seedChartOfAccounts();
        AccountingAccountMapping::query()->where('role', 'gcash_service_income')->delete();
        $charged = GCashTransaction::factory()->create(['status' => 'paid', 'paid_at' => now()]);
        $free = GCashTransaction::factory()->create(['charge_amount' => 0, 'total_amount' => 1000, 'status' => 'paid', 'paid_at' => now()]);

        try {
            $this->poster()->gcashTransaction($charged, $this->admin->id);
            $this->fail('An unmapped role must refuse the posting.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('gcash_service_income', $e->getMessage());
        }

        $this->assertNull($this->journalFor($charged, 'cash_in'));
        // A ₱0.00 charge never asks for the role.
        $this->assertNotNull($this->poster()->gcashTransaction($free, $this->admin->id));
    }

    // ── The journal register names the new documents ──

    public function test_the_register_names_each_new_document(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $adjustment = $this->balanceAdjustmentOn($loan, -100_000);
        $row = ShareCapitalLedger::factory()->create(['borrower_id' => $loan->borrower_id]);
        $tx = GCashTransaction::factory()->create(['status' => 'paid', 'paid_at' => now()]);

        $this->poster()->balanceAdjustment($adjustment, -100_000, $this->admin->id);
        $this->poster()->shareCapitalEntry($row, 'cash', $this->admin->id);
        $this->poster()->gcashTransaction($tx, $this->admin->id);

        $entries = collect($this->getJson('/api/accounting/journals?per_page=100')->assertOk()->json('data'))->keyBy('source');

        $this->assertSame('loan_adjustment', $entries['adjustment']['postable_type']);
        $this->assertSame($adjustment->adjustment_number, $entries['adjustment']['postable_label']);
        $this->assertSame('share_capital_entry', $entries['share_capital']['postable_type']);
        $this->assertSame($row->reference, $entries['share_capital']['postable_label']);
        $this->assertSame('gcash_transaction', $entries['cash_in']['postable_type']);
        $this->assertSame($tx->reference_no, $entries['cash_in']['postable_label']);
    }
}
