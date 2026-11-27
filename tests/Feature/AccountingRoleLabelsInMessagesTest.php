<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Round B browser check: deactivating 1180 in Chart of Accounts answered
 * "1180 Loan Adjustments Clearing is the account for loan_adjustments", a
 * stored key on screen. Every message that names a posting role reads it in
 * the Accounting Settings screen's words instead.
 */
class AccountingRoleLabelsInMessagesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function assertNamesTheRoleInWords(?string $message, string $label, string $key): void
    {
        $this->assertNotNull($message);
        $this->assertStringContainsString($label, $message);
        $this->assertStringNotContainsString($key, $message);
    }

    public function test_deactivating_a_mapped_account_names_the_role_in_words(): void
    {
        $message = $this->putJson('/api/accounting/accounts/'.$this->account('1180'), ['is_active' => false])
            ->assertUnprocessable()
            ->json('errors.is_active.0');

        $this->assertNamesTheRoleInWords($message, 'Loan adjustments clearing', 'loan_adjustments');
        $this->assertStringContainsString('1180 Loan Adjustments Clearing is the account for Loan adjustments clearing.', $message);
    }

    public function test_making_a_mapped_account_a_heading_names_the_role_in_words(): void
    {
        $message = $this->putJson('/api/accounting/accounts/'.$this->account('1180'), ['is_group' => true])
            ->assertUnprocessable()
            ->json('errors.is_group.0');

        $this->assertNamesTheRoleInWords($message, 'Loan adjustments clearing', 'loan_adjustments');
    }

    public function test_an_account_holding_several_roles_joins_them_in_words(): void
    {
        AccountingAccountMapping::query()
            ->where('role', 'interest_receivable')
            ->update(['accounting_account_id' => $this->account('1180')]);

        $message = $this->putJson('/api/accounting/accounts/'.$this->account('1180'), ['is_active' => false])
            ->assertUnprocessable()
            ->json('errors.is_active.0');

        $this->assertStringContainsString(
            'is the account for Interest receivable and Loan adjustments clearing.',
            $message,
        );
        $this->assertStringNotContainsString('_', $message);
    }

    public function test_deleting_a_mapped_account_names_the_role_in_words(): void
    {
        $message = $this->deleteJson('/api/accounting/accounts/'.$this->account('1180'))
            ->assertUnprocessable()
            ->json('errors.account.0');

        $this->assertNamesTheRoleInWords($message, 'Loan adjustments clearing', 'loan_adjustments');
        $this->assertTrue(AccountingAccount::whereKey($this->account('1180'))->exists());
    }

    public function test_changing_a_mapped_accounts_shape_names_the_role_in_words(): void
    {
        $message = $this->putJson('/api/accounting/accounts/'.$this->account('1180'), ['cash_kind' => 'cash'])
            ->assertUnprocessable()
            ->json('errors.cash_kind.0');

        $this->assertNamesTheRoleInWords($message, 'Loan adjustments clearing', 'loan_adjustments');
        $this->assertStringContainsString('is the account for Loan adjustments clearing.', $message);
        $this->assertStringContainsString('is the Loan adjustments clearing account', $message);
    }

    public function test_moving_a_mapped_account_names_the_role_in_words(): void
    {
        $account = AccountingAccount::findOrFail($this->account('1110'));

        $message = $this->putJson("/api/accounting/accounts/{$account->id}", [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'parent_id' => $this->account('1000'),
        ])->assertUnprocessable()->json('errors.parent_id.0');

        $this->assertNamesTheRoleInWords($message, 'Loans receivable', 'loans_receivable');
    }

    public function test_mapping_a_role_to_the_wrong_kind_of_account_names_the_role_in_words(): void
    {
        $message = $this->putJson('/api/accounting/settings/account-mapping', [
            'loan_adjustments' => $this->account('2390'),
        ])->assertUnprocessable()->json('errors.loan_adjustments.0');

        $this->assertNamesTheRoleInWords($message, 'the Loan adjustments clearing role', 'loan_adjustments');
    }

    public function test_every_role_has_a_label_in_words(): void
    {
        foreach (AccountingAccountMapping::ROLES as $role) {
            $this->assertArrayHasKey($role, AccountingAccountMapping::LABELS, "The {$role} role has no label.");
            $this->assertStringNotContainsString('_', AccountingAccountMapping::label($role));
        }

        $this->assertSame([], array_values(array_diff(
            array_keys(AccountingAccountMapping::LABELS),
            AccountingAccountMapping::ROLES,
        )), 'A label names a role that is not in the role list.');
    }

    public function test_labels_match_the_accounting_settings_screen(): void
    {
        $this->assertSame('Loan adjustments clearing', AccountingAccountMapping::label('loan_adjustments'));
        $this->assertSame('Member advances', AccountingAccountMapping::label('borrower_advances'));
        $this->assertSame('GCash', AccountingAccountMapping::label('gcash'));
        $this->assertSame('Retained earnings or net surplus', AccountingAccountMapping::label('net_surplus'));
    }

    public function test_an_unknown_role_reads_as_its_key_in_words(): void
    {
        $this->assertSame('Some new role', AccountingAccountMapping::label('some_NEW_role'));
    }

    public function test_a_list_of_roles_reads_in_words(): void
    {
        $this->assertSame('Suspense', AccountingAccountMapping::labelList(['suspense']));
        $this->assertSame(
            'Loan adjustments clearing and Suspense',
            AccountingAccountMapping::labelList(['suspense', 'loan_adjustments']),
        );
        $this->assertSame(
            'Cash, Bank and Suspense',
            AccountingAccountMapping::labelList(['suspense', 'bank', 'cash']),
        );
    }
}
