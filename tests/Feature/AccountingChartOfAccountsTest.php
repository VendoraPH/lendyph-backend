<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The chart of accounts: what gets seeded, what may be changed, and what the
 * endpoints answer with.
 *
 * The chart is the one table every other accounting figure is eventually keyed
 * to, so a wrong row here does not fail — it reports. An account under the
 * wrong parent moves money between sections of the balance sheet, a contra
 * account without its flag turns a deduction into an addition, and a heading
 * that accepts postings counts the same pesos twice. None of those raise an
 * error anywhere; they just produce a statement that is quietly wrong.
 */
class AccountingChartOfAccountsTest extends TestCase
{
    use SetupLendyPH;

    /**
     * `DEFAULT_CHART_OF_ACCOUNTS` from `src/constants/chart-of-accounts.ts`,
     * transcribed: code => [name, type, parent code, is_group, is_contra,
     * cash_kind].
     *
     * The frontend renders that constant as a read-only preview before a chart
     * has ever been saved, so the two lists have to agree code for code AND
     * name for name — a divergence shows a user one chart and seeds them
     * another, with no error in between.
     *
     * TWO ROWS DEVIATE, deliberately, and are marked below. The frontend
     * constant needs the same two changes; until it gets them, these two lines
     * are the entire difference between the files and are meant to be read as
     * such.
     */
    private const EXPECTED_CHART = [
        '1000' => ['Assets', 'asset', null, true, false, null],
        '1010' => ['Cash on Hand', 'asset', '1000', false, false, 'cash'],
        '1020' => ['GCash', 'asset', '1000', false, false, 'gcash'],
        '1030' => ['Maya', 'asset', '1000', false, false, 'maya'],
        '1040' => ['Bank Accounts', 'asset', '1000', false, false, 'bank'],
        '1100' => ['Loans Receivable', 'asset', '1000', true, false, null],
        '1110' => ['Current Loans Receivable', 'asset', '1100', false, false, null],
        '1120' => ['Past Due Loans Receivable', 'asset', '1100', false, false, null],
        '1150' => ['Interest Receivable', 'asset', '1000', false, false, null],
        '1160' => ['Penalty Receivable', 'asset', '1000', false, false, null],
        '1170' => ['Other Receivables', 'asset', '1000', false, false, null],
        '1200' => ['Allowance for Credit Losses', 'asset', '1000', false, true, null],
        '1300' => ['Prepaid Expenses', 'asset', '1000', false, false, null],
        '1400' => ['Property and Equipment', 'asset', '1000', true, false, null],
        '1410' => ['Office Equipment', 'asset', '1400', false, false, null],
        '1420' => ['Computer Equipment', 'asset', '1400', false, false, null],
        '1490' => ['Accumulated Depreciation', 'asset', '1400', false, true, null],
        '2000' => ['Liabilities', 'liability', null, true, false, null],
        '2010' => ['Accounts Payable', 'liability', '2000', false, false, null],
        '2020' => ['Accrued Expenses', 'liability', '2000', false, false, null],
        '2100' => ['Loans Payable', 'liability', '2000', false, false, null],
        '2110' => ['Other Borrowings', 'liability', '2000', false, false, null],
        '2200' => ['Taxes Payable', 'liability', '2000', true, false, null],
        '2210' => ['Withholding Tax Payable', 'liability', '2200', false, false, null],
        '2220' => ['Other Taxes Payable', 'liability', '2200', false, false, null],
        '2300' => ['Other Liabilities', 'liability', '2000', false, false, null],
        '3000' => ['Equity', 'equity', null, true, false, null],
        '3010' => ['Capital', 'equity', '3000', false, false, null],
        '3020' => ['Additional Capital', 'equity', '3000', false, false, null],
        '3030' => ['Retained Earnings', 'equity', '3000', false, false, null],
        // DEVIATION 1: a group here, postable in the frontend constant.
        '3040' => ['Current Year Earnings', 'equity', '3000', true, false, null],
        // DEVIATION 2: absent from the frontend constant entirely.
        '3050' => ['Opening Balance Equity', 'equity', '3000', false, false, null],
        '4000' => ['Income', 'income', null, true, false, null],
        '4010' => ['Interest Income', 'income', '4000', false, false, null],
        '4020' => ['Penalty Income', 'income', '4000', false, false, null],
        '4030' => ['Loan Processing Fee Income', 'income', '4000', false, false, null],
        '4040' => ['Service Fee Income', 'income', '4000', false, false, null],
        '4050' => ['Membership Fee Income', 'income', '4000', false, false, null],
        '4060' => ['Other Lending Income', 'income', '4000', false, false, null],
        '4070' => ['Other Income', 'income', '4000', false, false, null],
        '5000' => ['Expenses', 'expense', null, true, false, null],
        '5010' => ['Salaries and Wages', 'expense', '5000', false, false, null],
        '5020' => ['Rent', 'expense', '5000', false, false, null],
        '5030' => ['Electricity', 'expense', '5000', false, false, null],
        '5040' => ['Internet', 'expense', '5000', false, false, null],
        '5050' => ['Transportation', 'expense', '5000', false, false, null],
        '5060' => ['Office Supplies', 'expense', '5000', false, false, null],
        '5070' => ['Bank Charges', 'expense', '5000', false, false, null],
        '5080' => ['GCash Charges', 'expense', '5000', false, false, null],
        '5090' => ['Software Expenses', 'expense', '5000', false, false, null],
        '5100' => ['Professional Fees', 'expense', '5000', false, false, null],
        '5110' => ['Advertising', 'expense', '5000', false, false, null],
        '5120' => ['Communication', 'expense', '5000', false, false, null],
        '5130' => ['Depreciation Expense', 'expense', '5000', false, false, null],
        '5140' => ['Credit Loss Expense', 'expense', '5000', false, false, null],
        '5150' => ['Miscellaneous Expense', 'expense', '5000', false, false, null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Seeding ──

    public function test_the_seeded_chart_matches_the_frontend_constant_code_for_code(): void
    {
        $this->seedChart()->assertCreated();

        $this->assertSame(self::EXPECTED_CHART, $this->chartAsFixture());
    }

    public function test_the_seeder_service_and_the_endpoint_produce_the_same_chart(): void
    {
        app(ChartOfAccountsSeeder::class)->seed();

        $this->assertSame(self::EXPECTED_CHART, $this->chartAsFixture());
    }

    /**
     * The shape trap. 2110 Other Borrowings is a SIBLING of 2100 Loans Payable,
     * not a child of it: filing it underneath would make bank debt a component
     * of other borrowings, and 2100 is a postable account rather than a heading
     * in the first place.
     */
    public function test_other_borrowings_is_a_sibling_of_loans_payable(): void
    {
        $this->seedChart()->assertCreated();

        $this->assertSame('2000', $this->parentCodeOf('2110'));
        $this->assertSame('2000', $this->parentCodeOf('2100'));
        $this->assertFalse($this->account('2100')->is_group);
    }

    /**
     * The other shape trap. Interest, penalty and other receivables hang off
     * 1000 Assets, NOT off 1100 Loans Receivable — they are not loan principal,
     * and rolling them in would overstate the portfolio by amounts the
     * organisation has not collected.
     */
    public function test_the_other_receivables_hang_off_assets_not_loans_receivable(): void
    {
        $this->seedChart()->assertCreated();

        foreach (['1150', '1160', '1170'] as $code) {
            $this->assertSame('1000', $this->parentCodeOf($code), "{$code} must hang off 1000.");
        }
    }

    /**
     * The groups are the eight structural headings — plus 3040, which is a
     * heading here and postable in the frontend constant. See DEVIATION 1.
     */
    public function test_the_group_headings_are_exactly_the_eight_plus_current_year_earnings(): void
    {
        $this->seedChart()->assertCreated();

        $groups = AccountingAccount::query()->where('is_group', true)->inCodeOrder()->pluck('code')->all();

        $this->assertSame(
            ['1000', '1100', '1400', '2000', '2200', '3000', '3040', '4000', '5000'],
            $groups,
        );
    }

    public function test_the_contra_accounts_are_exactly_the_allowance_and_accumulated_depreciation(): void
    {
        $this->seedChart()->assertCreated();

        $contra = AccountingAccount::query()->where('is_contra', true)->inCodeOrder()->pluck('code')->all();

        $this->assertSame(['1200', '1490'], $contra);
    }

    /**
     * A contra asset carries a CREDIT balance and subtracts from the assets
     * above it. Derived, never stored from a payload — see
     * AccountingAccount::booted().
     */
    public function test_the_contra_accounts_derive_a_credit_normal_balance(): void
    {
        $this->seedChart()->assertCreated();

        $this->assertSame('credit', $this->account('1200')->normal_balance);
        $this->assertSame('credit', $this->account('1490')->normal_balance);

        // And the plain accounts around them are unaffected.
        $this->assertSame('debit', $this->account('1010')->normal_balance);
        $this->assertSame('credit', $this->account('4010')->normal_balance);
        $this->assertSame('debit', $this->account('5010')->normal_balance);
        $this->assertSame('credit', $this->account('2010')->normal_balance);
        $this->assertSame('credit', $this->account('3010')->normal_balance);
    }

    /**
     * DEVIATION 1. `statements.ts` derives 3040 as a synthetic balance-sheet
     * line from the period's net income, so an entry posted to the real account
     * would appear on the same statement twice — once as the posting, once as
     * the derivation. It stays in the chart because year-end closing needs
     * somewhere to move the result to.
     */
    public function test_current_year_earnings_cannot_be_posted_to(): void
    {
        $this->seedChart()->assertCreated();

        $account = $this->account('3040');

        $this->assertTrue($account->is_group, '3040 must be non-postable — the balance sheet already derives it.');
        $this->assertFalse($account->isPostable());
    }

    /**
     * DEVIATION 2. The opening-balance backfill needs a named plug for the
     * difference an organisation arrives with. Folding it into 3030 Retained
     * Earnings would hide it permanently, in the one account nobody reconciles.
     * Deliberately 3050 and not 3045 — `statements.ts` already uses 3045 for a
     * synthetic line.
     */
    public function test_opening_balance_equity_exists_and_is_postable(): void
    {
        $this->seedChart()->assertCreated();

        $account = $this->account('3050');

        $this->assertSame('Opening Balance Equity', $account->name);
        $this->assertSame('equity', $account->type);
        $this->assertSame('credit', $account->normal_balance);
        $this->assertTrue($account->isPostable());
        $this->assertNull(AccountingAccount::query()->where('code', '3045')->first(), '3045 belongs to a synthetic statement line and must not exist in the chart.');
    }

    public function test_seeding_twice_is_refused(): void
    {
        $this->seedChart()->assertCreated();

        $this->seedChart()->assertStatus(409);

        $this->assertSame(count(self::EXPECTED_CHART), AccountingAccount::query()->count());
        $this->assertSame(count(AccountingAccountMapping::ROLES), AccountingAccountMapping::query()->count());
    }

    /**
     * `seedAccounts()` calls `api.post`, which reads `response.data.data` and
     * is typed `Promise<Account[]>`. A paginator here would hand the client a
     * `{data, links, meta}` object where it expects an array, and every picker
     * on the module would render nothing.
     */
    public function test_the_seed_endpoint_answers_with_a_flat_list_not_a_paginator(): void
    {
        $response = $this->seedChart()->assertCreated();

        $body = $response->json();

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('meta', $body, 'The seed endpoint returns Account[], not a page of them.');
        $this->assertArrayNotHasKey('links', $body);
        $this->assertCount(count(self::EXPECTED_CHART), $body['data']);
        $this->assertSame('1000', $body['data'][0]['code']);
        $this->assertSame('5150', $body['data'][count(self::EXPECTED_CHART) - 1]['code']);
    }

    /**
     * Registration order decides which route wins, so `accounts/{account}`
     * declared first would capture "seed" as an id.
     */
    public function test_the_seed_route_is_not_swallowed_by_the_id_wildcard(): void
    {
        $this->seedChart()->assertCreated();

        // 405, not 404, and the difference is the point: the URI matches a
        // registered route (the literal POST above) while the GET wildcard
        // declines to treat "seed" as an id at all. A 404 here would mean the
        // wildcard HAD matched and then failed to resolve account "seed".
        $this->getJson('/api/accounting/accounts/seed')->assertStatus(405);
    }

    // ── Listing ──

    /**
     * `accountsList` is fetched with `getRaw` and drained by `fetchAllPages`,
     * which follows `meta.last_page`. Wrapping this in the `{success, data}`
     * envelope, or flattening the paginator, breaks every picker in the module.
     */
    public function test_the_list_endpoint_answers_with_the_paginator_envelope_in_code_order(): void
    {
        $this->seedChart()->assertCreated();

        $response = $this->getJson('/api/accounting/accounts')->assertOk();

        $response->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', count(self::EXPECTED_CHART))
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', (int) ceil(count(self::EXPECTED_CHART) / 15));

        $codes = array_column($response->json('data'), 'code');
        $sorted = $codes;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $codes, 'Accounts must arrive in code order, which is statement order.');
        $this->assertSame('1000', $codes[0]);
    }

    public function test_the_list_endpoint_carries_every_field_the_frontend_account_type_declares(): void
    {
        $this->seedChart()->assertCreated();

        $row = $this->getJson('/api/accounting/accounts')->assertOk()->json('data.0');

        foreach (['id', 'code', 'name', 'type', 'normal_balance', 'is_contra', 'parent_id', 'is_group', 'is_active', 'cash_kind'] as $field) {
            $this->assertArrayHasKey($field, $row);
        }
    }

    /**
     * The clamp is silent by design — asking for more than 100 is fewer rows,
     * not an error — which is exactly why `fetchAllPages` reads the server's
     * own `meta.per_page` rather than the number it asked for.
     */
    public function test_per_page_is_clamped_at_one_hundred(): void
    {
        $this->seedChart()->assertCreated();

        $this->getJson('/api/accounting/accounts?per_page=9999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/accounting/accounts?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonCount(5, 'data');

        // 0 would otherwise become 15 silently, and a negative reaches the
        // database as a negative LIMIT.
        $this->getJson('/api/accounting/accounts?per_page=0')->assertStatus(422);
        $this->getJson('/api/accounting/accounts?per_page=-5')->assertStatus(422);
    }

    public function test_an_unseeded_chart_lists_nothing_rather_than_failing(): void
    {
        $this->getJson('/api/accounting/accounts')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    // ── Creating and editing ──

    /**
     * The leading digit decides which statement an account lands on. An "asset"
     * numbered 4010 would appear under income on every report while claiming to
     * be an asset everywhere else, and nothing would say which half was lying.
     */
    public function test_an_account_whose_code_disagrees_with_its_type_is_refused(): void
    {
        $this->seedChart()->assertCreated();

        $this->postJson('/api/accounting/accounts', [
            'code' => '4999',
            'name' => 'Definitely An Asset',
            'type' => 'asset',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        // A code outside 1-5 has no classification at all and is refused too,
        // rather than being quietly filed under "asset".
        $this->postJson('/api/accounting/accounts', [
            'code' => '9010',
            'name' => 'Unclassifiable',
            'type' => 'asset',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_an_account_whose_code_agrees_with_its_type_is_created(): void
    {
        $this->seedChart()->assertCreated();

        $response = $this->postJson('/api/accounting/accounts', [
            'code' => '1180',
            'name' => 'Advances to Officers',
            'type' => 'asset',
            'parent_id' => $this->id('1000'),
        ])->assertCreated();

        $response->assertJsonPath('data.code', '1180')
            ->assertJsonPath('data.normal_balance', 'debit')
            ->assertJsonPath('data.is_group', false)
            ->assertJsonPath('data.is_active', true);

        $this->assertSame($this->admin->id, AccountingAccount::query()->where('code', '1180')->value('created_by'));
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $this->seedChart()->assertCreated();

        $this->postJson('/api/accounting/accounts', [
            'code' => '1010',
            'name' => 'Another Cash Account',
            'type' => 'asset',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    /**
     * `cash_kind` is what puts an account on the Cash & Bank screen, where its
     * balance reads as money the organisation holds. A heading's balance is the
     * sum of its subtree, so listing one there counts the same pesos twice in a
     * single figure.
     */
    public function test_a_group_account_refuses_a_cash_kind(): void
    {
        $this->seedChart()->assertCreated();

        $this->postJson('/api/accounting/accounts', [
            'code' => '1500',
            'name' => 'Other Money',
            'type' => 'asset',
            'is_group' => true,
            'cash_kind' => 'bank',
        ])->assertStatus(422)->assertJsonValidationErrors('cash_kind');

        // The same account without the money flag is fine.
        $this->postJson('/api/accounting/accounts', [
            'code' => '1500',
            'name' => 'Other Assets',
            'type' => 'asset',
            'is_group' => true,
        ])->assertCreated();
    }

    /**
     * `is_contra`, `is_group` and `is_active` back NOT NULL columns with
     * defaults. Accepting an explicit null would merge it over the default and
     * insert null — a 500 on a payload the validator had just approved.
     */
    public function test_a_null_flag_is_refused_rather_than_reaching_the_database(): void
    {
        $this->seedChart()->assertCreated();

        foreach (['is_contra', 'is_group', 'is_active'] as $flag) {
            $this->postJson('/api/accounting/accounts', [
                'code' => '1195',
                'name' => 'Null Flag',
                'type' => 'asset',
                $flag => null,
            ])->assertStatus(422)->assertJsonValidationErrors($flag);
        }

        $this->putJson('/api/accounting/accounts/'.$this->id('1010'), ['is_active' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    }

    public function test_a_parent_must_be_a_group_of_the_same_type(): void
    {
        $this->seedChart()->assertCreated();

        // 1010 Cash on Hand is postable, not a heading.
        $this->postJson('/api/accounting/accounts', [
            'code' => '1011',
            'name' => 'Petty Cash',
            'type' => 'asset',
            'parent_id' => $this->id('1010'),
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        // 2000 Liabilities is a heading, but of the wrong classification.
        $this->postJson('/api/accounting/accounts', [
            'code' => '1181',
            'name' => 'Misfiled Asset',
            'type' => 'asset',
            'parent_id' => $this->id('2000'),
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    /**
     * `normal_balance` is derived from `type` + `is_contra` on every write. A
     * client that sends it is ignored rather than refused, because the frontend
     * sends a `Partial<Account>` that may well carry the whole object.
     */
    public function test_normal_balance_is_derived_and_never_accepted_from_the_client(): void
    {
        $this->seedChart()->assertCreated();

        $this->postJson('/api/accounting/accounts', [
            'code' => '1210',
            'name' => 'Allowance for Something Else',
            'type' => 'asset',
            'is_contra' => true,
            // A contra asset is credit-normal. This must not survive.
            'normal_balance' => 'debit',
            'parent_id' => $this->id('1000'),
        ])->assertCreated()->assertJsonPath('data.normal_balance', 'credit');

        $this->assertSame('credit', AccountingAccount::query()->where('code', '1210')->value('normal_balance'));
    }

    /**
     * Exercised on an UNMAPPED contra account, deliberately.
     *
     * This used to use 1200 Allowance for Credit Losses, and that made it a
     * test that the `allowance_credit_losses` role could be re-shaped out from
     * under itself. Dropping `is_contra` there re-derives `normal_balance` to
     * debit — correctly, which is the behaviour below — and the automatic
     * engine then posts provisions to a debit-normal allowance, so
     * `AccountingDashboardBuilder::netReceivable()` reports gross PLUS the
     * provision. Nothing fails to balance. That route is now refused by
     * ValidatesAccountShape::assertMappedRolesStillFit(); see
     * AccountingAccountFieldsTest for the refusal itself.
     *
     * The derivation rule it was actually written to cover is unchanged, and is
     * what this still asserts.
     */
    public function test_changing_the_contra_flag_re_derives_the_normal_balance(): void
    {
        $this->seedChart()->assertCreated();

        $spare = AccountingAccount::query()->create([
            'code' => '1220',
            'name' => 'Allowance for Other Losses',
            'type' => 'asset',
            'is_contra' => true,
            'parent_id' => $this->id('1000'),
        ]);

        $this->assertSame('credit', $spare->normal_balance);

        $this->putJson("/api/accounting/accounts/{$spare->id}", ['is_contra' => false])
            ->assertOk()
            ->assertJsonPath('data.normal_balance', 'debit');
    }

    public function test_a_partial_update_leaves_untouched_fields_alone(): void
    {
        $this->seedChart()->assertCreated();

        $account = $this->account('1010');

        $this->putJson("/api/accounting/accounts/{$account->id}", ['name' => 'Cash in Vault'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cash in Vault')
            ->assertJsonPath('data.code', '1010')
            ->assertJsonPath('data.cash_kind', 'cash')
            ->assertJsonPath('data.normal_balance', 'debit');
    }

    public function test_an_account_cannot_be_reparented_below_itself(): void
    {
        $this->seedChart()->assertCreated();

        $assets = $this->account('1000');

        // 1100 sits below 1000, so this would close a loop.
        $this->putJson("/api/accounting/accounts/{$assets->id}", ['parent_id' => $this->id('1100')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');

        $this->putJson("/api/accounting/accounts/{$assets->id}", ['parent_id' => $assets->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    /**
     * A heading with children cannot stop being one: it would become postable
     * while still displaying the total of everything below it.
     */
    public function test_a_heading_with_children_cannot_become_postable(): void
    {
        $this->seedChart()->assertCreated();

        $this->putJson('/api/accounting/accounts/'.$this->id('1100'), ['is_group' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_group');
    }

    /**
     * An account a posting role resolves to has to stay postable, or the next
     * automatic entry through it fails at the moment a loan is released.
     */
    public function test_a_mapped_account_cannot_be_turned_into_a_heading_or_deactivated(): void
    {
        $this->seedChart()->assertCreated();

        $cash = $this->account('1010');

        $this->putJson("/api/accounting/accounts/{$cash->id}", ['is_group' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_group');

        $this->putJson("/api/accounting/accounts/{$cash->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    }

    // ── Deleting ──

    public function test_an_account_with_children_cannot_be_deleted(): void
    {
        $this->seedChart()->assertCreated();

        $this->deleteJson('/api/accounting/accounts/'.$this->id('1100'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');

        $this->assertNotNull($this->account('1100'));
    }

    public function test_an_account_a_posting_role_resolves_to_cannot_be_deleted(): void
    {
        $this->seedChart()->assertCreated();

        // 1110 is the `loans_receivable` role and has no children at all, so
        // the mapping is the only thing standing in the way.
        $this->deleteJson('/api/accounting/accounts/'.$this->id('1110'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');

        $this->assertNotNull($this->account('1110'));
    }

    public function test_an_unreferenced_leaf_can_be_deleted(): void
    {
        $this->seedChart()->assertCreated();

        $id = $this->id('5110');

        $this->deleteJson("/api/accounting/accounts/{$id}")->assertOk();

        $this->assertNull(AccountingAccount::query()->find($id));
    }

    // ── The posting account mapping ──

    public function test_every_mapping_resolves_after_seeding(): void
    {
        $this->seedChart()->assertCreated();

        $mapping = $this->getJson('/api/accounting/settings/account-mapping')->assertOk()->json('data');

        $this->assertSame(AccountingAccountMapping::ROLES, array_keys($mapping));

        // Counted off the constant rather than a literal. The literal was 13,
        // and adding `borrower_advances` to ROLES reddened this test from a
        // file the change never touched — the count is a restatement of the
        // line above it, so it can only ever go stale.
        $this->assertCount(count(AccountingAccountMapping::ROLES), $mapping);

        $expected = [];
        foreach (ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES as $role => $code) {
            $expected[$role] = $this->id($code);
        }

        $this->assertSame($expected, $mapping);

        // Every role must resolve to something a journal line may actually
        // reference. `loans_receivable` points at 1110, the LEAF — 1100 is the
        // heading above it, and a rule resolving to a group would double-count
        // the subtree it sums.
        $this->assertSame('1110', $this->account('1110')->code);
        $this->assertSame($this->id('1110'), $mapping['loans_receivable']);

        foreach ($mapping as $role => $accountId) {
            $account = AccountingAccount::query()->findOrFail($accountId);
            $this->assertTrue($account->isPostable(), "The {$role} role resolves to {$account->code}, which cannot be posted to.");
        }
    }

    public function test_an_unseeded_chart_has_an_empty_mapping_rather_than_a_broken_one(): void
    {
        $this->getJson('/api/accounting/settings/account-mapping')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_pointing_a_mapping_at_a_group_is_refused(): void
    {
        $this->seedChart()->assertCreated();

        $this->putJson('/api/accounting/settings/account-mapping', [
            'loans_receivable' => $this->id('1100'),
        ])->assertStatus(422)->assertJsonValidationErrors('loans_receivable');

        // And the stored mapping is untouched.
        $this->assertSame($this->id('1110'), AccountingAccountMapping::resolved()['loans_receivable']);
    }

    public function test_pointing_a_mapping_at_an_inactive_account_is_refused(): void
    {
        $this->seedChart()->assertCreated();

        $spare = AccountingAccount::query()->create([
            'code' => '1190',
            'name' => 'Retired Receivable',
            'type' => 'asset',
            'is_active' => false,
            'parent_id' => $this->id('1000'),
        ]);

        $this->putJson('/api/accounting/settings/account-mapping', [
            'loans_receivable' => $spare->id,
        ])->assertStatus(422)->assertJsonValidationErrors('loans_receivable');
    }

    public function test_a_mapping_update_repoints_only_the_roles_it_was_sent(): void
    {
        $this->seedChart()->assertCreated();

        $before = AccountingAccountMapping::resolved();

        $response = $this->putJson('/api/accounting/settings/account-mapping', [
            'interest_income' => $this->id('4060'),
        ])->assertOk();

        $after = $response->json('data');

        $this->assertSame($this->id('4060'), $after['interest_income']);

        foreach ($before as $role => $accountId) {
            if ($role === 'interest_income') {
                continue;
            }

            $this->assertSame($accountId, $after[$role], "The {$role} role must not move when it was not sent.");
        }
    }

    public function test_a_mapping_cannot_point_at_an_account_that_does_not_exist(): void
    {
        $this->seedChart()->assertCreated();

        $this->putJson('/api/accounting/settings/account-mapping', ['cash' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash');
    }

    // ── The audit trail ──

    /**
     * Seeding writes ONE audit row, not sixty-nine.
     *
     * Both models are Auditable — who renamed an account or re-pointed a
     * posting role has to be recoverable, because either quietly changes what
     * future statements say. But creating the chart is a single administrative
     * act, and a row per account would bury every other entry of that day.
     */
    public function test_seeding_writes_one_audit_row_rather_than_one_per_account(): void
    {
        $this->seedChart()->assertCreated();

        $rows = AuditLog::query()->where('action', 'chart_of_accounts_seeded')->get();

        $this->assertCount(1, $rows);
        $this->assertSame($this->admin->id, $rows->first()->user_id);
        $this->assertSame(count(self::EXPECTED_CHART), $rows->first()->new_values['accounts']);
        $this->assertSame(count(AccountingAccountMapping::ROLES), $rows->first()->new_values['mappings']);

        $this->assertSame(
            0,
            AuditLog::query()->where('auditable_type', AccountingAccount::class)->count(),
            'Seeding must not write a per-account audit row.',
        );
    }

    public function test_editing_an_account_is_audited(): void
    {
        $this->seedChart()->assertCreated();

        $account = $this->account('1010');

        $this->putJson("/api/accounting/accounts/{$account->id}", ['name' => 'Cash in Vault'])->assertOk();

        $entry = AuditLog::query()
            ->where('auditable_type', AccountingAccount::class)
            ->where('auditable_id', $account->id)
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertSame('Cash in Vault', $entry->new_values['name']);
        $this->assertSame($this->admin->id, $entry->user_id);
    }

    public function test_repointing_a_posting_role_is_audited(): void
    {
        $this->seedChart()->assertCreated();

        $this->putJson('/api/accounting/settings/account-mapping', [
            'interest_income' => $this->id('4060'),
        ])->assertOk();

        $entry = AuditLog::query()
            ->where('auditable_type', AccountingAccountMapping::class)
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertSame($this->id('4060'), $entry->new_values['accounting_account_id']);
    }

    // ── Who may do any of this ──

    public function test_a_bookkeeper_may_read_the_chart_but_not_change_it(): void
    {
        $this->seedChart()->assertCreated();

        $this->actingAs($this->userWithRole('general_bookkeeper'));

        $this->getJson('/api/accounting/accounts')->assertOk();

        $this->postJson('/api/accounting/accounts', [
            'code' => '1185',
            'name' => 'Unauthorised Account',
            'type' => 'asset',
        ])->assertForbidden();

        $this->putJson('/api/accounting/accounts/'.$this->id('1010'), ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson('/api/accounting/accounts/'.$this->id('5110'))->assertForbidden();

        // Accounting settings are an admin decision, not a bookkeeping one.
        $this->getJson('/api/accounting/settings/account-mapping')->assertForbidden();
        $this->putJson('/api/accounting/settings/account-mapping', ['cash' => $this->id('1010')])->assertForbidden();
    }

    public function test_seeding_the_chart_is_not_a_bookkeepers_call(): void
    {
        $this->actingAs($this->userWithRole('general_bookkeeper'));

        $this->postJson('/api/accounting/accounts/seed')->assertForbidden();

        $this->assertSame(0, AccountingAccount::query()->count());
    }

    public function test_a_role_without_accounting_permissions_sees_nothing(): void
    {
        $this->seedChart()->assertCreated();

        $this->actingAs($this->userWithRole('viewer'));

        $this->getJson('/api/accounting/accounts')->assertForbidden();
        $this->getJson('/api/accounting/accounts/'.$this->id('1010'))->assertForbidden();
        $this->getJson('/api/accounting/settings/account-mapping')->assertForbidden();
    }

    public function test_every_accounting_endpoint_requires_authentication(): void
    {
        app(ChartOfAccountsSeeder::class)->seed();

        $id = $this->id('1010');
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/accounting/accounts')->assertUnauthorized();
        $this->postJson('/api/accounting/accounts/seed')->assertUnauthorized();
        $this->getJson("/api/accounting/accounts/{$id}")->assertUnauthorized();
        $this->getJson('/api/accounting/settings/account-mapping')->assertUnauthorized();
    }

    // ── Helpers ──

    private function seedChart(): TestResponse
    {
        return $this->postJson('/api/accounting/accounts/seed');
    }

    /**
     * The stored chart in the shape of {@see self::EXPECTED_CHART}, so the two
     * can be compared in one assertion — which also proves the code ordering
     * and catches any row that is missing or extra.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: bool, 4: bool, 5: string|null}>
     */
    private function chartAsFixture(): array
    {
        $accounts = AccountingAccount::query()->inCodeOrder()->get();
        $codeById = $accounts->pluck('code', 'id');

        $fixture = [];

        foreach ($accounts as $account) {
            $fixture[$account->code] = [
                $account->name,
                $account->type,
                $account->parent_id === null ? null : $codeById[$account->parent_id],
                $account->is_group,
                $account->is_contra,
                $account->cash_kind,
            ];
        }

        return $fixture;
    }

    private function account(string $code): ?AccountingAccount
    {
        return AccountingAccount::query()->where('code', $code)->first();
    }

    private function id(string $code): int
    {
        return (int) AccountingAccount::query()->where('code', $code)->value('id');
    }

    private function parentCodeOf(string $code): ?string
    {
        $parentId = $this->account($code)?->parent_id;

        return $parentId === null ? null : AccountingAccount::query()->whereKey($parentId)->value('code');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }
}
