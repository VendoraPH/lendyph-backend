<?php

namespace Tests\Unit;

use App\Exceptions\CannotPostToTheBooksException;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingRules;
use PHPUnit\Framework\TestCase;

/**
 * The posting rules, ported from `src/lib/accounting/posting-rules.test.ts`.
 *
 * Two invariants are asserted on EVERY shape, the same two the frontend suite
 * asserts: the result balances, and no zero-amount line is ever emitted. They
 * are checked by {@see self::assertBalancedAndNonEmpty()} rather than by hand,
 * because a rule that emits a zero line is not a cosmetic problem — it puts a
 * row in the general ledger for an account the transaction never touched, and
 * the entry still balances, so nothing downstream reports it.
 *
 * Pure arithmetic against a literal mapping. No database and no application
 * boot: a rule that needed either would not be a rule, it would be a query.
 */
class AccountingPostingRulesTest extends TestCase
{
    /**
     * A mapping with a distinct, recognisable id per role, so an assertion that
     * a line landed on the wrong account reads as the wrong NUMBER rather than
     * as an off-by-one.
     */
    private function map(): AccountMap
    {
        return AccountMap::fromArray([
            'cash' => 1010,
            'gcash' => 1020,
            'maya' => 1030,
            'bank' => 1040,
            'loans_receivable' => 1110,
            'interest_receivable' => 1150,
            'penalty_receivable' => 1160,
            'interest_income' => 4010,
            'penalty_income' => 4020,
            'processing_fee_income' => 4030,
            'credit_loss_expense' => 5140,
            'allowance_credit_losses' => 1200,
            'accounts_payable' => 2010,
            'borrower_advances' => 2300,
        ]);
    }

    /**
     * @param  array{lines: list<array{account_id:int, debit:int, credit:int}>}  $posting
     */
    private function assertBalancedAndNonEmpty(array $posting): void
    {
        $debit = array_sum(array_column($posting['lines'], 'debit'));
        $credit = array_sum(array_column($posting['lines'], 'credit'));

        $this->assertSame($debit, $credit, 'The entry does not balance.');
        $this->assertGreaterThan(0, $debit, 'The entry records nothing.');

        foreach ($posting['lines'] as $index => $line) {
            $this->assertTrue(
                $line['debit'] !== 0 || $line['credit'] !== 0,
                "Line {$index} carries no amount on either side.",
            );
            $this->assertTrue(
                $line['debit'] === 0 || $line['credit'] === 0,
                "Line {$index} is both a debit and a credit.",
            );
        }
    }

    /** The debit/credit a role received, for readable assertions. */
    private function amountOn(array $posting, int $accountId, string $side): int
    {
        foreach ($posting['lines'] as $line) {
            if ($line['account_id'] === $accountId) {
                return $line[$side];
            }
        }

        return 0;
    }

    // ── loan_release: the rule the frontend spec gets wrong ──

    /**
     * THE FIX. `posting-rules.ts` credits cash with the full loan amount.
     *
     * This backend withholds fees at release, so only `net_proceeds` leaves the
     * drawer. Posting the gross overstates cash by every peso withheld and omits
     * the fee income entirely — and because both sides moved by the same amount
     * the entry balances, posts, and is reported on the balance sheet and the
     * income statement as though it were correct.
     *
     * ₱50,000.00 principal, ₱1,234.56 withheld, ₱48,765.44 disbursed.
     */
    public function test_a_release_credits_cash_with_the_net_and_the_deductions_to_income(): void
    {
        $posting = PostingRules::loanRelease(5_000_000, 4_876_544, 123_456, 'cash', $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('loan_release', $posting['source']);

        // The borrower owes the FULL principal — the schedule is built on it.
        $this->assertSame(5_000_000, $this->amountOn($posting, 1110, 'debit'));
        // Only the net actually left the drawer.
        $this->assertSame(4_876_544, $this->amountOn($posting, 1010, 'credit'));
        // And the withheld part is income, not a smaller cash balance.
        $this->assertSame(123_456, $this->amountOn($posting, 4030, 'credit'));
    }

    /** A product with no fees emits no fee line rather than a zero one. */
    public function test_a_release_with_nothing_withheld_emits_no_fee_line(): void
    {
        $posting = PostingRules::loanRelease(5_000_000, 5_000_000, 0, 'cash', $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(2, $posting['lines']);
        $this->assertSame(0, $this->amountOn($posting, 4030, 'credit'));
    }

    /**
     * Figures that do not reconcile are refused rather than posted.
     *
     * `net_proceeds` is maintained by repeated float subtraction in pesos across
     * computeDeductions() and applyInsuranceOnRelease(), and a CSV-imported or
     * hand-edited loan need not satisfy the identity at all. Saying which three
     * numbers disagree beats letting JournalPoster report an unexplained
     * difference between debits and credits.
     */
    public function test_a_release_whose_parts_do_not_add_up_to_the_principal_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/does not reconcile/');

        PostingRules::loanRelease(5_000_000, 4_876_544, 100_000, 'cash', $this->map());
    }

    public function test_a_release_settles_through_the_method_it_is_given(): void
    {
        $posting = PostingRules::loanRelease(100_000, 100_000, 0, 'bank', $this->map());

        $this->assertSame(100_000, $this->amountOn($posting, 1040, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 1010, 'credit'));
    }

    // ── loan_collection ──

    public function test_a_collection_splits_the_payment_across_its_components(): void
    {
        $posting = PostingRules::loanCollection(
            received: 500_000,
            principal: 300_000,
            interest: 150_000,
            penalty: 50_000,
            fees: 0,
            overpayment: 0,
            method: 'cash',
            map: $this->map(),
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('loan_collection', $posting['source']);
        $this->assertSame(500_000, $this->amountOn($posting, 1010, 'debit'));
        $this->assertSame(300_000, $this->amountOn($posting, 1110, 'credit'));
        $this->assertSame(150_000, $this->amountOn($posting, 4010, 'credit'));
        $this->assertSame(50_000, $this->amountOn($posting, 4020, 'credit'));
    }

    /**
     * Penalty is credited to income at COLLECTION, which is the whole of the
     * cash-basis choice. There is no accrual event and none should be added
     * without also adding the reversing entry a cash-basis book does not need.
     */
    public function test_a_payment_without_penalty_emits_no_penalty_line(): void
    {
        $posting = PostingRules::loanCollection(
            received: 450_000,
            principal: 300_000,
            interest: 150_000,
            penalty: 0,
            fees: 0,
            overpayment: 0,
            method: 'cash',
            map: $this->map(),
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(3, $posting['lines']);
        $this->assertSame(0, $this->amountOn($posting, 4020, 'credit'));
    }

    /**
     * THE SECOND DIVERGENCE from the frontend spec.
     *
     * `PaymentAllocation` has no overpayment field, so the frontend rule debits
     * cash with the allocated part only. `amount_paid` is not bounded by what is
     * owed, so the drawer would be understated by every excess peso — and the
     * excess is money the organisation is HOLDING, not money it has earned, so
     * it is a liability rather than income.
     */
    public function test_an_overpayment_is_held_as_a_liability_not_taken_as_income(): void
    {
        $posting = PostingRules::loanCollection(
            received: 600_000,
            principal: 300_000,
            interest: 150_000,
            penalty: 0,
            fees: 0,
            overpayment: 150_000,
            method: 'cash',
            map: $this->map(),
        );

        $this->assertBalancedAndNonEmpty($posting);
        // Every peso that arrived is in the drawer.
        $this->assertSame(600_000, $this->amountOn($posting, 1010, 'debit'));
        // The excess sits in a liability, not in any income account.
        $this->assertSame(150_000, $this->amountOn($posting, 2300, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 4010, 'credit') - 150_000);
    }

    public function test_a_payment_that_does_not_reconcile_with_its_parts_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/does not reconcile/');

        PostingRules::loanCollection(
            received: 600_000,
            principal: 300_000,
            interest: 150_000,
            penalty: 0,
            fees: 0,
            overpayment: 0,
            method: 'cash',
            map: $this->map(),
        );
    }

    /**
     * Validating the SUM is not validating the PARTS.
     *
     * `{principal: 400000, interest: -100000}` sums to a clean 300000 and would
     * post a 300000 debit against a 400000 credit and a -100000 credit, which
     * balances arithmetically and is nonsense as bookkeeping.
     */
    public function test_a_negative_component_is_refused_even_when_the_total_is_positive(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        PostingRules::loanCollection(
            received: 300_000,
            principal: 400_000,
            interest: -100_000,
            penalty: 0,
            fees: 0,
            overpayment: 0,
            method: 'cash',
            map: $this->map(),
        );
    }

    // ── The other rules ──

    public function test_a_transfer_moves_money_without_touching_income(): void
    {
        $posting = PostingRules::fundTransfer(250_000, 'gcash', 'bank', $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('transfer', $posting['source']);
        $this->assertSame(250_000, $this->amountOn($posting, 1040, 'debit'));
        $this->assertSame(250_000, $this->amountOn($posting, 1020, 'credit'));

        // The mistake this rule exists to prevent: a sweep reported as revenue
        // inflates the income statement by the entire amount swept.
        foreach ($posting['lines'] as $line) {
            $this->assertNotSame(4010, $line['account_id']);
            $this->assertNotSame(4070, $line['account_id']);
        }
    }

    public function test_a_transfer_into_the_same_account_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/same account/');

        PostingRules::fundTransfer(250_000, 'bank', 'bank', $this->map());
    }

    public function test_a_wallet_charge_is_an_expense_paid_out_of_that_wallet(): void
    {
        $posting = PostingRules::walletCharge(1_500, 'gcash', 5080, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(1_500, $this->amountOn($posting, 5080, 'debit'));
        $this->assertSame(1_500, $this->amountOn($posting, 1020, 'credit'));
    }

    public function test_a_standalone_fee_is_recognised_as_income_when_collected(): void
    {
        $posting = PostingRules::loanFee(75_000, 'cash', $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('loan_fee', $posting['source']);
        $this->assertSame(75_000, $this->amountOn($posting, 1010, 'debit'));
        $this->assertSame(75_000, $this->amountOn($posting, 4030, 'credit'));
    }

    /**
     * The allowance is a CONTRA asset, so crediting it reduces net loans
     * receivable without touching the gross figure the loan module reports.
     */
    public function test_a_provision_charges_expense_against_the_allowance(): void
    {
        $posting = PostingRules::creditLossProvision(1_000_000, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('credit_loss', $posting['source']);
        $this->assertSame(1_000_000, $this->amountOn($posting, 5140, 'debit'));
        $this->assertSame(1_000_000, $this->amountOn($posting, 1200, 'credit'));
        // Gross loans receivable is untouched; that is what "contra" buys.
        $this->assertSame(0, $this->amountOn($posting, 1110, 'credit'));
    }

    public function test_the_expense_rules_exist_for_the_stream_that_owns_the_tables(): void
    {
        $cash = PostingRules::expenseCash(120_000, 5020, 'bank', $this->map());
        $this->assertBalancedAndNonEmpty($cash);
        $this->assertSame(120_000, $this->amountOn($cash, 1040, 'credit'));

        $accrual = PostingRules::expenseAccrual(120_000, 5020, $this->map());
        $this->assertBalancedAndNonEmpty($accrual);
        $this->assertSame(120_000, $this->amountOn($accrual, 2010, 'credit'));

        // Settling the liability books no second expense.
        $settle = PostingRules::payablePayment(120_000, 'bank', $this->map());
        $this->assertBalancedAndNonEmpty($settle);
        $this->assertSame(120_000, $this->amountOn($settle, 2010, 'debit'));
        $this->assertSame(0, $this->amountOn($settle, 5020, 'debit'));
    }

    // ── Failing closed ──

    /**
     * An unmapped role stops the posting; it does not skip the line.
     *
     * Skipping would produce an entry that cannot balance, and JournalPoster
     * would then report a difference in debits and credits — a message that
     * names neither the role nor the setting that is missing.
     */
    public function test_an_unmapped_role_refuses_the_posting_and_names_the_role(): void
    {
        $incomplete = AccountMap::fromArray(['loans_receivable' => 1110, 'cash' => 1010]);

        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/processing_fee_income/');

        PostingRules::loanRelease(5_000_000, 4_876_544, 123_456, 'cash', $incomplete);
    }

    /**
     * A role behind a ZERO leg is never resolved, so it need not be mapped.
     *
     * PHP evaluates every element of an array literal before the array exists,
     * so building the lines and THEN filtering the zero ones resolves roles for
     * legs that are never emitted. That made the set of roles a posting
     * REQUIRES larger than the set it USES — and silently, since every test
     * with a complete map stays green.
     *
     * The consequence was not theoretical: an ordinary collection with no
     * penalty, no fees and no overpayment demanded all three of those roles, so
     * a deployment that adopted accounting before `borrower_advances` existed
     * would have had every repayment refused rather than only the overpaying
     * ones.
     */
    public function test_a_role_behind_a_zero_leg_need_not_be_mapped(): void
    {
        // Exactly the roles an ordinary collection USES, and nothing more.
        $minimal = AccountMap::fromArray([
            'cash' => 1010,
            'loans_receivable' => 1110,
            'interest_income' => 4010,
        ]);

        $posting = PostingRules::loanCollection(
            received: 450_000,
            principal: 300_000,
            interest: 150_000,
            penalty: 0,
            fees: 0,
            overpayment: 0,
            method: 'cash',
            map: $minimal,
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(3, $posting['lines']);
    }

    /** The same, for a release that withholds nothing. */
    public function test_a_zero_deduction_release_need_not_map_the_fee_account(): void
    {
        $minimal = AccountMap::fromArray(['cash' => 1010, 'loans_receivable' => 1110]);

        $posting = PostingRules::loanRelease(5_000_000, 5_000_000, 0, 'cash', $minimal);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(2, $posting['lines']);
    }

    public function test_a_zero_amount_records_nothing_and_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/greater than zero/');

        PostingRules::loanFee(0, 'cash', $this->map());
    }

    public function test_an_amount_past_the_modules_exact_arithmetic_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/beyond any amount/');

        PostingRules::loanFee(9_007_199_254_740_993, 'cash', $this->map());
    }

    /**
     * A settlement role the module does not know about stops the posting.
     *
     * The lending method enum has already grown once (`auto_pay`, April), and
     * the next addition must refuse rather than quietly file the money under
     * cash — a collection posted to the wrong asset account still balances and
     * is only ever found by counting the drawer.
     */
    public function test_an_unknown_settlement_role_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/not a settlement account/');

        PostingRules::loanFee(75_000, 'crypto', $this->map());
    }
}
