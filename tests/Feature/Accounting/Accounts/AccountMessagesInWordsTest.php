<?php

namespace Tests\Feature\Accounting\Accounts;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\ResolvesPostingAccounts;
use App\Services\Accounting\SettlementMethod;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Accounting messages name posting roles, account types and money-account
 * kinds in words, never by their stored keys ("Accounts payable", "an asset
 * account", "a GCash wallet"), and the net surplus account can't be renamed
 * onto the opening balance equity code.
 */
class AccountMessagesInWordsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    // ── Posting roles in words ──────────────────────────────────────────────

    public function test_an_unmapped_role_on_an_expense_is_named_in_words(): void
    {
        AccountingAccountMapping::query()->where('role', 'accounts_payable')->delete();

        $resolver = new class
        {
            use ResolvesPostingAccounts;

            public function resolve(string $role): AccountingAccount
            {
                return $this->requirePostingRole($role, 'An expense recorded as owed');
            }
        };

        try {
            $resolver->resolve('accounts_payable');
            $this->fail('An unmapped role should be refused.');
        } catch (ValidationException $e) {
            $message = $e->errors()['account_mapping'][0];
        }

        $this->assertStringContainsString('the Accounts payable account', $message);
        $this->assertStringNotContainsString('accounts_payable', $message);
    }

    public function test_an_unmapped_role_in_the_account_map_is_named_in_words(): void
    {
        try {
            AccountMap::fromArray([])->accountFor('insurance_premium_payable');
            $this->fail('An unmapped role should be refused.');
        } catch (CannotPostToTheBooksException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('the Insurance premium payable account', $message);
        $this->assertStringNotContainsString('insurance_premium_payable', $message);
    }

    public function test_a_role_that_is_not_a_settlement_account_is_named_in_words_with_the_list(): void
    {
        try {
            SettlementMethod::assertIsSettlementRole('loans_receivable');
            $this->fail('A non-settlement role should be refused.');
        } catch (CannotPostToTheBooksException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame(
            'Loans receivable is not a settlement account. Money can only move through Cash, GCash, Maya and Bank.',
            $message,
        );
    }

    // ── Role-shape messages ─────────────────────────────────────────────────

    public function test_a_wrong_type_names_both_types_in_words(): void
    {
        $message = AccountingAccountMapping::roleMismatch('cash', '4010 Interest Income', 'income', false, null)['message'];

        $this->assertStringStartsWith('4010 Interest Income is an income account, but the Cash role has to resolve to an asset account.', $message);
    }

    public function test_a_money_account_kind_on_a_non_money_role_is_named_in_words(): void
    {
        $message = AccountingAccountMapping::roleMismatch('loans_receivable', '1110 Loans Receivable', 'asset', false, 'gcash')['message'];

        $this->assertStringContainsString('marked as a GCash wallet', $message);
        $this->assertStringNotContainsString('cash_kind', $message);
        $this->assertStringNotContainsString('gcash account', $message);
    }

    public function test_the_wrong_money_account_kind_is_named_in_words(): void
    {
        $message = AccountingAccountMapping::roleMismatch('gcash', '1040 Bank Accounts', 'asset', false, 'bank')['message'];

        $this->assertStringStartsWith('1040 Bank Accounts is a bank account, but the GCash role has to resolve to a GCash wallet.', $message);
        $this->assertStringNotContainsString('cash_kind', $message);
        $this->assertStringNotContainsString('cash kind', $message);

        $none = AccountingAccountMapping::roleMismatch('maya', '1110 Loans Receivable', 'asset', false, null)['message'];

        $this->assertStringStartsWith('1110 Loans Receivable is not a money account, but the Maya role has to resolve to a Maya wallet.', $none);
    }

    public function test_re_typing_a_mapped_account_answers_in_words(): void
    {
        $this->putJson('/api/accounting/accounts/'.$this->account('1010'), ['cash_kind' => 'maya'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.cash_kind.0',
                fn (string $message): bool => str_contains($message, '1010 Cash on Hand is a Maya wallet, but the Cash role has to resolve to a cash account.')
                    && ! str_contains($message, 'cash_kind'),
            );
    }

    public function test_a_non_asset_money_account_is_refused_in_words(): void
    {
        $this->postJson('/api/accounting/accounts', [
            'code' => '2990',
            'name' => 'Wallet Float',
            'type' => 'liability',
            'parent_id' => $this->account('2000'),
            'cash_kind' => 'gcash',
        ])->assertStatus(422)->assertJsonPath(
            'errors.cash_kind.0',
            fn (string $message): bool => str_starts_with($message, 'A liability account cannot be a money account.')
                && ! str_contains($message, 'cash_kind'),
        );
    }

    public function test_a_code_of_the_wrong_type_is_refused_in_words(): void
    {
        $this->postJson('/api/accounting/accounts', [
            'code' => '4990',
            'name' => 'Misfiled',
            'type' => 'asset',
            'parent_id' => $this->account('1000'),
        ])->assertStatus(422)->assertJsonPath(
            'errors.code.0',
            'Account code 4990 is an income code, so it can\'t be an asset account. The leading digit decides which statement this account reports on.',
        );
    }

    // ── The net surplus account and the 3050 code ───────────────────────────

    public function test_the_net_surplus_account_cannot_take_the_opening_balance_equity_code(): void
    {
        $netSurplus = AccountingAccount::query()->findOrFail(AccountingAccountMapping::query()->where('role', 'net_surplus')->value('accounting_account_id'));
        $this->putJson('/api/accounting/accounts/'.$this->account('3050'), ['code' => '3059'])->assertOk();

        $this->putJson('/api/accounting/accounts/'.$netSurplus->id, ['code' => '3050'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.code.0',
                "{$netSurplus->code} {$netSurplus->name} is the account for Retained earnings or net surplus, and 3050 is the opening balance equity code: "
                ."the year's result would be mixed into the opening balances. Keep another code, or point that role somewhere else first.",
            );

        $this->assertNotSame('3050', $netSurplus->fresh()->code);
    }

    public function test_another_equity_account_can_still_take_a_free_code(): void
    {
        $openingEquity = $this->account('3050');

        $this->putJson('/api/accounting/accounts/'.$openingEquity, ['code' => '3059'])->assertOk();
        $this->putJson('/api/accounting/accounts/'.$openingEquity, ['code' => '3050'])->assertOk();
    }
}
