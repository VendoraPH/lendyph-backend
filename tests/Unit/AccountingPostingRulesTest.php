<?php

namespace Tests\Unit;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccountMapping;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingRules;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'service_fee_income' => 4040,
            'notarial_fees_payable' => 2030,
            'insurance_premium_payable' => 2040,
            'share_capital' => 3060,
            'other_fee_income' => 4080,
            'documentary_stamp_tax_payable' => 2230,
            'withholding_tax_payable' => 2210,
            'other_taxes_payable' => 2220,
            'gcash_service_income' => 4090,
            'loan_adjustments' => 1180,
            'unearned_restructure_income' => 1210,
            'net_surplus' => 3030,
            'suspense' => 2390,
        ]);
    }

    /**
     * A posting's lines without their descriptions, for exact comparison.
     *
     * @param  array{lines: list<array<string, mixed>>}  $posting
     * @return list<array{account_id: int, debit: int, credit: int}>
     */
    private function linesOf(array $posting): array
    {
        return array_map(
            static fn (array $line): array => ['account_id' => $line['account_id'], 'debit' => $line['debit'], 'credit' => $line['credit']],
            $posting['lines'],
        );
    }

    /** The amount on the line carrying a description, for the lines a role shares with another. */
    private function amountDescribed(array $posting, string $description, string $side): int
    {
        foreach ($posting['lines'] as $line) {
            if (($line['description'] ?? null) === $description) {
                return $line[$side];
            }
        }

        return 0;
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

    // ── loan_release: each deduction booked by its type ──

    /**
     * A processing fee item goes to the account mapped for processing fees.
     *
     * The type is the item's NAME, normalised the way FeeOverlapDetector and
     * the Income report normalise it, so "PROCESSING-FEE." is the same type and
     * "Processing Fee Waiver" is not.
     */
    public function test_a_processing_fee_item_is_credited_to_processing_fee_income(): void
    {
        $posting = PostingRules::loanRelease(
            5_000_000, 4_876_544, 123_456, 'cash', $this->map(),
            items: [['name' => 'PROCESSING-FEE.', 'amount' => 123_456]],
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(3, $posting['lines']);
        $this->assertSame(123_456, $this->amountOn($posting, 4030, 'credit'));

        $deductions = PostingRules::classifyDeductions(123_456, [['name' => 'PROCESSING-FEE.', 'amount' => 123_456]]);

        $this->assertSame(['processing_fee_income' => 123_456], $deductions['mapped']);
        $this->assertSame([], $deductions['unmapped']);
        $this->assertSame(0, $deductions['remainder']);
        $this->assertSame('processing_fee_income', PostingRules::deductionRoleFor('Processing Fee'));
        $this->assertNull(PostingRules::deductionRoleFor('Processing Fee Waiver'));
    }

    /**
     * Each deduction type the accountant confirmed on 2026-10-03 is credited
     * to its own role, items of one type summed whatever their spelling.
     */
    public function test_each_deduction_type_is_credited_to_its_own_account(): void
    {
        $items = [
            ['name' => 'Processing Fee', 'amount' => 100_000],
            ['name' => 'Service Fee', 'amount' => 50_000],
            ['name' => 'Notarial Fee', 'amount' => 20_000],
            ['name' => 'Insurance Premium', 'amount' => 30_000],
            ['name' => 'Share Capital', 'amount' => 40_000],
            ['name' => 'service fee', 'amount' => 5_000],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_755_000, 245_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(100_000, $this->amountOn($posting, 4030, 'credit'));
        $this->assertSame(55_000, $this->amountOn($posting, 4040, 'credit'));
        $this->assertSame(20_000, $this->amountOn($posting, 2030, 'credit'));
        $this->assertSame(30_000, $this->amountOn($posting, 2040, 'credit'));
        $this->assertSame(40_000, $this->amountOn($posting, 3060, 'credit'));

        $deductions = PostingRules::classifyDeductions(245_000, $items);

        $this->assertSame([
            'processing_fee_income' => 100_000,
            'service_fee_income' => 55_000,
            'notarial_fees_payable' => 20_000,
            'insurance_premium_payable' => 30_000,
            'share_capital' => 40_000,
        ], $deductions['mapped']);
        $this->assertSame([], $deductions['unmapped']);
        $this->assertSame(['amount' => 55_000, 'role' => 'service_fee_income'], $deductions['types']['service fee']);
        $this->assertSame('notarial_fees_payable', PostingRules::deductionRoleFor('NOTARIAL FEE'));
        $this->assertSame('share_capital', PostingRules::deductionRoleFor('Share Capital'));
        $this->assertNull(PostingRules::deductionRoleFor('Share Capital Build-Up'));
    }

    /**
     * A fee from the Settings fee catalog (its item carries a `fee_id`) whose
     * name is no type of its own goes to Other Fee Income; one named like a
     * typed fee goes to that type's account.
     */
    public function test_a_catalog_fee_is_credited_to_other_fee_income_unless_its_name_is_a_type(): void
    {
        $items = [
            ['name' => 'Credit Investigation Fee', 'amount' => 30_000, 'catalog_fee' => true],
            ['name' => 'Service Fee', 'amount' => 20_000, 'catalog_fee' => true],
            ['name' => 'Documentary Stamp', 'amount' => 10_000],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_940_000, 60_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(30_000, $this->amountOn($posting, 4080, 'credit'));
        $this->assertSame(20_000, $this->amountOn($posting, 4040, 'credit'));
        // A hand-typed Documentary Stamp is a tax: 2230, no longer 4030.
        $this->assertSame(10_000, $this->amountOn($posting, 2230, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 4030, 'credit'));

        $deductions = PostingRules::classifyDeductions(60_000, $items);

        $this->assertSame(['other_fee_income' => 30_000, 'service_fee_income' => 20_000, 'documentary_stamp_tax_payable' => 10_000], $deductions['mapped']);
        $this->assertSame(['service_fee_income' => 20_000, 'documentary_stamp_tax_payable' => 10_000, 'other_fee_income' => 30_000], $deductions['credits']);
        $this->assertSame([], $deductions['unmapped']);
        $this->assertSame(['amount' => 30_000, 'role' => 'other_fee_income'], $deductions['types']['credit investigation fee']);
    }

    /**
     * A type with no account of its own is booked where every deduction was
     * booked before: processing fee income. No account is guessed for it by
     * its name or by any code.
     */
    public function test_a_type_without_an_account_is_booked_to_processing_fee_income(): void
    {
        $items = [
            ['name' => 'Processing Fee', 'amount' => 100_000],
            ['name' => 'Inspection Fee', 'amount' => 50_000],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_850_000, 150_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(3, $posting['lines']);
        $this->assertSame(150_000, $this->amountOn($posting, 4030, 'credit'));

        $deductions = PostingRules::classifyDeductions(150_000, $items);

        $this->assertSame(['processing_fee_income' => 100_000], $deductions['mapped']);
        $this->assertSame(['inspection fee' => 50_000], $deductions['unmapped']);
        $this->assertSame(['amount' => 50_000, 'role' => null], $deductions['types']['inspection fee']);
        $this->assertSame(['processing_fee_income' => 150_000], $deductions['credits']);
    }

    /**
     * A Documentary Stamp Tax is a government levy the cooperative collects
     * and owes on, so it is credited to 2230 Documentary Stamp Tax Payable,
     * hand-typed or from the fee catalog. Recognised as the disclosure
     * recognises a tax: the type is exactly "dst" or contains "documentary
     * stamp" (decision 4b, 2026-11-02).
     */
    public function test_a_documentary_stamp_tax_is_credited_to_documentary_stamp_tax_payable(): void
    {
        $items = [
            ['name' => 'Documentary Stamp Tax', 'amount' => 30_000, 'catalog_fee' => true],
            ['name' => 'DST', 'amount' => 10_000],
            ['name' => 'documentary-stamp', 'amount' => 5_000],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_955_000, 45_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(3, $posting['lines']);
        $this->assertSame(45_000, $this->amountOn($posting, 2230, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 4030, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 4080, 'credit'));

        $deductions = PostingRules::classifyDeductions(45_000, $items);

        $this->assertSame(['documentary_stamp_tax_payable' => 45_000], $deductions['mapped']);
        $this->assertSame([], $deductions['unmapped']);
        $this->assertSame(['amount' => 10_000, 'role' => 'documentary_stamp_tax_payable'], $deductions['types']['dst']);
        $this->assertSame('documentary_stamp_tax_payable', PostingRules::taxRoleFor('D.S.T.'));
    }

    /**
     * Any other tax (a type containing "tax") is credited to 2220 Other Taxes
     * Payable, hand-typed or from the fee catalog.
     */
    public function test_another_tax_is_credited_to_other_taxes_payable(): void
    {
        $items = [
            ['name' => 'Local Business Tax', 'amount' => 20_000, 'catalog_fee' => true],
            ['name' => 'Other Taxes', 'amount' => 10_000],
            ['name' => 'Service Fee', 'amount' => 5_000],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_965_000, 35_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(30_000, $this->amountOn($posting, 2220, 'credit'));
        $this->assertSame(5_000, $this->amountOn($posting, 4040, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 4080, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 4030, 'credit'));

        $deductions = PostingRules::classifyDeductions(35_000, $items);

        $this->assertSame(['service_fee_income' => 5_000, 'other_taxes_payable' => 30_000], $deductions['credits']);
        $this->assertSame('other_taxes_payable', PostingRules::taxRoleFor('Other Taxes'));
        $this->assertNull(PostingRules::taxRoleFor('Credit Investigation Fee'));
    }

    /**
     * A chart without an account for a tax role books that tax where it went
     * before, rather than refusing the release: a catalog fee on Other Fee
     * Income, a hand-typed deduction on processing fee income. The release
     * logs a warning for it (AccountingReleaseDeductionAccountsTest).
     */
    public function test_a_tax_whose_role_the_chart_has_no_account_for_is_booked_where_it_went_before(): void
    {
        $roles = $this->map()->all();
        unset($roles['documentary_stamp_tax_payable'], $roles['other_taxes_payable']);
        $map = AccountMap::fromArray($roles);

        $items = [
            ['name' => 'Documentary Stamp Tax', 'amount' => 30_000, 'catalog_fee' => true],
            ['name' => 'DST', 'amount' => 10_000],
            ['name' => 'Other Taxes', 'amount' => 5_000],
        ];

        $deductions = PostingRules::classifyDeductions(45_000, $items, 'LN-000042', $map);

        $this->assertSame(['other_fee_income' => 30_000, 'processing_fee_income' => 15_000], $deductions['credits']);
        $this->assertSame(['dst' => 10_000, 'other taxes' => 5_000], $deductions['unmapped']);
        $this->assertSame(
            [
                ['type' => 'documentary stamp tax', 'role' => 'documentary_stamp_tax_payable', 'booked_to' => 'other_fee_income'],
                ['type' => 'dst', 'role' => 'documentary_stamp_tax_payable', 'booked_to' => 'processing_fee_income'],
                ['type' => 'other taxes', 'role' => 'other_taxes_payable', 'booked_to' => 'processing_fee_income'],
            ],
            PostingRules::taxAccountFallbacks($items, $map),
        );
        $this->assertSame([], PostingRules::taxAccountFallbacks($items, $this->map()));
    }

    /**
     * Without 2230 (or 2210), a Documentary Stamp Tax (or a withholding tax)
     * goes to Other Taxes Payable when the chart has that, so it stays a
     * liability; only without that too does it go back to income.
     */
    public function test_a_tax_without_its_own_account_falls_back_to_other_taxes_payable_first(): void
    {
        $roles = $this->map()->all();
        unset($roles['documentary_stamp_tax_payable'], $roles['withholding_tax_payable']);
        $map = AccountMap::fromArray($roles);

        $items = [
            ['name' => 'Documentary Stamp Tax', 'amount' => 30_000, 'catalog_fee' => true],
            ['name' => 'DST', 'amount' => 10_000],
            ['name' => 'Withholding Tax', 'amount' => 5_000],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_955_000, 45_000, 'cash', $map, items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(3, $posting['lines']);
        $this->assertSame(45_000, $this->amountOn($posting, 2220, 'credit'));
        $this->assertSame(
            [
                ['type' => 'documentary stamp tax', 'role' => 'documentary_stamp_tax_payable', 'booked_to' => 'other_taxes_payable'],
                ['type' => 'dst', 'role' => 'documentary_stamp_tax_payable', 'booked_to' => 'other_taxes_payable'],
                ['type' => 'withholding tax', 'role' => 'withholding_tax_payable', 'booked_to' => 'other_taxes_payable'],
            ],
            PostingRules::taxAccountFallbacks($items, $map),
        );
    }

    public function test_a_withholding_tax_is_credited_to_withholding_tax_payable(): void
    {
        $items = [['name' => 'Withholding Tax', 'amount' => 12_000], ['name' => 'EWT Withholding', 'amount' => 3_000, 'catalog_fee' => true]];

        $posting = PostingRules::loanRelease(5_000_000, 4_985_000, 15_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(15_000, $this->amountOn($posting, 2210, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 2220, 'credit'));
    }

    /**
     * Deduction names and the tax role each is recognised as, on whole words.
     * The same matcher decides the disclosure (DisclosureFinanceChargesTest).
     *
     * @return array<string, array{string, string|null}>
     */
    public static function taxNames(): array
    {
        return [
            'DST' => ['DST', 'documentary_stamp_tax_payable'],
            'D.S.T.' => ['D.S.T.', 'documentary_stamp_tax_payable'],
            'Documentary Stamp Tax' => ['Documentary Stamp Tax', 'documentary_stamp_tax_payable'],
            'Documentary Stamps' => ['Documentary Stamps', 'documentary_stamp_tax_payable'],
            'Doc Stamp' => ['Doc Stamp', 'documentary_stamp_tax_payable'],
            'DST on loan' => ['DST on loan', 'documentary_stamp_tax_payable'],
            'Withholding Tax' => ['Withholding Tax', 'withholding_tax_payable'],
            'Withholding Taxes' => ['Withholding Taxes', 'withholding_tax_payable'],
            'EWT' => ['EWT', 'withholding_tax_payable'],
            'CWT 2%' => ['CWT 2%', 'withholding_tax_payable'],
            'CBU Withholding' => ['CBU Withholding', null],
            'Savings Withholding' => ['Savings Withholding', null],
            'Tax-exempt Charge' => ['Tax-exempt Charge', null],
            'Tax Exempt Fee' => ['Tax Exempt Fee', null],
            'Non-tax Charge' => ['Non-tax Charge', null],
            'Non Tax Fee' => ['Non Tax Fee', null],
            'VAT' => ['VAT', null],
            'Local Business Tax' => ['Local Business Tax', 'other_taxes_payable'],
            'Other Taxes' => ['Other Taxes', 'other_taxes_payable'],
            'TAX' => ['TAX', 'other_taxes_payable'],
            'Taxi Fare' => ['Taxi Fare', null],
            'Tax Clearance Fee' => ['Tax Clearance Fee', null],
            'Non-taxable Charge' => ['Non-taxable Charge', null],
            'Syntax Review Fee' => ['Syntax Review Fee', null],
            'Taxation Seminar' => ['Taxation Seminar', null],
            'Documentary Fee' => ['Documentary Fee', null],
            'Distribution Fee' => ['Distribution Fee', null],
            'Processing Fee' => ['Processing Fee', null],
        ];
    }

    #[DataProvider('taxNames')]
    public function test_the_tax_matcher_reads_whole_words(string $name, ?string $role): void
    {
        $this->assertSame($role, PostingRules::taxRoleFor($name));
        $this->assertSame($role, PostingRules::taxRoleFor(PostingRules::deductionType($name)), 'the type reads the same as the name');
    }

    /**
     * Hand-typed taxes now have an account of their own, so ones that add up
     * to more than the loan withheld are refused like any other typed item,
     * rather than crediting 2230 with money the loan does not show was kept.
     */
    public function test_hand_typed_taxes_above_the_total_deductions_are_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/LN-000042.*₱1,600\.00.*₱1,500\.00/s');

        PostingRules::loanRelease(
            5_000_000, 4_850_000, 150_000, 'cash', $this->map(),
            items: [
                ['name' => 'Processing Fee', 'amount' => 100_000],
                ['name' => 'DST', 'amount' => 60_000],
            ],
            loan: 'LN-000042',
        );
    }

    /**
     * A hand-typed tax whose amount cannot be read is set aside and booked as
     * before, as it was while it had no account of its own: the old rule
     * posted these loans. A catalog tax with no usable amount is refused, as
     * any catalog fee already was.
     */
    public function test_a_hand_typed_tax_without_a_usable_amount_is_set_aside_and_booked_as_before(): void
    {
        $items = [
            ['name' => 'Processing Fee', 'amount' => 100_000],
            ['name' => 'Documentary Stamp', 'amount' => null],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_850_000, 150_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(150_000, $this->amountOn($posting, 4030, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 2230, 'credit'));
        $this->assertSame([['type' => 'documentary stamp', 'reason' => 'no usable amount']], PostingRules::classifyDeductions(150_000, $items)['unusable']);

        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/LN-000042.*"DST"/s');

        PostingRules::classifyDeductions(150_000, [['name' => 'DST', 'amount' => null, 'catalog_fee' => true]], 'LN-000042');
    }

    /**
     * Whatever part of the total no item explains is booked as today, too —
     * an imported loan with a total and no item list included.
     */
    public function test_the_unitemised_remainder_is_booked_exactly_as_before(): void
    {
        $partly = PostingRules::loanRelease(
            5_000_000, 4_850_000, 150_000, 'cash', $this->map(),
            items: [['name' => 'Processing Fee', 'amount' => 100_000]],
        );
        $none = PostingRules::loanRelease(5_000_000, 4_850_000, 150_000, 'cash', $this->map(), items: []);

        foreach ([$partly, $none] as $posting) {
            $this->assertBalancedAndNonEmpty($posting);
            $this->assertCount(3, $posting['lines']);
            $this->assertSame(150_000, $this->amountOn($posting, 4030, 'credit'));
        }

        $this->assertSame(50_000, PostingRules::classifyDeductions(150_000, [['name' => 'Processing Fee', 'amount' => 100_000]])['remainder']);
        $this->assertSame(150_000, PostingRules::classifyDeductions(150_000, [])['remainder']);
    }

    /**
     * Items with an account of their own that add up to MORE than was
     * withheld cannot all be booked where they belong without crediting more
     * than the loan's deductions — so the release is refused, naming the
     * figures, rather than one of them being quietly trimmed.
     */
    public function test_mapped_items_that_exceed_the_total_deductions_are_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/LN-000042.*₱2,000\.00.*₱1,500\.00/s');

        PostingRules::loanRelease(
            5_000_000, 4_850_000, 150_000, 'cash', $this->map(),
            items: [
                ['name' => 'Processing Fee', 'amount' => 120_000],
                ['name' => 'processing fee', 'amount' => 80_000],
            ],
            loan: 'LN-000042',
        );
    }

    /**
     * Unmapped items above the total are not refused: they and the remainder
     * are booked as today, which credits the whole total to processing fee
     * income — the figure the loan records as withheld.
     */
    public function test_unmapped_items_above_the_total_are_booked_as_before(): void
    {
        $posting = PostingRules::loanRelease(
            5_000_000, 4_850_000, 150_000, 'cash', $this->map(),
            items: [
                ['name' => 'Processing Fee', 'amount' => 100_000],
                ['name' => 'Inspection Fee', 'amount' => 90_000],
            ],
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(150_000, $this->amountOn($posting, 4030, 'credit'));
        $this->assertSame(-40_000, PostingRules::classifyDeductions(150_000, [
            ['name' => 'Processing Fee', 'amount' => 100_000],
            ['name' => 'Inspection Fee', 'amount' => 90_000],
        ])['remainder']);
    }

    /**
     * A negative item with an account of its own is refused, naming the loan
     * and the figure: direction belongs to the column, not the sign.
     */
    public function test_a_negative_mapped_item_is_refused_naming_the_loan(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/LN-000042.*"Processing Fee".*-₱100\.00/s');

        PostingRules::loanRelease(
            5_000_000, 4_960_000, 40_000, 'cash', $this->map(),
            items: [
                ['name' => 'Processing Fee', 'amount' => -10_000],
                ['name' => 'Service Fee', 'amount' => 50_000],
            ],
            loan: 'LN-000042',
        );
    }

    /**
     * An item amount that is not whole, non-negative centavos — a peso float,
     * a string, nothing at all, a negative, or past the module's ceiling.
     *
     * @return array<string, array{mixed}>
     */
    public static function unusableItemAmounts(): array
    {
        return [
            'pesos as a float' => [1500.00],
            'a decimal string' => ['1500.00'],
            'missing' => [null],
            'negative' => [-10_000],
            'past the ceiling' => [9_007_199_254_740_993],
        ];
    }

    /**
     * On an item with an account of its own, an unusable amount refuses the
     * release and names the loan: reading it as zero would book its share as
     * something else.
     */
    #[DataProvider('unusableItemAmounts')]
    public function test_a_mapped_item_without_a_usable_amount_is_refused(mixed $amount): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/LN-000042.*"Processing Fee"/s');

        PostingRules::classifyDeductions(150_000, [['name' => 'Processing Fee', 'amount' => $amount]], 'LN-000042');
    }

    /**
     * On an item with NO account of its own, the same amounts are not a
     * refusal: the old rule posted these loans, and so does this one. The item
     * is set aside — listed, so it stays visible — and its money is part of the
     * remainder, booked exactly as before.
     */
    #[DataProvider('unusableItemAmounts')]
    public function test_an_unmapped_item_without_a_usable_amount_is_set_aside_and_booked_as_before(mixed $amount): void
    {
        $items = [
            ['name' => 'Processing Fee', 'amount' => 100_000],
            ['name' => 'Inspection Fee', 'amount' => $amount],
        ];

        $posting = PostingRules::loanRelease(5_000_000, 4_850_000, 150_000, 'cash', $this->map(), items: $items);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertCount(3, $posting['lines']);
        $this->assertSame(150_000, $this->amountOn($posting, 4030, 'credit'));

        $deductions = PostingRules::classifyDeductions(150_000, $items);

        $this->assertSame([], $deductions['unmapped']);
        $this->assertArrayNotHasKey('inspection fee', $deductions['types']);
        $this->assertCount(1, $deductions['unusable']);
        $this->assertSame('inspection fee', $deductions['unusable'][0]['type']);
        $this->assertSame(50_000, $deductions['remainder']);
    }

    /** Something in the list that is not an item at all is set aside the same way. */
    public function test_an_entry_that_is_not_an_item_is_set_aside_and_booked_as_before(): void
    {
        $items = [['name' => 'Processing Fee', 'amount' => 100_000], 'Service Fee 500', 500, null];

        $posting = PostingRules::loanRelease(5_000_000, 4_850_000, 150_000, 'cash', $this->map(), items: $items);

        $this->assertSame(150_000, $this->amountOn($posting, 4030, 'credit'));

        $deductions = PostingRules::classifyDeductions(150_000, $items);

        $this->assertCount(3, $deductions['unusable']);
        $this->assertSame(['type' => '', 'reason' => 'not an item'], $deductions['unusable'][0]);
        $this->assertSame(50_000, $deductions['remainder']);
    }

    /**
     * Every deduction type maps to a role the mapping screen actually offers,
     * so a typo here cannot send a fee to a role nobody can ever set — and the
     * table's keys are already in normal form, or they would never match.
     */
    public function test_every_deduction_role_is_a_real_posting_role(): void
    {
        foreach (PostingRules::DEDUCTION_ROLES as $type => $role) {
            $this->assertContains($role, AccountingAccountMapping::ROLES, "\"{$type}\" maps to an unknown role.");
            $this->assertSame($type, PostingRules::deductionType($type), "\"{$type}\" is not normalised.");
        }

        $this->assertContains(PostingRules::UNMAPPED_DEDUCTION_ROLE, AccountingAccountMapping::ROLES);
        $this->assertContains(PostingRules::CATALOG_FEE_ROLE, AccountingAccountMapping::ROLES);
    }

    /**
     * The lines a release posts for typical item lists: debit the gross,
     * credit the net, then each role its share in a fixed order — processing
     * fee income (with whatever no item explains), service fee income,
     * notarial fees payable, insurance premium payable, share capital,
     * documentary stamp tax payable, withholding tax payable, other taxes
     * payable, other fee income.
     *
     * @return array<string, array{int, int, int, list<array<string, mixed>>, list<array{int, int}>}>
     */
    public static function typicalItemLists(): array
    {
        return [
            'product fees' => [5_000_000, 4_850_000, 150_000, [
                ['name' => 'Processing Fee', 'amount' => 100_000],
                ['name' => 'Service Fee', 'amount' => 25_000],
                ['name' => 'Notarial Fee', 'amount' => 25_000],
            ], [[4030, 100_000], [4040, 25_000], [2030, 25_000]]],
            'product fees, catalog fee and insurance' => [5_000_000, 4_795_000, 205_000, [
                ['name' => 'Processing Fee', 'amount' => 100_000],
                ['name' => 'Service Fee', 'amount' => 25_000],
                ['name' => 'Notarial Fee', 'amount' => 25_000],
                ['name' => 'Credit Investigation Fee', 'amount' => 5_000, 'catalog_fee' => true],
                ['name' => 'Insurance Premium', 'amount' => 50_000],
            ], [[4030, 100_000], [4040, 25_000], [2030, 25_000], [2040, 50_000], [4080, 5_000]]],
            'taxes, hand-typed and catalog' => [5_000_000, 4_930_000, 70_000, [
                ['name' => 'Processing Fee', 'amount' => 30_000],
                ['name' => 'Documentary Stamp Tax', 'amount' => 15_000, 'catalog_fee' => true],
                ['name' => 'DST', 'amount' => 10_000],
                ['name' => 'Local Business Tax', 'amount' => 5_000],
                ['name' => 'Withholding Tax', 'amount' => 2_000],
                ['name' => 'Credit Investigation Fee', 'amount' => 8_000, 'catalog_fee' => true],
            ], [[4030, 30_000], [2230, 25_000], [2210, 2_000], [2220, 5_000], [4080, 8_000]]],
            'processing fee only' => [5_000_000, 4_876_544, 123_456, [
                ['name' => 'Processing Fee', 'amount' => 123_456],
            ], [[4030, 123_456]]],
            'no processing fee' => [1_000_000, 990_000, 10_000, [
                ['name' => 'Notarial Fee', 'amount' => 10_000],
            ], [[2030, 10_000]]],
            'a zero processing fee' => [1_000_000, 990_000, 10_000, [
                ['name' => 'Processing Fee', 'amount' => 0],
                ['name' => 'Service Fee', 'amount' => 10_000],
            ], [[4040, 10_000]]],
            'an unitemised remainder after a typed fee' => [1_000_000, 980_000, 20_000, [
                ['name' => 'Share Capital', 'amount' => 15_000],
            ], [[3060, 15_000], [4030, 5_000]]],
            'no item list' => [1_000_000, 980_000, 20_000, [], [[4030, 20_000]]],
            'nothing withheld' => [1_000_000, 1_000_000, 0, [], []],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{int, int}>  $credits  account id => credit, in line order
     */
    #[DataProvider('typicalItemLists')]
    public function test_release_lines_book_each_type_to_its_account(int $gross, int $net, int $deductions, array $items, array $credits): void
    {
        $posting = PostingRules::loanRelease($gross, $net, $deductions, 'cash', $this->map(), items: $items);

        $expected = [
            ['account_id' => 1110, 'debit' => $gross, 'credit' => 0],
            ['account_id' => 1010, 'debit' => 0, 'credit' => $net],
        ];

        foreach ($credits as [$accountId, $amount]) {
            $expected[] = ['account_id' => $accountId, 'debit' => 0, 'credit' => $amount];
        }

        $this->assertSame($expected, $posting['lines']);
        $this->assertBalancedAndNonEmpty($posting);
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

    // ── loan_collection: share capital build-up (Phase 2, decision 5) ──

    /**
     * A build-up payment's extra is the member's share capital, so it is
     * credited to 3060 Share Capital, not held on 2300 as an overpayment.
     */
    public function test_a_build_up_payment_credits_share_capital_not_other_liabilities(): void
    {
        $posting = PostingRules::loanCollection(
            received: 1_255_000,
            principal: 1_000_000,
            interest: 180_000,
            penalty: 0,
            fees: 0,
            overpayment: 75_000,
            method: 'cash',
            map: $this->map(),
            shareCapital: 75_000,
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(1_255_000, $this->amountOn($posting, 1010, 'debit'));
        $this->assertSame(75_000, $this->amountOn($posting, 3060, 'credit'));
        $this->assertSame(0, $this->amountOn($posting, 2300, 'credit'));
    }

    public function test_an_overpayment_beyond_the_build_up_stays_on_other_liabilities(): void
    {
        $posting = PostingRules::loanCollection(
            received: 1_280_000,
            principal: 1_000_000,
            interest: 180_000,
            penalty: 0,
            fees: 0,
            overpayment: 100_000,
            method: 'cash',
            map: $this->map(),
            shareCapital: 75_000,
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(75_000, $this->amountOn($posting, 3060, 'credit'));
        $this->assertSame(25_000, $this->amountOn($posting, 2300, 'credit'));
    }

    public function test_a_build_up_larger_than_the_overpayment_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/share capital/');

        PostingRules::loanCollection(
            received: 1_255_000,
            principal: 1_000_000,
            interest: 180_000,
            penalty: 0,
            fees: 0,
            overpayment: 75_000,
            method: 'cash',
            map: $this->map(),
            shareCapital: 75_001,
        );
    }

    // ── Restructure into a new loan (Phase 2, decisions 2 and 3) ──

    /** UP ₱40,000, UI ₱5,000, UPen ₱2,000, P ₱46,000: all principal carried, interest first. */
    public function test_a_restructure_split_carries_principal_then_capitalises_interest_before_penalty(): void
    {
        $this->assertSame([
            'carried_principal' => 4_000_000,
            'capitalised_interest' => 500_000,
            'capitalised_penalty' => 100_000,
            'written_off_principal' => 0,
        ], PostingRules::restructureSplit(4_000_000, 500_000, 200_000, 4_600_000));
    }

    /** CP = P: a new principal below the unpaid principal capitalises nothing and writes off the rest. */
    public function test_a_restructure_split_below_the_unpaid_principal_writes_the_difference_off(): void
    {
        $this->assertSame([
            'carried_principal' => 4_500_000,
            'capitalised_interest' => 0,
            'capitalised_penalty' => 0,
            'written_off_principal' => 500_000,
        ], PostingRules::restructureSplit(5_000_000, 300_000, 100_000, 4_500_000));
    }

    /** WP = 0 and C = 0: a new principal equal to the unpaid principal. */
    public function test_a_restructure_split_at_exactly_the_unpaid_principal_carries_it_all(): void
    {
        $this->assertSame([
            'carried_principal' => 5_000_000,
            'capitalised_interest' => 0,
            'capitalised_penalty' => 0,
            'written_off_principal' => 0,
        ], PostingRules::restructureSplit(5_000_000, 300_000, 100_000, 5_000_000));
    }

    public function test_a_restructure_split_above_everything_owed_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/more than/');

        PostingRules::restructureSplit(5_000_000, 300_000, 100_000, 5_400_001);
    }

    /**
     * J1: Dr 1110 P / Dr m D / Cr 1110 CP / Cr 1210 C / Cr each deduction's
     * role. No cash line for principal: nothing is paid out.
     */
    public function test_a_restructure_release_carries_principal_and_defers_the_capitalised_part(): void
    {
        $posting = PostingRules::restructureRelease(
            principal: 4_600_000,
            carried: 4_000_000,
            capitalisedInterest: 500_000,
            capitalisedPenalty: 100_000,
            deductions: 50_000,
            method: 'cash',
            map: $this->map(),
            items: [['name' => 'Processing Fee', 'amount' => 50_000]],
            loan: 'LA-000020',
            source: 'LN-000010',
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('loan_release', $posting['source']);
        $this->assertSame([
            ['account_id' => 1110, 'debit' => 4_600_000, 'credit' => 0],
            ['account_id' => 1010, 'debit' => 50_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 4_000_000],
            ['account_id' => 1210, 'debit' => 0, 'credit' => 600_000],
            ['account_id' => 4030, 'debit' => 0, 'credit' => 50_000],
        ], $this->linesOf($posting));
        $this->assertSame('Principal carried from LN-000010', $posting['lines'][2]['description']);
    }

    /** CP = P with nothing withheld: the principal only moves loans; no cash, no deferral. */
    public function test_a_restructure_release_that_only_carries_principal_needs_no_other_account(): void
    {
        $posting = PostingRules::restructureRelease(
            principal: 4_500_000,
            carried: 4_500_000,
            capitalisedInterest: 0,
            capitalisedPenalty: 0,
            deductions: 0,
            method: 'cash',
            map: AccountMap::fromArray(['loans_receivable' => 1110]),
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame([
            ['account_id' => 1110, 'debit' => 4_500_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 4_500_000],
        ], $this->linesOf($posting));
    }

    public function test_a_restructure_release_collects_its_deductions_through_the_method_given(): void
    {
        $posting = PostingRules::restructureRelease(4_600_000, 4_000_000, 500_000, 100_000, 50_000, 'bank', $this->map());

        $this->assertSame(50_000, $this->amountOn($posting, 1040, 'debit'));
        $this->assertSame(0, $this->amountOn($posting, 1010, 'debit'));
    }

    public function test_a_restructure_release_whose_split_does_not_add_up_to_the_principal_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/does not reconcile/');

        PostingRules::restructureRelease(4_600_000, 4_000_000, 500_000, 99_999, 0, 'cash', $this->map());
    }

    /** J2 with no deferral on the source: the allowance first, then expense. */
    public function test_a_restructure_write_off_uses_the_allowance_before_expense(): void
    {
        $posting = PostingRules::restructureWriteOff(500_000, 0, 200_000, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('credit_loss', $posting['source']);
        $this->assertSame([
            ['account_id' => 1200, 'debit' => 200_000, 'credit' => 0],
            ['account_id' => 5140, 'debit' => 300_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 500_000],
        ], $this->linesOf($posting));
    }

    /** A restructure of a restructure: the source's own unrecognised deferral is written off first. */
    public function test_a_restructure_write_off_takes_the_sources_own_deferral_first(): void
    {
        $posting = PostingRules::restructureWriteOff(500_000, 150_000, 1_000_000, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame([
            ['account_id' => 1210, 'debit' => 150_000, 'credit' => 0],
            ['account_id' => 1200, 'debit' => 350_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 500_000],
        ], $this->linesOf($posting));
        $this->assertSame(PostingRules::DEFERRAL_WRITTEN_OFF_LINE, $posting['lines'][0]['description']);
    }

    public function test_a_restructure_write_off_smaller_than_the_deferral_needs_no_allowance(): void
    {
        $posting = PostingRules::restructureWriteOff(
            500_000,
            800_000,
            1_000_000,
            AccountMap::fromArray(['loans_receivable' => 1110, 'unearned_restructure_income' => 1210]),
        );

        $this->assertSame([
            ['account_id' => 1210, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 500_000],
        ], $this->linesOf($posting));
    }

    /** An allowance carrying a debit balance has nothing to absorb a loss with. */
    public function test_a_restructure_write_off_against_an_allowance_in_debit_is_all_expense(): void
    {
        $posting = PostingRules::restructureWriteOff(500_000, 0, -40_000, $this->map());

        $this->assertSame([
            ['account_id' => 5140, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 500_000],
        ], $this->linesOf($posting));
    }

    // ── Deferral recognition on the new loan's collections ──

    /**
     * ₱7,000 interest and ₱3,000 penalty capitalised into a ₱60,000 loan,
     * collected ₱10,000 of principal at a time: each collection recognises
     * its share, the cumulative figure is never off by more than rounding, and
     * the last one finishes exactly at the capitalised amounts.
     */
    public function test_deferral_is_recognised_in_proportion_and_finishes_exactly(): void
    {
        $interest = 0;
        $penalty = 0;
        $steps = [];

        for ($k = 1; $k <= 6; $k++) {
            $x = PostingRules::deferralRecognition(700_000, 300_000, 6_000_000, $k * 1_000_000, $k === 6, $interest, $penalty);
            $steps[] = [$x['interest'], $x['penalty']];
            $interest += $x['interest'];
            $penalty += $x['penalty'];
        }

        $this->assertSame([
            [116_667, 50_000],
            [116_666, 50_000],
            [116_667, 50_000],
            [116_667, 50_000],
            [116_666, 50_000],
            [116_667, 50_000],
        ], $steps);
        $this->assertSame([700_000, 300_000], [$interest, $penalty]);
        $this->assertSame(
            ['interest' => 0, 'penalty' => 0],
            PostingRules::deferralRecognition(700_000, 300_000, 6_000_000, 6_000_000, true, $interest, $penalty),
            'nothing is left to recognise once it has all been recognised',
        );
    }

    /** Once no period is open, all the rest is recognised, whatever principal was collected. */
    public function test_deferral_is_recognised_in_full_when_the_loan_has_no_open_period(): void
    {
        $this->assertSame(
            ['interest' => 583_333, 'penalty' => 250_000],
            PostingRules::deferralRecognition(700_000, 300_000, 6_000_000, 4_000_000, true, 116_667, 50_000),
        );
    }

    /** After a voided collection's recognition is reversed, the next one catches up to the target. */
    public function test_deferral_after_a_void_catches_up_to_the_principal_collected(): void
    {
        // Two collections recognised 116,667 + 50,000 and 116,666 + 50,000;
        // the second was voided, so only the first stands. Principal paid is
        // back to ₱20,000 with the next payment.
        $this->assertSame(
            ['interest' => 116_666, 'penalty' => 50_000],
            PostingRules::deferralRecognition(700_000, 300_000, 6_000_000, 2_000_000, false, 116_667, 50_000),
        );
    }

    public function test_a_loan_with_nothing_capitalised_recognises_nothing(): void
    {
        $this->assertSame(
            ['interest' => 0, 'penalty' => 0],
            PostingRules::deferralRecognition(0, 0, 6_000_000, 3_000_000, false, 0, 0),
        );
    }

    /** Exact at sizes where `capitalised × principal paid` passes PHP's integer range. */
    public function test_deferral_proportion_is_exact_for_large_figures(): void
    {
        // ₱100,000,000.00 capitalised in a ₱500,000,000.00 loan, ₱123,456,789.01 collected:
        // 10,000,000,000 × 12,345,678,901 ÷ 50,000,000,000 = 2,469,135,780.2.
        $x = PostingRules::deferralRecognition(10_000_000_000, 0, 50_000_000_000, 12_345_678_901, false, 0, 0);

        $this->assertSame(['interest' => 2_469_135_780, 'penalty' => 0], $x);
    }

    /** What remains after recognition and after a write-off or clearing that took part of it. */
    public function test_the_remaining_deferral_splits_a_release_in_the_ratio_still_owed(): void
    {
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], PostingRules::deferralRemaining(700_000, 300_000, 116_667, 50_000, 0));
        $this->assertSame(['interest' => 0, 'penalty' => 0], PostingRules::deferralRemaining(700_000, 300_000, 116_667, 50_000, 833_333));
        $this->assertSame(['interest' => 291_667, 'penalty' => 125_000], PostingRules::deferralRemaining(700_000, 300_000, 116_667, 50_000, 416_666));
    }

    /** Dr 1210 / Cr 4010 and 4020, as their own lines beside the collection's own interest and penalty. */
    public function test_a_collection_on_a_restructured_loan_recognises_its_deferral(): void
    {
        $posting = PostingRules::loanCollection(
            received: 1_180_000,
            principal: 1_000_000,
            interest: 180_000,
            penalty: 0,
            fees: 0,
            overpayment: 0,
            method: 'cash',
            map: $this->map(),
            deferredInterest: 116_667,
            deferredPenalty: 50_000,
        );

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame(166_667, $this->amountOn($posting, 1210, 'debit'));
        $this->assertSame(166_667, $this->amountDescribed($posting, PostingRules::DEFERRAL_RECOGNISED_LINE, 'debit'));
        $this->assertSame(116_667, $this->amountDescribed($posting, PostingRules::DEFERRED_INTEREST_LINE, 'credit'));
        $this->assertSame(50_000, $this->amountDescribed($posting, PostingRules::DEFERRED_PENALTY_LINE, 'credit'));
        // The collection's own interest is still its own line.
        $this->assertSame(180_000, $this->amountOn($posting, 4010, 'credit'));
    }

    // ── Balance adjustments (decision 7) ──

    public function test_a_balance_reduction_clears_through_loan_adjustments(): void
    {
        $posting = PostingRules::balanceAdjustment(-250_000, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('adjustment', $posting['source']);
        $this->assertSame([
            ['account_id' => 1180, 'debit' => 250_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 250_000],
        ], $this->linesOf($posting));
    }

    public function test_a_balance_increase_is_the_mirror(): void
    {
        $posting = PostingRules::balanceAdjustment(250_000, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame([
            ['account_id' => 1110, 'debit' => 250_000, 'credit' => 0],
            ['account_id' => 1180, 'debit' => 0, 'credit' => 250_000],
        ], $this->linesOf($posting));
    }

    /** A reduction that completes a restructured loan moves the deferral it still holds out of 1210. */
    public function test_a_reduction_that_completes_a_loan_clears_its_remaining_deferral(): void
    {
        $posting = PostingRules::balanceAdjustment(-1_000_000, $this->map(), deferralCleared: 166_667);

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame([
            ['account_id' => 1180, 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => 1110, 'debit' => 0, 'credit' => 1_000_000],
            ['account_id' => 1210, 'debit' => 166_667, 'credit' => 0],
            ['account_id' => 1180, 'debit' => 0, 'credit' => 166_667],
        ], $this->linesOf($posting));
        $this->assertSame(PostingRules::DEFERRAL_CLEARED_LINE, $posting['lines'][2]['description']);
    }

    public function test_an_increase_never_clears_a_deferral(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);

        PostingRules::balanceAdjustment(250_000, $this->map(), deferralCleared: 1);
    }

    public function test_a_zero_balance_adjustment_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);
        $this->expectExceptionMessageMatches('/greater than zero/');

        PostingRules::balanceAdjustment(0, $this->map());
    }

    // ── Share capital entries (decision 5) ──

    public function test_a_share_capital_credit_is_money_in_to_share_capital(): void
    {
        $posting = PostingRules::shareCapitalEntry(500_000, 0, 'cash', $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('share_capital', $posting['source']);
        $this->assertSame([
            ['account_id' => 1010, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => 3060, 'debit' => 0, 'credit' => 500_000],
        ], $this->linesOf($posting));
    }

    public function test_a_share_capital_debit_is_money_out_of_share_capital(): void
    {
        $posting = PostingRules::shareCapitalEntry(0, 200_000, 'bank', $this->map());

        $this->assertSame([
            ['account_id' => 3060, 'debit' => 200_000, 'credit' => 0],
            ['account_id' => 1040, 'debit' => 0, 'credit' => 200_000],
        ], $this->linesOf($posting));
    }

    /** @return array<string, array{int, int}> */
    public static function unpostableShareCapitalRows(): array
    {
        return ['both sides' => [100, 100], 'neither side' => [0, 0], 'negative' => [-100, 0]];
    }

    #[DataProvider('unpostableShareCapitalRows')]
    public function test_a_share_capital_row_on_both_sides_or_neither_is_refused(int $credit, int $debit): void
    {
        $this->expectException(CannotPostToTheBooksException::class);

        PostingRules::shareCapitalEntry($credit, $debit, 'cash', $this->map());
    }

    // ── GCash (decision 6) ──

    public function test_a_cash_in_takes_cash_sends_gcash_and_earns_the_charge(): void
    {
        $posting = PostingRules::gcashTransaction('cash_in', 500_000, 2_000, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('cash_in', $posting['source']);
        $this->assertSame([
            ['account_id' => 1010, 'debit' => 502_000, 'credit' => 0],
            ['account_id' => 1020, 'debit' => 0, 'credit' => 500_000],
            ['account_id' => 4090, 'debit' => 0, 'credit' => 2_000],
        ], $this->linesOf($posting));
    }

    public function test_a_cash_out_takes_gcash_pays_cash_less_the_charge(): void
    {
        $posting = PostingRules::gcashTransaction('cash_out', 500_000, 2_000, $this->map());

        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame('cash_out', $posting['source']);
        $this->assertSame([
            ['account_id' => 1020, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => 1010, 'debit' => 0, 'credit' => 498_000],
            ['account_id' => 4090, 'debit' => 0, 'credit' => 2_000],
        ], $this->linesOf($posting));
    }

    /** A ₱0.00 charge has no income line, and needs no income account. */
    public function test_a_gcash_transaction_without_a_charge_has_no_income_line(): void
    {
        $map = AccountMap::fromArray(['cash' => 1010, 'gcash' => 1020]);

        foreach (['cash_in', 'cash_out'] as $type) {
            $posting = PostingRules::gcashTransaction($type, 500_000, 0, $map);

            $this->assertBalancedAndNonEmpty($posting);
            $this->assertCount(2, $posting['lines'], $type);
        }
    }

    public function test_a_cash_out_whose_charge_is_the_whole_amount_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);

        PostingRules::gcashTransaction('cash_out', 2_000, 2_000, $this->map());
    }

    public function test_an_unknown_gcash_type_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);

        PostingRules::gcashTransaction('refund', 500_000, 0, $this->map());
    }

    // ── Allowance (decision 12) ──

    public function test_a_positive_allowance_adjustment_provides_and_a_negative_one_releases(): void
    {
        $up = PostingRules::allowanceAdjustment(300_000, $this->map());
        $down = PostingRules::allowanceAdjustment(-300_000, $this->map());

        $this->assertBalancedAndNonEmpty($up);
        $this->assertSame('credit_loss', $up['source']);
        $this->assertSame([
            ['account_id' => 5140, 'debit' => 300_000, 'credit' => 0],
            ['account_id' => 1200, 'debit' => 0, 'credit' => 300_000],
        ], $this->linesOf($up));
        $this->assertSame([
            ['account_id' => 1200, 'debit' => 300_000, 'credit' => 0],
            ['account_id' => 5140, 'debit' => 0, 'credit' => 300_000],
        ], $this->linesOf($down));
    }

    public function test_a_zero_allowance_adjustment_is_refused(): void
    {
        $this->expectException(CannotPostToTheBooksException::class);

        PostingRules::allowanceAdjustment(0, $this->map());
    }

    /**
     * Posted journals carry these exact strings and a loan's deferral is read
     * back by them, so a reworded constant would recognise it twice.
     */
    public function test_the_deferral_line_markers_never_change(): void
    {
        $this->assertSame('Unearned interest and penalty recognised', PostingRules::DEFERRAL_RECOGNISED_LINE);
        $this->assertSame('Restructured interest recognised', PostingRules::DEFERRED_INTEREST_LINE);
        $this->assertSame('Restructured penalty recognised', PostingRules::DEFERRED_PENALTY_LINE);
        $this->assertSame('Unearned interest and penalty written off', PostingRules::DEFERRAL_WRITTEN_OFF_LINE);
        $this->assertSame('Unearned interest and penalty cleared', PostingRules::DEFERRAL_CLEARED_LINE);
    }

    /** Every role the new rules ask for is a declared posting role with a shape. */
    public function test_every_new_role_is_declared_with_a_shape(): void
    {
        foreach (['gcash_service_income', 'loan_adjustments', 'unearned_restructure_income', 'net_surplus', 'suspense'] as $role) {
            $this->assertContains($role, AccountingAccountMapping::ROLES);
            $this->assertArrayHasKey($role, AccountingAccountMapping::ROLE_SHAPES);
        }

        $this->assertSame(['type' => 'asset', 'is_contra' => true], AccountingAccountMapping::ROLE_SHAPES['unearned_restructure_income']);
        $this->assertSame(array_values(AccountingAccountMapping::ROLES), array_keys(AccountingAccountMapping::ROLE_SHAPES));
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
