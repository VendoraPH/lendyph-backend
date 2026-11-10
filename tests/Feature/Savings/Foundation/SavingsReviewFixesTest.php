<?php

namespace Tests\Feature\Savings\Foundation;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Models\SavingsSetting;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\BranchAccess;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLedger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The fixes from the review of PR #272 (M1–M5, L1–L10): each test fails on
 * the code before the fix.
 */
class SavingsReviewFixesTest extends TestCase
{
    use SetupLendyPH;

    private SavingsLedger $ledger;

    private AutomaticPoster $poster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->ledger = app(SavingsLedger::class);
        $this->poster = app(AutomaticPoster::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(array $attributes = []): SavingsAccount
    {
        return SavingsAccount::factory()->create(array_merge(['opened_on' => '2026-11-01'], $attributes));
    }

    private function books(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
    }

    private function refusedOn(callable $write, string $field, string $contains): void
    {
        try {
            DB::transaction($write);
            $this->fail('The write was not refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($contains, $e->errors()[$field][0]);
        }
    }

    private function booksRefuse(callable $post, string $contains): void
    {
        try {
            DB::transaction($post);
            $this->fail('The books took the posting.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
            $this->assertStringNotContainsString('_', $e->getMessage());
        }
    }

    // ── M1 ──

    public function test_m1_settings_hold_exactly_two_checkers_and_a_stored_third_is_ignored(): void
    {
        $message = $this->putJson('/api/savings/settings', ['tier3_checkers' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('tier3_checkers')->json('errors.tier3_checkers.0');
        $this->assertStringContainsString('two', $message);
        $this->putJson('/api/savings/settings', ['tier3_checkers' => 2])->assertOk();

        DB::table('savings_settings')->update(['tier3_checkers' => 5]);
        $gate = app(ApprovalGate::class);

        $this->assertSame(2, $gate->requiredCheckers(5_000_001));
        $approval = $gate->open('withdrawal', 5_000_001, ['account_id' => $this->account()->id], $this->admin->id);
        $this->assertSame(2, $approval->required_checkers);
    }

    // ── M2 ──

    public function test_m2_a_reversed_posting_is_never_silently_handed_back(): void
    {
        $this->books();
        $deposit = DB::transaction(fn () => $this->ledger->credit($this->account(), 'deposit', 10_000, ['method' => 'cash']));
        $this->poster->savingsTransaction($deposit, $this->admin->id);
        DB::transaction(fn () => $this->poster->reverseFor($deposit, 'savings', $this->admin->id, 'Wrong account'));

        $this->booksRefuse(fn () => $this->poster->savingsTransaction($deposit, $this->admin->id), 'reversed');

        $placement = TimeDeposit::factory()->create(['placement_date' => '2026-11-10']);
        $this->poster->timeDepositPlacement($placement, $this->admin->id);
        DB::transaction(fn () => $this->poster->reverseFor($placement, 'time_deposit', $this->admin->id));

        $this->booksRefuse(fn () => $this->poster->timeDepositPlacement($placement, $this->admin->id), 'reversed');
    }

    // ── M3 ──

    public function test_m3_an_interest_run_posts_only_when_its_rows_match_its_totals(): void
    {
        $this->books();
        $account = $this->account();
        $drifted = SavingsInterestRun::factory()->create(['product_id' => $account->product_id, 'branch_id' => $account->branch_id, 'status' => 'approved', 'total_gross' => '10.00', 'total_tax' => '2.00', 'total_net' => '8.00']);
        DB::transaction(function () use ($account, $drifted): void {
            $this->ledger->credit($account, 'interest', 999, ['value_date' => '2026-11-09', 'interest_run_id' => $drifted->id, 'created_by' => null]);
            $this->ledger->debit($account, 'withholding_tax', 200, ['value_date' => '2026-11-09', 'interest_run_id' => $drifted->id, 'created_by' => null]);
        });

        $this->booksRefuse(fn () => $this->poster->savingsInterestRun($drifted, $this->admin->id), 'does not match');

        $matched = SavingsInterestRun::factory()->create(['product_id' => $account->product_id, 'branch_id' => $account->branch_id, 'status' => 'approved', 'total_gross' => '10.00', 'total_tax' => '2.00', 'total_net' => '8.00', 'period_start' => '2026-04-01', 'period_end' => '2026-06-30']);
        DB::transaction(function () use ($account, $matched): void {
            $this->ledger->credit($account, 'interest', 1_000, ['value_date' => '2026-11-09', 'interest_run_id' => $matched->id, 'created_by' => null]);
            $this->ledger->debit($account, 'withholding_tax', 200, ['value_date' => '2026-11-09', 'interest_run_id' => $matched->id, 'created_by' => null]);
        });

        $this->assertSame('savings_interest', DB::transaction(fn () => $this->poster->savingsInterestRun($matched, $this->admin->id))->source);
    }

    // ── M4 ──

    public function test_m4_a_rollover_placement_posts_nothing_of_its_own(): void
    {
        $this->books();
        $original = TimeDeposit::factory()->create();
        $rollover = TimeDeposit::factory()->create(['rolled_over_from_id' => $original->id, 'funding_method' => 'savings', 'placement_date' => '2026-11-10']);

        $this->booksRefuse(fn () => $this->poster->timeDepositPlacement($rollover, $this->admin->id), 'rolled over');
    }

    // ── M5 ──

    public function test_m5_a_reversal_keeps_the_value_date_of_the_row_it_reverses_inside_a_credited_period(): void
    {
        $account = $this->account();
        $interest = DB::transaction(fn () => $this->ledger->credit($account, 'interest', 500, ['value_date' => '2026-11-09', 'created_by' => null]));
        DB::table('savings_accounts')->where('id', $account->id)->update(['interest_credited_through' => '2026-11-09']);
        $account->refresh();

        $reversal = DB::transaction(fn () => $this->ledger->debit($account, 'reversal', 500, ['value_date' => '2026-11-09', 'reverses_transaction_id' => $interest->id]));
        $this->assertSame('2026-11-09', $reversal->value_date->toDateString());
        $this->assertSame('0.00', $account->refresh()->ledger_balance);

        $other = DB::transaction(fn () => $this->ledger->credit($account, 'deposit', 100, ['method' => 'cash']));
        $this->refusedOn(fn () => $this->ledger->debit($account, 'reversal', 100, ['value_date' => '2026-11-09', 'reverses_transaction_id' => $other->id]), 'value_date', 'already been credited');
        $this->refusedOn(fn () => $this->ledger->debit($account, 'withdrawal', 100, ['method' => 'cash', 'value_date' => '2026-11-09', 'reverses_transaction_id' => $interest->id]), 'reverses_transaction_id', 'Only a reversal');
    }

    // ── L1 ──

    public function test_l1_malformed_amounts_are_422_in_words_never_500(): void
    {
        foreach (['.5', '5.', '+5', '1e3'] as $amount) {
            $this->putJson('/api/savings/settings', ['tier1_limit' => $amount])
                ->assertUnprocessable()->assertJsonValidationErrors('tier1_limit');
        }

        $this->postJson('/api/savings/products', ['code' => 'X-1', 'name' => 'X', 'kind' => 'regular', 'annual_interest_rate' => '1', 'minimum_opening_balance' => '.5'])
            ->assertUnprocessable()->assertJsonValidationErrors('minimum_opening_balance');
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '+5', 'effective_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('rate');
        $this->assertSame('10000.00', SavingsSetting::current()->tier1_limit);
    }

    // ── L2 ──

    public function test_l2_a_balance_beyond_what_the_column_holds_is_refused(): void
    {
        $account = $this->account(['ledger_balance' => '9999999999.99']);

        $this->refusedOn(fn () => $this->ledger->credit($account, 'deposit', 1, ['method' => 'cash']), 'amount', 'beyond');
    }

    // ── L4 ──

    public function test_l4_a_transfer_posts_nothing_only_within_one_branch(): void
    {
        $this->books();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $from = $this->account(['borrower_id' => $borrower->id, 'branch_id' => $this->branch->id, 'ledger_balance' => '0.00']);
        $sameBranch = $this->account(['borrower_id' => $borrower->id, 'branch_id' => $this->branch->id]);
        $otherBranch = $this->account(['borrower_id' => $borrower->id, 'branch_id' => Branch::factory()->create()->id]);
        DB::transaction(fn () => $this->ledger->credit($from, 'deposit', 10_000, ['method' => 'cash']));

        [$out, $in] = DB::transaction(function () use ($from, $sameBranch): array {
            $out = $this->ledger->debit($from, 'transfer_out', 1_000, ['method' => 'savings']);

            return [$out, $this->ledger->credit($sameBranch, 'transfer_in', 1_000, ['method' => 'savings', 'counterpart_transaction_id' => $out->id])];
        });
        $this->assertNull($this->poster->savingsTransaction($out, $this->admin->id));
        $this->assertNull($this->poster->savingsTransaction($in, $this->admin->id));

        [$crossOut, $crossIn] = DB::transaction(function () use ($from, $otherBranch): array {
            $out = $this->ledger->debit($from, 'transfer_out', 1_000, ['method' => 'savings']);

            return [$out, $this->ledger->credit($otherBranch, 'transfer_in', 1_000, ['method' => 'savings', 'counterpart_transaction_id' => $out->id])];
        });
        $this->booksRefuse(fn () => $this->poster->savingsTransaction($crossIn, $this->admin->id), 'branch');
        $this->booksRefuse(fn () => $this->poster->savingsTransaction($crossOut, $this->admin->id), 'branch');

        $lonely = DB::transaction(fn () => $this->ledger->debit($from, 'transfer_out', 1_000, ['method' => 'savings']));
        $this->booksRefuse(fn () => $this->poster->savingsTransaction($lonely, $this->admin->id), 'other leg');
    }

    // ── L5 ──

    public function test_l5_accounts_and_runs_always_have_a_branch(): void
    {
        foreach (['savings_accounts', 'savings_interest_runs'] as $table) {
            $nullable = DB::selectOne(
                'select IS_NULLABLE from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
                [$table, 'branch_id'],
            )->IS_NULLABLE;

            $this->assertSame('NO', $nullable, "{$table}.branch_id must be required.");
        }
    }

    // ── L6 ──

    public function test_l6_a_settlement_must_pay_out_its_stored_settled_amount(): void
    {
        $this->books();
        $deposit = TimeDeposit::factory()->create(['principal' => '50000.00', 'status' => 'withdrawn', 'settled_on' => '2026-11-10', 'settled_interest' => '616.44', 'settled_tax' => '123.29', 'settled_amount' => '50493.14']);

        $this->booksRefuse(fn () => $this->poster->timeDepositSettlement($deposit, 0, 'cash', null), 'settled amount');

        $deposit->forceFill(['settled_amount' => '50493.15'])->save();
        $this->assertSame('time_deposit_settlement', DB::transaction(fn () => $this->poster->timeDepositSettlement($deposit, 0, 'cash', null))->source);
    }

    // ── L7 ──

    public function test_l7_a_second_live_approval_for_the_same_thing_is_409(): void
    {
        $account = $this->account();
        $gate = app(ApprovalGate::class);
        $gate->open('reversal', 100, ['account_id' => $account->id, 'live_key' => 'rev:42'], $this->admin->id);

        try {
            DB::transaction(fn () => $gate->open('reversal', 100, ['account_id' => $account->id, 'live_key' => 'rev:42'], $this->admin->id));
            $this->fail('A second live approval was opened.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertStringContainsString('already waiting', $e->getResponse()->getData(true)['message']);
        }
    }

    // ── L8 ──

    public function test_l8_a_transfer_in_reactivates_a_dormant_account_with_an_audit_row(): void
    {
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-05']);

        $in = DB::transaction(fn () => $this->ledger->credit($account, 'transfer_in', 100, ['method' => 'savings']));

        $this->assertSame('active', $account->refresh()->status);
        $row = AuditLog::query()->where('action', 'savings_account_reactivated')->where('auditable_id', $account->id)->sole();
        $this->assertStringContainsString('transfer', (string) $row->description);
        $this->assertStringContainsString($in->reference, (string) $row->description);
    }

    // ── L9 ──

    public function test_l9_a_frozen_account_takes_no_member_activity_but_still_earns(): void
    {
        $account = $this->account(['status' => 'frozen']);

        foreach (['deposit', 'transfer_in'] as $type) {
            $this->refusedOn(fn () => $this->ledger->credit($account, $type, 100, ['method' => 'cash']), 'account', 'frozen');
        }

        DB::transaction(fn () => $this->ledger->credit($account, 'interest', 100, ['value_date' => '2026-11-09', 'created_by' => null]));
        $this->refusedOn(fn () => $this->ledger->debit($account, 'withdrawal', 50, ['method' => 'cash']), 'account', 'frozen');
        $this->assertSame('1.00', $account->refresh()->ledger_balance);
    }

    // ── L10 ──

    public function test_l10_company_wide_writes_need_branches_view_all_and_reads_need_view(): void
    {
        $product = SavingsProduct::factory()->create();
        $writes = [
            ['putJson', '/api/savings/settings', ['dormancy_months' => 12]],
            ['postJson', '/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '0', 'effective_from' => '2026-11-10']],
            ['postJson', '/api/savings/products', ['code' => 'NEW-1', 'name' => 'New', 'kind' => 'regular', 'annual_interest_rate' => '1']],
            ['putJson', "/api/savings/products/{$product->id}", ['name' => 'Renamed']],
            ['postJson', "/api/savings/products/{$product->id}/rates", ['annual_rate' => '1', 'effective_from' => '2026-11-10']],
            ['deleteJson', "/api/savings/products/{$product->id}", []],
        ];

        $this->actingAs($this->userWith(['savings:view', 'savings:settings']));
        foreach ($writes as [$method, $uri, $body]) {
            $this->{$method}($uri, $body)->assertForbidden();
        }

        foreach (['/api/savings/settings', '/api/savings/products', "/api/savings/products/{$product->id}/rates"] as $uri) {
            $this->getJson($uri)->assertOk();
        }

        $this->actingAs($this->userWith(['savings:settings', BranchAccess::VIEW_ALL]));
        foreach ($writes as [$method, $uri, $body]) {
            $this->assertNotSame(403, $this->{$method}($uri, $body)->status(), "{$method} {$uri} refused a company-wide caller.");
        }
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'savings_review_'.Role::query()->count(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
