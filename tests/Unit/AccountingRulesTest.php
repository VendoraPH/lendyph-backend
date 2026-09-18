<?php

namespace Tests\Unit;

use App\Models\AccountingAccount;
use App\Services\Accounting\AccountRules;
use Tests\TestCase;

/**
 * Account classification, ported from `src/lib/accounting/account.test.ts`.
 *
 * Both halves of the system classify accounts, and they must classify them the
 * same way: the browser decides what a picker may offer and the server decides
 * what may actually be written. A divergence would let a screen offer an
 * account the API then refuses, or worse, accept one it should not.
 *
 * `sortAccounts` has no port. Ordering happens in SQL here
 * (`AccountingAccount::scopeInCodeOrder`), because a paginated page has to be a
 * page of the same list the whole chart would produce — sorting a page after
 * the fact would only sort the fifteen rows it happened to receive. The
 * ordering is covered end to end in AccountingChartOfAccountsTest.
 *
 * No database: nothing here touches a connection.
 */
class AccountingRulesTest extends TestCase
{
    /** An unsaved chart row. Never persisted, so no connection is used. */
    private function account(array $overrides = []): AccountingAccount
    {
        return new AccountingAccount(array_merge([
            'code' => '1010',
            'name' => 'Cash on Hand',
            'type' => 'asset',
            'is_contra' => false,
            'parent_id' => null,
            'is_group' => false,
            'is_active' => true,
        ], $overrides));
    }

    public function test_derives_the_account_type_from_the_leading_digit(): void
    {
        $this->assertSame('asset', AccountRules::typeFromCode('1010'));
        $this->assertSame('liability', AccountRules::typeFromCode('2010'));
        $this->assertSame('equity', AccountRules::typeFromCode('3010'));
        $this->assertSame('income', AccountRules::typeFromCode('4010'));
        $this->assertSame('expense', AccountRules::typeFromCode('5010'));
    }

    public function test_an_unknown_code_range_has_no_type_rather_than_a_wrong_one(): void
    {
        $this->assertNull(AccountRules::typeFromCode('9010'));
        $this->assertNull(AccountRules::typeFromCode(''));
        $this->assertNull(AccountRules::typeFromCode('   '));
        $this->assertNull(AccountRules::typeFromCode('abc'));
    }

    public function test_assets_and_expenses_are_debit_normal_the_rest_credit_normal(): void
    {
        $this->assertSame('debit', AccountRules::normalBalanceFor('asset', false));
        $this->assertSame('debit', AccountRules::normalBalanceFor('expense', false));
        $this->assertSame('credit', AccountRules::normalBalanceFor('liability', false));
        $this->assertSame('credit', AccountRules::normalBalanceFor('equity', false));
        $this->assertSame('credit', AccountRules::normalBalanceFor('income', false));
    }

    public function test_a_contra_account_inverts_its_types_normal_side(): void
    {
        // Allowance for Credit Losses is an asset carrying a credit balance.
        $this->assertSame('credit', AccountRules::normalBalanceFor('asset', true));
        // A contra-income account (sales discounts) is debit-normal.
        $this->assertSame('debit', AccountRules::normalBalanceFor('income', true));
    }

    public function test_a_debit_normal_account_grows_with_debits(): void
    {
        $this->assertSame(300000, AccountRules::signedBalance('debit', 500000, 200000));
    }

    public function test_a_credit_normal_account_grows_with_credits(): void
    {
        $this->assertSame(300000, AccountRules::signedBalance('credit', 200000, 500000));
    }

    public function test_an_allowance_reduces_assets_instead_of_adding_to_them(): void
    {
        // The bug this guards: treating the allowance as a plain asset makes
        // Net Loans Receivable come out as Gross PLUS the allowance.
        $allowance = $this->account([
            'code' => '1200',
            'name' => 'Allowance for Credit Losses',
            'is_contra' => true,
        ]);

        $normalBalance = AccountRules::normalBalanceFor($allowance->type, $allowance->is_contra);

        // ₱150,000 credited into the allowance.
        $balance = AccountRules::signedBalance($normalBalance, 0, 15000000);
        $this->assertSame(15000000, $balance);

        $grossLoans = 500000000; // ₱5,000,000
        $this->assertSame(485000000, $grossLoans - $balance); // ₱4,850,000 net
    }

    public function test_a_balance_can_go_the_wrong_way_and_says_so_with_a_negative(): void
    {
        // An overdrawn cash account is a real condition and must not be hidden.
        $this->assertSame(-300000, AccountRules::signedBalance('debit', 100000, 400000));
    }

    public function test_balance_sheet_takes_assets_liabilities_and_equity(): void
    {
        $this->assertSame('balance_sheet', AccountRules::belongsToStatement('asset'));
        $this->assertSame('balance_sheet', AccountRules::belongsToStatement('liability'));
        $this->assertSame('balance_sheet', AccountRules::belongsToStatement('equity'));
    }

    public function test_income_statement_takes_income_and_expenses(): void
    {
        $this->assertSame('income_statement', AccountRules::belongsToStatement('income'));
        $this->assertSame('income_statement', AccountRules::belongsToStatement('expense'));
    }

    public function test_group_headers_cannot_be_posted_to(): void
    {
        // Posting to both "1100 Loans Receivable" and its child "1110 Current"
        // would count the same money twice in the subtree total.
        $this->assertFalse(AccountRules::isPostable($this->account(['is_group' => true])));
        $this->assertTrue(AccountRules::isPostable($this->account(['is_group' => false])));
    }

    public function test_an_inactive_account_cannot_be_posted_to(): void
    {
        $this->assertFalse(AccountRules::isPostable($this->account(['is_active' => false])));
    }

    /** The model exposes the same rule, so callers need not reach for the service. */
    public function test_the_model_agrees_with_the_rule(): void
    {
        $this->assertFalse($this->account(['is_group' => true])->isPostable());
        $this->assertTrue($this->account()->isPostable());
    }
}
