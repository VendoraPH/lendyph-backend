<?php

namespace Tests\Feature\Accounting\Accounts;

use App\Models\AccountingAccount;
use App\Models\AccountingExpense;
use App\Models\AccountingOpeningBalance;
use Tests\Feature\Accounting\OpeningBalances\OpeningBalanceTestHelpers;
use Tests\TestCase;

/**
 * Deleting an account: every refusal is a 422 in words, a reference the
 * checks don't list (a foreign key) answers the same 422 instead of a 500,
 * and an account on an opening balance draft that can no longer change is
 * told to be deactivated.
 */
class AccountDeleteTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();
    }

    public function test_a_reference_the_checks_do_not_list_answers_422_not_500(): void
    {
        AccountingExpense::factory()->create(['expense_account_id' => $this->account('5020')]);

        $this->deleteJson('/api/accounting/accounts/'.$this->account('5020'))
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', '5020 Rent is still in use elsewhere in the books and cannot be deleted. Deactivate it instead.');

        $this->assertTrue(AccountingAccount::query()->whereKey($this->account('5020'))->exists());
    }

    public function test_after_the_cli_posted_an_account_on_the_screen_draft_is_told_to_deactivate(): void
    {
        $this->save([
            ['account_id' => $this->account('1420'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 100],
        ])->assertOk();
        $this->postSimpleJournal('1010', '3050', 10_000, ['source' => 'opening_balance', 'branch_id' => null]);

        $this->deleteJson('/api/accounting/accounts/'.$this->account('1420'))
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', '1420 Computer Equipment is on the opening balance draft, which can no longer change. Deactivate it instead.');
    }

    public function test_an_account_on_a_finalised_draft_is_told_to_deactivate(): void
    {
        $this->save([
            ['account_id' => $this->account('1420'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 100],
        ])->assertOk();
        AccountingOpeningBalance::query()->update(['status' => AccountingOpeningBalance::STATUS_FINALISED]);

        $this->deleteJson('/api/accounting/accounts/'.$this->account('1420'))
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', '1420 Computer Equipment is on the opening balance draft, which can no longer change. Deactivate it instead.');
    }

    public function test_an_unused_account_still_deletes(): void
    {
        $this->deleteJson('/api/accounting/accounts/'.$this->account('1420'))->assertOk();

        $this->assertFalse(AccountingAccount::query()->whereKey($this->account('1420'))->exists());
    }

    public function test_the_journal_line_count_reads_in_the_singular_and_the_plural(): void
    {
        $this->postSimpleJournal('1420', '3050', 10_000);

        $this->deleteJson('/api/accounting/accounts/'.$this->account('1420'))
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', '1420 Computer Equipment has 1 journal line against it and cannot be deleted. Deactivate it instead — an account with history keeps its history.');

        $this->postSimpleJournal('1420', '3050', 10_000);
        $this->postSimpleJournal('3050', '1420', 5_000);

        $this->deleteJson('/api/accounting/accounts/'.$this->account('1420'))
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', '1420 Computer Equipment has 3 journal lines against it and cannot be deleted. Deactivate it instead — an account with history keeps its history.');
    }

    public function test_the_sub_account_count_reads_in_the_singular_and_the_plural(): void
    {
        $this->deleteJson('/api/accounting/accounts/'.$this->account('1400'))
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', fn (string $message): bool => str_ends_with($message, ' accounts beneath it. Move or remove them first.'));

        $heading = $this->postJson('/api/accounting/accounts', [
            'code' => '1600', 'name' => 'Other Assets', 'type' => 'asset', 'parent_id' => $this->account('1000'), 'is_group' => true,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/accounting/accounts', [
            'code' => '1610', 'name' => 'Deposits', 'type' => 'asset', 'parent_id' => $heading,
        ])->assertCreated();

        $this->deleteJson("/api/accounting/accounts/{$heading}")
            ->assertStatus(422)
            ->assertJsonPath('errors.account.0', '1600 Other Assets has 1 account beneath it. Move or remove it first.');
    }
}
