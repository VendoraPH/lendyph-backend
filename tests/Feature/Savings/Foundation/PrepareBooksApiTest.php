<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\BranchAccess;
use App\Services\Savings\SavingsBooks;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `POST /savings/settings/prepare-books` and the `books_ready` and
 * `books_missing` fields of `GET /savings/settings`: staff turn savings on in
 * the books from Settings (the same `SavingsBooks::prepare()` as the
 * `savings:prepare-books` command), since staging and production change only
 * through code. Company-wide, so it needs `savings:settings` and
 * `branches:view_all`.
 */
class PrepareBooksApiTest extends TestCase
{
    use SetupLendyPH;

    private const ALL_ROLES_IN_WORDS = ['Savings deposits', 'Time deposits', 'Interest expense on deposits', 'Withholding tax on deposits'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A user whose only role holds exactly these permissions. */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'books_probe_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->branches()->sync([$this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function snapshot(): array
    {
        return [AccountingAccount::query()->count(), AccountingAccountMapping::query()->count(), AuditLog::query()->count()];
    }

    public function test_preparing_needs_savings_settings_and_access_to_all_branches(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $refused = [$this->userWith(['savings:view', BranchAccess::VIEW_ALL]), $this->userWith(['savings:view', 'savings:settings'])];
        $before = $this->snapshot();

        foreach ($refused as $user) {
            $this->actingAs($user)
                ->postJson('/api/savings/settings/prepare-books')
                ->assertForbidden()
                ->assertJsonPath('message', 'Savings settings, tax rules, products and rates apply to every branch, so changing them needs the savings settings permission and access to all branches.');
        }

        $this->assertSame($before, $this->snapshot());

        $this->actingAs($this->userWith(['savings:view', 'savings:settings', BranchAccess::VIEW_ALL]))
            ->postJson('/api/savings/settings/prepare-books')
            ->assertOk();
    }

    public function test_on_a_lending_company_chart_it_adds_and_maps_everything_and_a_product_can_then_be_turned_on(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $product = SavingsProduct::factory()->inactive()->create();

        $this->getJson('/api/savings/settings')
            ->assertOk()
            ->assertJsonPath('data.books_ready', false)
            ->assertJsonPath('data.books_missing', self::ALL_ROLES_IN_WORDS);

        $this->putJson("/api/savings/products/{$product->id}", ['is_active' => true])->assertUnprocessable();

        $this->postJson('/api/savings/settings/prepare-books')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'The savings books are ready: 3 accounts added and 4 posting roles mapped.')
            ->assertJsonPath('data.books_ready', true)
            ->assertJsonPath('data.books_missing', [])
            ->assertJsonPath('data.added', [
                '2050 Savings Deposits (liability)',
                '2060 Time Deposits (liability)',
                '5160 Interest Expense on Deposits (expense)',
            ])
            ->assertJsonPath('data.mapped', [
                'Savings deposits now posts to 2050 Savings Deposits',
                'Time deposits now posts to 2060 Time Deposits',
                'Interest expense on deposits now posts to 5160 Interest Expense on Deposits',
                'Withholding tax on deposits now posts to 2210 Withholding Tax Payable',
            ])
            ->assertJsonPath('data.unchanged', [])
            ->assertJsonPath('data.skipped', []);

        $this->getJson('/api/savings/settings')
            ->assertJsonPath('data.books_ready', true)
            ->assertJsonPath('data.books_missing', []);

        $this->putJson("/api/savings/products/{$product->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_the_audit_rows_name_who_pressed_it(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $staff = $this->userWith(['savings:view', 'savings:settings', BranchAccess::VIEW_ALL]);
        $auditBefore = AuditLog::query()->max('id');

        $this->actingAs($staff)->postJson('/api/savings/settings/prepare-books')->assertOk();

        $rows = AuditLog::query()->where('id', '>', $auditBefore)->orderBy('id')->get();
        $this->assertCount(7, $rows);
        $this->assertSame([$staff->id], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertSame(
            [SavingsBooks::AUDIT_ACCOUNT_ADDED => 3, SavingsBooks::AUDIT_ROLE_MAPPED => 4],
            $rows->countBy('action')->sortKeys()->all(),
        );
        $this->assertSame(
            'Added account 2050 Savings Deposits (liability) for members\' savings balances, as approved in the Phase 3 plan on 2026-11-10.',
            $rows->first()->description,
        );
        $this->assertStringNotContainsString('System change', $rows->pluck('description')->implode(' '));
    }

    public function test_a_second_call_changes_nothing_and_says_so(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $this->postJson('/api/savings/settings/prepare-books')->assertOk();
        $before = $this->snapshot();

        $this->postJson('/api/savings/settings/prepare-books')
            ->assertOk()
            ->assertJsonPath('message', 'The savings books were already set up. Nothing changed.')
            ->assertJsonPath('data.books_ready', true)
            ->assertJsonPath('data.added', [])
            ->assertJsonPath('data.mapped', [])
            ->assertJsonPath('data.unchanged', [
                'Savings deposits already posts to 2050 Savings Deposits',
                'Time deposits already posts to 2060 Time Deposits',
                'Interest expense on deposits already posts to 5160 Interest Expense on Deposits',
                'Withholding tax on deposits already posts to 2210 Withholding Tax Payable',
            ]);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_with_no_chart_it_is_422_in_words_and_writes_nothing(): void
    {
        $before = $this->snapshot();

        $this->getJson('/api/savings/settings')
            ->assertJsonPath('data.books_ready', false)
            ->assertJsonPath('data.books_missing', self::ALL_ROLES_IN_WORDS);

        $this->postJson('/api/savings/settings/prepare-books')
            ->assertUnprocessable()
            ->assertJsonPath('errors.books.0', 'There is no chart of accounts yet, so the savings books can\'t be set up. Set one up in Accounting → Chart of Accounts first.');

        $this->assertSame($before, $this->snapshot());
    }
}
