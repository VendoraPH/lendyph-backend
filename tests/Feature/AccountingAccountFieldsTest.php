<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The three `Account` fields only the journals module can answer, and the two
 * guards that only become reachable once journals exist.
 *
 * `balance`, `has_transactions` and `description` were all in
 * `src/types/accounting.ts` with nothing behind them: the first rendered ₱0.00
 * on every money account AND in the "total across all money accounts" card, the
 * second was simply absent, and the third was accepted by the API and silently
 * dropped at mass assignment. None of the three failed.
 */
class AccountingAccountFieldsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function accountJson(string $code): array
    {
        return $this->getJson('/api/accounting/accounts/'.$this->account($code))->assertOk()->json('data');
    }

    // ── balance ──

    public function test_an_account_carries_its_signed_balance_as_integer_centavos(): void
    {
        $this->postSimpleJournal('1010', '3010', 150050);

        $cash = $this->accountJson('1010');

        // An INTEGER, never a decimal string. A `decimal:2` cast serialises to
        // JSON as "1500.50", and `sumCentavos` on the frontend had to be
        // hardened against exactly that after a Cash & Bank total read ₱0.00
        // while every row beneath it formatted correctly.
        $this->assertSame(150050, $cash['balance']);
        $this->assertIsInt($cash['balance']);
    }

    public function test_a_balance_is_signed_in_the_accounts_own_normal_direction(): void
    {
        $this->postSimpleJournal('1010', '3010', 1000000);
        $this->postSimpleJournal('5030', '2010', 300000);

        // Cash is debit-normal and Accounts Payable is credit-normal, so both
        // read POSITIVE when they sit where they should. A raw debit-minus-
        // credit would report every liability and every income account as
        // negative.
        $this->assertSame(1000000, $this->accountJson('1010')['balance']);
        $this->assertSame(300000, $this->accountJson('2010')['balance']);
    }

    public function test_an_overdrawn_cash_account_reports_a_negative_balance(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000);
        $this->postSimpleJournal('5030', '1010', 40000);

        // A real condition, reported rather than clamped to zero. The trial
        // balance places the same figure in the credit column; here it is the
        // signed number the Cash & Bank card formats.
        $this->assertSame(-30000, $this->accountJson('1010')['balance']);
    }

    public function test_a_balance_counts_reversed_originals_so_a_reversed_pair_nets_out(): void
    {
        $this->postSimpleJournal('1010', '3010', 500000);
        $mistake = $this->postSimpleJournal('1010', '3010', 4000000);

        $this->postJson("/api/accounting/journals/{$mistake->id}/reverse")->assertCreated();

        // Counting only `posted` would leave the reversal in and its original
        // out: a cash balance of MINUS ₱35,000 on an account holding ₱5,000.
        $this->assertSame(500000, $this->accountJson('1010')['balance']);
    }

    public function test_an_account_that_was_never_posted_to_reports_zero_rather_than_nothing(): void
    {
        // "Never posted to" and "posted to and netted out" are the same balance
        // to a reader. An absent field would be read as "not asked for".
        $this->assertSame(0, $this->accountJson('1010')['balance']);
    }

    public function test_a_group_heading_reports_no_balance_of_its_own(): void
    {
        $this->postSimpleJournal('1110', '1010', 100000);

        // What a screen shows against a heading is the total of its subtree,
        // computed from the rows beneath it. Emitting 0 would be read as "this
        // heading holds nothing".
        $this->assertArrayNotHasKey('balance', $this->accountJson('1100'));
    }

    public function test_the_list_endpoint_carries_balances_and_agrees_with_the_trial_balance(): void
    {
        $this->postSimpleJournal('1010', '3010', 250000);

        $listed = collect($this->getJson('/api/accounting/accounts?per_page=100')->assertOk()->json('data'))
            ->keyBy('code');

        $trialBalance = collect($this->getJson('/api/accounting/trial-balance')->json('data.rows'))
            ->keyBy('account_code');

        // Same aggregate, same rows — which is the point of routing both
        // through TrialBalanceBuilder rather than writing a second query.
        $this->assertSame(250000, $listed['1010']['balance']);
        $this->assertSame($trialBalance['1010']['debit'], $listed['1010']['balance']);
    }

    // ── has_transactions ──

    public function test_has_transactions_flips_once_a_line_exists(): void
    {
        $this->assertFalse($this->accountJson('1010')['has_transactions']);

        $this->postSimpleJournal('1010', '3010', 100000);

        $this->assertTrue($this->accountJson('1010')['has_transactions']);
        $this->assertFalse($this->accountJson('5030')['has_transactions']);
    }

    public function test_a_draft_line_already_counts_as_a_transaction(): void
    {
        $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        // The foreign key restricts on ANY line, so a draft blocks the delete
        // exactly as a posted entry does. A `has_transactions` that ignored
        // drafts would tell the UI deletion is safe and then 500 on it.
        $this->assertTrue($this->accountJson('5030')['has_transactions']);

        $this->deleteJson('/api/accounting/accounts/'.$this->account('5030'))
            ->assertStatus(422);
    }

    public function test_the_list_endpoint_answers_has_transactions_without_going_n_plus_one(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $listed = collect($this->getJson('/api/accounting/accounts?per_page=100')->assertOk()->json('data'))
            ->keyBy('code');

        $this->assertTrue($listed['1010']['has_transactions']);
        $this->assertFalse($listed['5030']['has_transactions']);

        // A `withCount` plus the balances aggregate — not one `exists` per row,
        // which on a 69-account chart is 69 queries nobody would notice until
        // production.
        $this->assertLessThan(20, $queries, "A page of accounts ran {$queries} queries; that is an N+1.");
    }

    // ── description ──

    public function test_a_description_round_trips(): void
    {
        $note = 'Advances to field officers, settled against payroll.';

        $created = $this->postJson('/api/accounting/accounts', [
            'code' => '1180',
            'name' => 'Advances to Officers',
            'type' => 'asset',
            'parent_id' => $this->account('1000'),
            'description' => $note,
        ])->assertCreated();

        $this->assertSame($note, $created->json('data.description'));

        $id = $created->json('data.id');
        $this->assertSame($note, $this->getJson("/api/accounting/accounts/{$id}")->json('data.description'));

        $this->putJson("/api/accounting/accounts/{$id}", ['description' => 'Superseded by payroll module.'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Superseded by payroll module.');

        $this->putJson("/api/accounting/accounts/{$id}", ['description' => null])
            ->assertOk()
            ->assertJsonPath('data.description', null);
    }

    public function test_an_overlong_description_is_refused_rather_than_truncated(): void
    {
        $this->postJson('/api/accounting/accounts', [
            'code' => '1181',
            'name' => 'Too Wordy',
            'type' => 'asset',
            'description' => str_repeat('a', 501),
        ])->assertStatus(422)->assertJsonValidationErrors('description');
    }

    // ── The posting engine trusts the mapping table without question ──

    public function test_a_role_cannot_point_at_an_account_of_the_wrong_type(): void
    {
        // Nothing about this fails to balance. Every collection would credit
        // revenue twice and the cash would never appear, and every report would
        // render it without complaint.
        $this->putJson('/api/accounting/settings/account-mapping', [
            'cash' => $this->account('4010'),
        ])->assertStatus(422)->assertJsonValidationErrors('cash');

        $this->assertSame($this->account('1010'), AccountingAccountMapping::resolved()['cash']);
    }

    public function test_the_allowance_role_must_point_at_a_contra_account(): void
    {
        $plainAsset = AccountingAccount::query()->create([
            'code' => '1195',
            'name' => 'Ordinary Asset',
            'type' => 'asset',
            'is_contra' => false,
            'parent_id' => $this->account('1000'),
        ]);

        // A non-contra allowance is debit-normal, so Net Loans Receivable comes
        // out as gross PLUS the provision — overstating the portfolio by twice
        // the allowance, on the dashboard and the balance sheet at once.
        $this->putJson('/api/accounting/settings/account-mapping', [
            'allowance_credit_losses' => $plainAsset->id,
        ])->assertStatus(422)->assertJsonValidationErrors('allowance_credit_losses');
    }

    public function test_a_settlement_role_must_point_at_an_account_of_its_own_cash_kind(): void
    {
        // `cash_kind` is what puts an account on the Cash & Bank screen and
        // into the dashboard's cash figures. A gcash role resolving to an
        // account without one would take collections that never show up in the
        // money the organisation thinks it holds.
        $this->putJson('/api/accounting/settings/account-mapping', [
            'gcash' => $this->account('1110'),
        ])->assertStatus(422)->assertJsonValidationErrors('gcash');

        // And pointing it at the WRONG money account is refused too.
        $this->putJson('/api/accounting/settings/account-mapping', [
            'gcash' => $this->account('1040'),
        ])->assertStatus(422)->assertJsonValidationErrors('gcash');
    }

    public function test_every_default_mapping_satisfies_its_own_role_shape(): void
    {
        // The seeder and the validator have to agree, or a freshly seeded
        // organisation cannot save its own settings screen without changing
        // something first.
        $mapping = AccountingAccountMapping::resolved();

        $this->putJson('/api/accounting/settings/account-mapping', $mapping)->assertOk();
    }

    public function test_every_role_has_a_declared_shape(): void
    {
        // A role in ROLES with no entry in ROLE_SHAPES is refused outright by
        // the validator, so the two lists drifting would take the settings
        // screen down rather than quietly let one role point anywhere.
        $this->assertSame(
            AccountingAccountMapping::ROLES,
            array_keys(AccountingAccountMapping::ROLE_SHAPES),
        );
    }

    // ── The inverse door: re-shaping the account a role already points at ──

    public function test_the_contra_flag_of_a_mapped_account_cannot_be_dropped(): void
    {
        // VARIANT A. On a freshly seeded chart 1200 has no lines at all, so the
        // history guard does not fire — and the saving hook would re-derive
        // `normal_balance` to debit, making netReceivable() compute gross minus
        // a NEGATIVE allowance, i.e. gross PLUS the provision. Nothing fails to
        // balance; the dashboard simply overstates the portfolio.
        $this->putJson('/api/accounting/accounts/'.$this->account('1200'), [
            'is_contra' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('is_contra');

        $this->assertTrue(AccountingAccount::query()->find($this->account('1200'))->is_contra);
    }

    public function test_the_cash_kind_of_a_mapped_money_account_cannot_be_cleared(): void
    {
        // VARIANT B, which had no precondition at all — it worked with or
        // without history. AccountingDashboardBuilder filters the cash figures
        // on `cash_kind`, so collections would keep posting correctly to 1010
        // and silently stop appearing in money-on-hand.
        $this->postSimpleJournal('1010', '3010', 500000);

        $this->putJson('/api/accounting/accounts/'.$this->account('1010'), [
            'cash_kind' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('cash_kind');

        $this->assertSame('cash', AccountingAccount::query()->find($this->account('1010'))->cash_kind);

        // And swapping it for the wrong kind is refused too — a `cash` role
        // resolving to a `bank` account moves money between two real cards.
        $this->putJson('/api/accounting/accounts/'.$this->account('1010'), [
            'cash_kind' => 'bank',
        ])->assertStatus(422)->assertJsonValidationErrors('cash_kind');
    }

    public function test_a_mapped_account_cannot_be_reclassified_onto_another_statement(): void
    {
        // VARIANT C. Reclassification is legal for an unmapped account with no
        // history — `test_an_account_with_no_entries_may_still_be_reclassified`
        // relies on it — which is exactly what made this reachable.
        $this->putJson('/api/accounting/accounts/'.$this->account('1110'), [
            'code' => '4110',
            'type' => 'income',
            'parent_id' => $this->account('4000'),
        ])->assertStatus(422)->assertJsonValidationErrors('type');

        $this->assertSame('asset', AccountingAccount::query()->find($this->account('1110'))->type);
    }

    public function test_the_mapped_shape_guard_does_not_wait_for_history(): void
    {
        // The whole window before an organisation posts its first entry is when
        // it is configuring its books — precisely when this is reachable and
        // precisely when a history gate would be switched off.
        $this->assertFalse($this->accountJson('1200')['has_transactions']);

        $this->putJson('/api/accounting/accounts/'.$this->account('1200'), [
            'is_contra' => false,
        ])->assertStatus(422);
    }

    public function test_an_unmapped_account_is_still_free_to_be_re_shaped(): void
    {
        // The guard is about ROLES, not about accounts in general. 5030
        // Electricity resolves no role, so an administrator correcting it is
        // not blocked.
        $this->putJson('/api/accounting/accounts/'.$this->account('5030'), [
            'name' => 'Electricity and Water',
        ])->assertOk();

        $spare = AccountingAccount::query()->create([
            'code' => '1197',
            'name' => 'Spare Money Account',
            'type' => 'asset',
            'parent_id' => $this->account('1000'),
        ]);

        $this->putJson("/api/accounting/accounts/{$spare->id}", ['cash_kind' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('data.cash_kind', 'wallet');
    }

    public function test_both_doors_to_the_same_wrong_shape_are_shut(): void
    {
        // The two directions are one rule. Pointing the role at a bad account
        // and re-shaping the account under the role reach the same number, and
        // both need permissions the same principals hold.
        $plainAsset = AccountingAccount::query()->create([
            'code' => '1198',
            'name' => 'Ordinary Asset',
            'type' => 'asset',
            'parent_id' => $this->account('1000'),
        ]);

        $this->putJson('/api/accounting/settings/account-mapping', [
            'allowance_credit_losses' => $plainAsset->id,
        ])->assertStatus(422);

        $this->putJson('/api/accounting/accounts/'.$this->account('1200'), ['is_contra' => false])
            ->assertStatus(422);
    }

    // ── History keeps its sign ──

    public function test_the_type_of_an_account_with_entries_cannot_be_changed(): void
    {
        $this->postSimpleJournal('1010', '3010', 2000000);

        // `normal_balance` is derived from `type` and applied to lines recorded
        // years ago, so one PUT would re-sign every balance this account has
        // ever carried — and the ledger rows themselves would be untouched and
        // look perfectly ordinary.
        $this->putJson('/api/accounting/accounts/'.$this->account('1010'), [
            'code' => '4090',
            'type' => 'income',
        ])->assertStatus(422)->assertJsonValidationErrors('type');

        $this->assertSame('asset', AccountingAccount::query()->find($this->account('1010'))->type);
    }

    public function test_the_contra_flag_of_an_account_with_entries_cannot_be_changed(): void
    {
        $this->postSimpleJournal('5140', '1200', 500000);

        $this->putJson('/api/accounting/accounts/'.$this->account('1200'), [
            'is_contra' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('is_contra');

        $this->assertTrue(AccountingAccount::query()->find($this->account('1200'))->is_contra);
    }

    public function test_an_account_with_no_entries_may_still_be_reclassified(): void
    {
        // Nothing has been posted to it, so there is no history to re-sign and
        // an administrator correcting a mistake should not be blocked.
        $account = AccountingAccount::query()->create([
            'code' => '1196',
            'name' => 'Mis-typed',
            'type' => 'asset',
            'parent_id' => $this->account('1000'),
        ]);

        $this->putJson("/api/accounting/accounts/{$account->id}", [
            'code' => '5196',
            'type' => 'expense',
            'parent_id' => $this->account('5000'),
        ])->assertOk()->assertJsonPath('data.type', 'expense');
    }

    public function test_an_account_with_entries_may_still_be_renamed_and_deactivated(): void
    {
        $this->postSimpleJournal('5030', '1010', 100000);

        // Deactivation is the normal end of an account's life: it stops taking
        // new history while keeping what it has. Only the two fields that
        // re-interpret history are frozen.
        $this->putJson('/api/accounting/accounts/'.$this->account('5030'), [
            'name' => 'Electricity and Water',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    /**
     * Variant B inverted, and worse: instead of stripping `cash_kind` off a
     * money account so its balance vanishes, put one ON a non-money account so
     * its balance is counted as cash. Do it to `loans_receivable` and the whole
     * net loan portfolio reports as money in the bank.
     */
    public function test_a_mapped_non_money_account_cannot_be_made_a_money_account(): void
    {
        $receivable = AccountingAccount::where('code', '1110')->firstOrFail();

        $this->putJson("/api/accounting/accounts/{$receivable->id}", [
            'code' => $receivable->code,
            'name' => $receivable->name,
            'type' => $receivable->type,
            'cash_kind' => 'bank',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_kind');

        $this->assertNull($receivable->fresh()->cash_kind);
    }

    public function test_a_non_asset_account_cannot_be_a_money_account(): void
    {
        $income = AccountingAccount::where('code', '4010')->firstOrFail();

        $this->putJson("/api/accounting/accounts/{$income->id}", [
            'code' => $income->code,
            'name' => $income->name,
            'type' => 'income',
            'cash_kind' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_kind');
    }

    public function test_a_money_account_cannot_be_created_on_a_non_asset_type(): void
    {
        $expenses = AccountingAccount::where('code', '5000')->firstOrFail();

        $this->postJson('/api/accounting/accounts', [
            'code' => '5199',
            'name' => 'Not A Wallet',
            'type' => 'expense',
            'parent_id' => $expenses->id,
            'cash_kind' => 'gcash',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_kind');
    }
}
