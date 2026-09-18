<?php

namespace Tests\Feature;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\Accounting\AutomaticPoster;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The wiring: a lending event happens, and the books say so.
 *
 * The rules themselves are unit-tested in AccountingPostingRulesTest, with no
 * database. What is tested HERE is everything that only exists once the engine
 * is attached to a real transaction — the pieces a green rule suite cannot
 * prove:
 *
 * - the journal carries the document it came from, so an entry can be traced
 *   back to the loan or the receipt that caused it;
 * - a retry does not write a second entry, which would leave the trial balance
 *   balanced and the portfolio double-counted;
 * - a rolled-back preview leaves nothing behind;
 * - pesos become centavos exactly once, and by a factor of exactly 100;
 * - a missing setting stops the business transaction instead of quietly
 *   skipping the entry.
 */
class AccountingAutomaticPostingTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * A released loan with EXACT, deliberately chosen money figures.
     *
     * ₱50,000.00 principal, ₱1,234.56 withheld, ₱48,765.44 disbursed. The
     * amounts are not round on purpose — see
     * {@see self::test_pesos_become_centavos_by_a_factor_of_exactly_one_hundred()}.
     */
    private function releaseLoanWithDeductions(
        float $principal = 50000.00,
        float $deduction = 1234.56,
    ): Loan {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);

        $loan = $loans->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'deductions' => [
                ['name' => 'Processing Fee', 'amount' => $deduction, 'type' => 'fixed'],
            ],
        ], $this->admin);

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin);

        return $loan->fresh();
    }

    /** The journal raised for a document and source, if any. */
    private function journalFor(object $postable, string $source): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $postable->getMorphClass())
            ->where('postable_id', $postable->getKey())
            ->where('source', $source)
            ->first();
    }

    /** The debit or credit that landed on an account code. */
    private function lineOn(AccountingJournal $journal, string $code, string $side): int
    {
        $line = $journal->lines()->where('accounting_account_id', $this->account($code))->first();

        return $line === null ? 0 : (int) $line->{$side};
    }

    // ── A release produces a traceable journal ──

    public function test_a_loan_release_posts_a_journal_against_the_loan_that_caused_it(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();
        $journal = $this->journalFor($loan, 'loan_release');

        $this->assertNotNull($journal, 'Releasing a loan wrote no journal.');

        // The document, so the entry can be traced back from the books.
        $this->assertSame(Loan::class, $journal->postable_type);
        $this->assertSame($loan->id, $journal->postable_id);
        $this->assertSame('loan_release', $journal->source);

        // In the books, not sitting as a draft nobody posted.
        $this->assertSame('posted', $journal->status);
        $this->assertSame('JE-000001', $journal->journal_no);

        // Findable by the paper the borrower holds.
        $this->assertSame($loan->loan_account_number, $journal->reference);
        $this->assertSame($loan->branch_id, $journal->branch_id);

        // And it resolves back through the relation the register renders.
        $this->assertTrue($journal->postable->is($loan));
    }

    /**
     * THE BUG IN THE FRONTEND SPEC, proven against a persisted entry.
     *
     * `posting-rules.ts` credits cash with the FULL loan amount. This backend
     * withholds fees at release, so posting the gross would overstate cash by
     * ₱1,234.56 and report ₱0 of fee income — while balancing perfectly.
     */
    public function test_a_release_credits_cash_with_what_actually_left_the_drawer(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();
        $journal = $this->journalFor($loan, 'loan_release');

        // The borrower owes the whole principal.
        $this->assertSame(5_000_000, $this->lineOn($journal, '1110', 'debit'));
        // Only the net left the drawer.
        $this->assertSame(4_876_544, $this->lineOn($journal, '1010', 'credit'));
        // The rest is income, and is visible as income.
        $this->assertSame(123_456, $this->lineOn($journal, '4030', 'credit'));

        $this->assertSame(5_000_000, (int) $journal->total_debit);
        $this->assertSame((int) $journal->total_debit, (int) $journal->total_credit);
    }

    /**
     * THE 100x TEST.
     *
     * ₱50,000.00 must land as 5_000_000 centavos. Every plausible unit mistake
     * gives a different, visible number, which is the point of asserting the
     * exact figure rather than a balance:
     *
     *   - conversion skipped entirely →     50_000
     *   - multiplied by 10             →    500_000
     *   - multiplied by 10_000         → 500_000_000
     *
     * None of those would fail a balance check: an entry built consistently in
     * the wrong unit balances perfectly and misstates every statement it
     * appears on. The deduction is ₱1,234.56 rather than a round figure because
     * a value with non-zero centavos also catches the OTHER way this breaks —
     * `(int) (1234.56 * 100)` is 123455, a centavo short, since 1234.56 has no
     * exact binary representation. Money::toCentavos() parses the decimal string
     * digit by digit precisely so that cannot happen.
     */
    public function test_pesos_become_centavos_by_a_factor_of_exactly_one_hundred(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions(principal: 50000.00, deduction: 1234.56);
        $journal = $this->journalFor($loan, 'loan_release');

        // The peso figures on the loan, for the record.
        $this->assertSame('50000.00', (string) $loan->principal_amount);
        $this->assertSame('1234.56', (string) $loan->total_deductions);
        $this->assertSame('48765.44', (string) $loan->net_proceeds);

        // The centavo figures in the books. Exactly 100x, to the centavo.
        $this->assertSame(5_000_000, $this->lineOn($journal, '1110', 'debit'));
        $this->assertSame(123_456, $this->lineOn($journal, '4030', 'credit'));
        $this->assertSame(4_876_544, $this->lineOn($journal, '1010', 'credit'));

        // The fractional centavo is not lost on the way in.
        $this->assertNotSame(123_455, $this->lineOn($journal, '4030', 'credit'));
    }

    /**
     * The ORDERING hazard, tested rather than trusted to a comment.
     *
     * `applyInsuranceOnRelease()` withholds the premium by REWRITING
     * `net_proceeds` and `total_deductions` on the loan, partway through the
     * release transaction. Post before it — which is what a `Loan::updated`
     * observer would do — and the entry credits cash with ₱500.00 that never
     * left the drawer and leaves the premium out of income, while balancing
     * perfectly.
     *
     * ₱50,000 principal, ₱1,234.56 of fees, plus a ₱500.00 premium withheld:
     * ₱48,265.44 handed over, ₱1,734.56 kept.
     */
    public function test_a_release_posts_the_net_left_after_insurance_is_withheld(): void
    {
        $this->seedChartOfAccounts();

        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6,
            'frequency' => 'monthly', 'processing_fee' => 0, 'service_fee' => 0, 'notarial_fee' => 0,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);

        $loan = $loans->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000.00,
            'start_date' => now()->toDateString(),
            'deductions' => [['name' => 'Processing Fee', 'amount' => 1234.56, 'type' => 'fixed']],
        ], $this->admin);

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin, [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 500.00,
            'insurance_payment_type' => 'full',
        ]);

        $loan = $loan->fresh();
        $journal = $this->journalFor($loan, 'loan_release');

        // The loan itself, after the premium was withheld.
        $this->assertSame('48265.44', (string) $loan->net_proceeds);
        $this->assertSame('1734.56', (string) $loan->total_deductions);

        // And the books agree with it, to the centavo.
        $this->assertSame(5_000_000, $this->lineOn($journal, '1110', 'debit'));
        $this->assertSame(4_826_544, $this->lineOn($journal, '1010', 'credit'));
        $this->assertSame(173_456, $this->lineOn($journal, '4030', 'credit'));

        // Posting BEFORE applyInsuranceOnRelease() would have credited cash
        // with 4_876_544 — the pre-insurance figure — and still balanced.
        $this->assertNotSame(4_876_544, $this->lineOn($journal, '1010', 'credit'));
    }

    // ── Idempotency: a retry must not double-post ──

    /**
     * A retry returns the entry that already exists.
     *
     * This is the module's worst failure mode if it is wrong, and the reason it
     * is worst is that nothing looks broken: two balanced journals leave the
     * trial balance balanced, every statement internally consistent, and the
     * portfolio silently counted twice.
     */
    public function test_reposting_a_release_returns_the_existing_journal_instead_of_a_second_one(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();
        $first = $this->journalFor($loan, 'loan_release');

        // The retry: a redelivered job, a double-clicked button, a timeout the
        // caller resolved as a failure.
        $second = app(AutomaticPoster::class)->loanRelease($loan->fresh(), $this->admin->id);
        $third = app(AutomaticPoster::class)->loanRelease($loan->fresh(), $this->admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);

        $this->assertSame(1, AccountingJournal::query()
            ->where('postable_type', Loan::class)
            ->where('postable_id', $loan->id)
            ->where('source', 'loan_release')
            ->count());

        // No second number was burned, and no second set of lines written.
        $this->assertSame(1, AccountingJournal::query()->count());
        $this->assertCount(3, $first->fresh()->lines);
    }

    /**
     * The guard is the DATABASE, not the SELECT above it.
     *
     * `JournalPoster::postImmediately()` checks for an existing entry first,
     * which answers the ordinary retry cheaply. That check cannot answer two
     * retries racing — both read "no journal yet", and both then insert. So the
     * real guarantee has to be the unique index, and this asserts the index
     * itself refuses the duplicate rather than trusting the code path that
     * usually gets there first.
     */
    public function test_the_database_itself_refuses_a_second_journal_for_the_same_document_and_source(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();
        $existing = $this->journalFor($loan, 'loan_release');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/accounting_journals_postable_source_unique/');

        // Bypasses every application check on purpose: the point is that even a
        // writer that skipped the poster entirely cannot double-post.
        DB::table('accounting_journals')->insert([
            'date' => $existing->date->toDateString(),
            'source' => 'loan_release',
            'description' => 'A second release entry for the same loan',
            'status' => 'draft',
            'postable_type' => Loan::class,
            'postable_id' => $loan->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A different SOURCE against the same document is allowed, and must be.
     *
     * One loan legitimately raises several entries over its life — the release,
     * and later a provision against it. Keying idempotency on the document
     * alone would make the second one impossible.
     */
    public function test_a_different_source_against_the_same_loan_is_a_separate_entry(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();
        $provision = app(AutomaticPoster::class)->creditLossProvision($loan, '2500.00', $this->admin->id);

        $this->assertNotNull($provision);
        $this->assertSame('credit_loss', $provision->source);
        $this->assertNotSame($this->journalFor($loan, 'loan_release')->id, $provision->id);
        $this->assertSame(2, AccountingJournal::query()->where('postable_id', $loan->id)->count());
    }

    // ── A collection ──

    public function test_a_repayment_posts_a_collection_against_the_receipt_not_the_loan(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();

        $first = app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);
        $second = app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $firstJournal = $this->journalFor($first, 'loan_collection');
        $secondJournal = $this->journalFor($second, 'loan_collection');

        $this->assertNotNull($firstJournal);
        $this->assertNotNull($secondJournal);

        // The document is the RECEIPT. Keyed on the loan instead, the unique
        // index would have permitted the first collection and silently returned
        // it for every payment after — the portfolio moving while the books
        // stood still.
        $this->assertSame(Repayment::class, $firstJournal->postable_type);
        $this->assertNotSame($firstJournal->id, $secondJournal->id);
        $this->assertSame($first->receipt_number, $firstJournal->reference);

        // Every peso received is in the drawer.
        $this->assertSame(500_000, $this->lineOn($firstJournal, '1010', 'debit'));
        $this->assertSame((int) $firstJournal->total_debit, (int) $firstJournal->total_credit);
    }

    public function test_a_collection_settles_through_the_account_matching_its_payment_method(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();

        $repayment = app(RepaymentService::class)->processRepayment(
            $loan->fresh(), 5000, now()->toDateString(), $this->admin,
            method: 'bank_transfer', referenceNumber: 'TRF-1',
        );

        $journal = $this->journalFor($repayment, 'loan_collection');

        // `bank_transfer` is money in the bank, not in the drawer. Three of the
        // six lending methods have no same-named settlement role.
        $this->assertSame(500_000, $this->lineOn($journal, '1040', 'debit'));
        $this->assertSame(0, $this->lineOn($journal, '1010', 'debit'));
    }

    // ── previewAllocation() must leave nothing behind ──

    /**
     * The preview runs a REAL processRepayment() inside a transaction it rolls
     * back, so the numbers it shows match what a real payment would do.
     *
     * That is exactly why the posting call is explicit and inside the same
     * transaction rather than in an observer: the rollback takes the journal
     * with it, by the same mechanism that takes the repayment row. An observer
     * hung off `Repayment::created` would be posting entries for payments
     * nobody made, and would keep doing so the moment anything about that
     * rollback changed.
     */
    public function test_a_rolled_back_preview_leaves_no_journal_behind(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();

        $journalsBefore = AccountingJournal::query()->count();
        $linesBefore = DB::table('accounting_journal_lines')->count();
        $repaymentsBefore = Repayment::query()->count();

        $preview = app(RepaymentService::class)->previewAllocation(
            $loan->fresh(), 5000, now()->toDateString(), $this->admin,
        );

        // The preview really did run the allocation.
        $this->assertTrue($preview['is_preview']);
        $this->assertGreaterThan(0, $preview['total_principal'] + $preview['total_interest']);

        // And left nothing at all.
        $this->assertSame($repaymentsBefore, Repayment::query()->count());
        $this->assertSame($journalsBefore, AccountingJournal::query()->count());
        $this->assertSame($linesBefore, (int) DB::table('accounting_journal_lines')->count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'loan_collection')->count());
    }

    // ── Voiding ──

    /**
     * A void reverses the collection; it does not delete it.
     *
     * Without this the collection would stay on the books forever — cash and
     * income overstated by the whole payment, the loan balance restored by
     * reverseAllocation(), and the two silently disagreeing.
     */
    public function test_voiding_a_payment_reverses_its_journal_and_keeps_both_halves(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();
        $repayment = app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);
        $original = $this->journalFor($repayment, 'loan_collection');

        app(RepaymentService::class)->voidRepayment($repayment->fresh(), 'Keyed twice', $this->admin);

        $original = $original->fresh();
        $this->assertSame('reversed', $original->status);
        $this->assertNotNull($original->reversed_by_journal_id);

        $mirror = AccountingJournal::find($original->reversed_by_journal_id);
        $this->assertSame('reversal', $mirror->source);
        $this->assertSame($original->id, $mirror->reverses_journal_id);

        // The pair nets to zero: what was credited is now debited, to the centavo.
        $this->assertSame((int) $original->total_debit, (int) $mirror->total_credit);
        $this->assertSame(
            $this->lineOn($original, '1010', 'debit'),
            $this->lineOn($mirror, '1010', 'credit'),
        );

        // BOTH halves stay on the record. That is what makes the void auditable
        // rather than merely current.
        $this->assertNotNull($original->fresh());
    }

    // ── Failing closed ──

    /**
     * A missing setting stops the RELEASE, rather than releasing the money and
     * quietly not mentioning it.
     *
     * This is the trade the module is built on. Refusing is loud and
     * recoverable; a gap in the books is silent and permanent, because nothing
     * reports an entry that was never due.
     */
    public function test_a_missing_account_mapping_refuses_the_release_and_rolls_it_back(): void
    {
        $this->seedChartOfAccounts();

        // The role the release rule needs for the fees it withholds.
        AccountingAccountMapping::query()->where('role', 'processing_fee_income')->delete();

        try {
            $this->releaseLoanWithDeductions();
            $this->fail('The release was allowed to happen with no entry in the books.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('processing_fee_income', $e->getMessage());
        }

        // Nothing was written: no journal, and no released loan either.
        $this->assertSame(0, AccountingJournal::query()->count());
        $this->assertSame(0, Loan::query()->whereIn('status', Loan::EVER_RELEASED_STATUSES)->count());
        $this->assertSame(0, Loan::query()->whereNotNull('loan_account_number')->count());
    }

    public function test_a_missing_mapping_refuses_the_payment_and_rolls_it_back(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();
        AccountingAccountMapping::query()->where('role', 'interest_income')->delete();

        try {
            app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);
            $this->fail('The payment was recorded with no entry in the books.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('interest_income', $e->getMessage());
        }

        // The repayment row went with it, and so did the schedule allocation.
        $this->assertSame(0, Repayment::query()->count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'loan_collection')->count());
        $this->assertSame(
            0.0,
            (float) $loan->fresh()->amortizationSchedules()->sum('principal_paid'),
        );
    }

    /**
     * THE BLAST RADIUS, tested directly.
     *
     * An earlier draft resolved every role in the array literal before dropping
     * the zero lines, so an ORDINARY collection — no penalty, no fees, no
     * overpayment — demanded `penalty_income`, `processing_fee_income` AND
     * `borrower_advances` anyway. On a deployment that adopted accounting
     * before this branch, where `borrower_advances` is unmapped and 2300 may
     * have been tidied away, that would have refused EVERY repayment rather
     * than the overpaying ones.
     *
     * Neither of the "emits no X line" tests catches it, because both run
     * against a complete map. This one removes the role AND the account behind
     * it, which is the worst real state.
     */
    public function test_a_role_behind_a_zero_leg_is_never_resolved(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();

        // The pre-backfill deployment: role unmapped, and the account it would
        // have pointed at deleted as an unused heading-less leaf.
        AccountingAccountMapping::query()->where('role', 'borrower_advances')->delete();
        DB::table('accounting_accounts')->where('code', '2300')->delete();

        // An ordinary payment: every leg but principal/interest is zero.
        $repayment = app(RepaymentService::class)->processRepayment(
            $loan->fresh(), 5000, now()->toDateString(), $this->admin,
        );

        $journal = $this->journalFor($repayment, 'loan_collection');

        $this->assertNotNull($journal, 'An ordinary collection was refused over a role it never uses.');
        $this->assertSame(0.0, (float) $repayment->overpayment);
        $this->assertSame((int) $journal->total_debit, (int) $journal->total_credit);

        // Only the legs that carry an amount are present.
        $this->assertSame(0, $this->lineOn($journal, '4020', 'credit'));
    }

    /** A zero-deduction release likewise never asks for the fee account. */
    public function test_a_zero_fee_release_does_not_require_the_fee_account(): void
    {
        $this->seedChartOfAccounts();

        AccountingAccountMapping::query()->where('role', 'processing_fee_income')->delete();

        $loan = $this->releaseLoanWithDeductions(principal: 50000.00, deduction: 0.0);
        $journal = $this->journalFor($loan, 'loan_release');

        $this->assertNotNull($journal, 'A fee-free release was refused over the fee account.');
        $this->assertCount(2, $journal->lines);
        $this->assertSame(5_000_000, $this->lineOn($journal, '1110', 'debit'));
        $this->assertSame(5_000_000, $this->lineOn($journal, '1010', 'credit'));
    }

    // ── The gate: organisations that keep no books ──

    /**
     * Failing closed on a ROLE must not mean failing closed on an organisation
     * that has never opened the accounting module.
     *
     * Every deployment that has not seeded a chart would otherwise stop
     * releasing loans the moment this shipped — turning a correctness
     * improvement into an outage. An organisation with no chart has no books,
     * so there is no entry to miss.
     */
    public function test_an_organisation_with_no_chart_of_accounts_still_releases_and_collects(): void
    {
        $this->assertSame(0, DB::table('accounting_accounts')->count());

        $loan = $this->releaseLoanWithDeductions();

        $this->assertSame('released', $loan->status);
        $this->assertNotNull($loan->loan_account_number);
        $this->assertSame(0, AccountingJournal::query()->count());

        $repayment = app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $this->assertSame('posted', $repayment->status);
        $this->assertSame(0, AccountingJournal::query()->count());

        // And voiding one is not blocked by a journal that never existed.
        app(RepaymentService::class)->voidRepayment($repayment->fresh(), 'Keyed twice', $this->admin);
        $this->assertSame('voided', $repayment->fresh()->status);
    }

    /**
     * The importer bulk-creates a coop's historical portfolio as `released` and
     * `ongoing` rows WITHOUT going through LoanService::release().
     *
     * Those disbursements happened years ago under someone else's books. An
     * observer on `Loan` would post a journal for every one of them, dated
     * today, the first time an organisation imported its history — which is the
     * third of the three reasons this engine is called explicitly.
     */
    public function test_creating_a_released_loan_row_directly_posts_nothing(): void
    {
        $this->seedChartOfAccounts();

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $product = LoanProduct::factory()->create();

        Loan::create([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'principal_amount' => 50000,
            'start_date' => now()->toDateString(),
            'maturity_date' => now()->addMonths(6)->toDateString(),
            'total_deductions' => 0,
            'net_proceeds' => 50000,
            'status' => 'ongoing',
            'loan_account_number' => 'LN-900001',
            'released_at' => now()->subYear(),
        ]);

        $this->assertSame(0, AccountingJournal::query()->count());
    }

    // ── The new role ──

    /**
     * `amount_paid` is not bounded by what is owed, and the excess is money the
     * organisation is HOLDING rather than money it has earned.
     */
    public function test_an_overpayment_is_held_as_a_liability_rather_than_taken_as_income(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->releaseLoanWithDeductions();

        // Far more than the whole schedule: everything settles and a remainder
        // is left over as `overpayment`.
        $repayment = app(RepaymentService::class)->processRepayment(
            $loan->fresh(), 90000, now()->toDateString(), $this->admin,
        );

        $this->assertGreaterThan(0, (float) $repayment->overpayment);

        $journal = $this->journalFor($repayment, 'loan_collection');

        // The drawer holds every peso that arrived.
        $this->assertSame(9_000_000, $this->lineOn($journal, '1010', 'debit'));

        // And the excess sits in a liability — 2300 Other Liabilities — not in
        // any income account.
        $expected = (int) round((float) $repayment->overpayment * 100);
        $this->assertSame($expected, $this->lineOn($journal, '2300', 'credit'));
        $this->assertSame((int) $journal->total_debit, (int) $journal->total_credit);
    }

    /**
     * The backfill, which is what keeps this from being an outage.
     *
     * ChartOfAccountsSeeder runs once and refuses to run over an existing
     * chart, so a deployment that adopted accounting before this branch would
     * never get the new role from it — and would then refuse the first payment
     * that arrived with an overpayment. Simulated here by seeding a chart and
     * removing the row, which is exactly the state such a deployment is in.
     */
    public function test_the_backfill_maps_the_new_role_on_a_chart_seeded_before_it_existed(): void
    {
        $this->seedChartOfAccounts();

        // An older deployment: a full chart, twelve mappings, no borrower_advances.
        AccountingAccountMapping::query()->where('role', 'borrower_advances')->delete();
        $this->assertArrayNotHasKey('borrower_advances', AccountingAccountMapping::resolved());

        $this->runBorrowerAdvancesBackfill();

        $this->assertSame(
            $this->account('2300'),
            AccountingAccountMapping::resolved()['borrower_advances'] ?? null,
        );
    }

    /** Re-running it must not disturb a mapping an administrator re-pointed. */
    public function test_the_backfill_leaves_an_administrators_own_choice_alone(): void
    {
        $this->seedChartOfAccounts();

        AccountingAccountMapping::query()
            ->where('role', 'borrower_advances')
            ->update(['accounting_account_id' => $this->account('2020')]);

        $this->runBorrowerAdvancesBackfill();

        $this->assertSame(
            $this->account('2020'),
            AccountingAccountMapping::resolved()['borrower_advances'],
        );
    }

    /**
     * An organisation with no chart gets nothing, so it is not left holding a
     * mapping table with no accounts behind it.
     */
    public function test_the_backfill_does_nothing_where_there_are_no_books(): void
    {
        $this->assertSame(0, DB::table('accounting_accounts')->count());

        $this->runBorrowerAdvancesBackfill();

        $this->assertSame(0, DB::table('accounting_account_mappings')->count());
    }

    private function runBorrowerAdvancesBackfill(): void
    {
        (require database_path('migrations/2026_09_18_090000_map_borrower_advances_role.php'))->up();
    }

    public function test_the_borrower_advances_role_is_seeded_with_the_default_chart(): void
    {
        $this->seedChartOfAccounts();

        $mapping = AccountingAccountMapping::resolved();

        $this->assertArrayHasKey('borrower_advances', $mapping);
        $this->assertSame($this->account('2300'), $mapping['borrower_advances']);

        // Every declared role resolves, so no rule can ask for one that does not.
        foreach (AccountingAccountMapping::ROLES as $role) {
            $this->assertArrayHasKey($role, $mapping, "The {$role} role was not seeded.");
        }
    }
}
