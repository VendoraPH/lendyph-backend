<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/staff
 *
 * The account-officer pickers (new loan, restructure, loan detail) listed
 * staff through GET /api/users, which only admin and super_admin may call, so
 * a loan officer opened every picker to an empty list. The owner's decision:
 * whoever can create or edit a loan gets a narrow staff list — active users,
 * names only — and the users list itself stays admin-only. Restructuring a
 * loan picks an officer too, so `loans:restructure` opens the list as well.
 */
class StaffListTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * $this->admin is the seeded super_admin, who passes every check through
     * Gate::before. Anything about who may call this has to be acted out as
     * someone else.
     */
    private function userWithRole(string $role, array $attributes = []): User
    {
        return tap(
            User::factory()->create(['branch_id' => $this->branch->id, ...$attributes]),
            fn (User $user) => $user->assignRole(Role::where('name', $role)->firstOrFail()),
        );
    }

    /**
     * A caller holding exactly these permissions, which is a role the roles
     * screen can build and the seeder does not ship.
     */
    private function userWithOnlyPermissions(string ...$permissions): User
    {
        $role = Role::create(['name' => 'spec:'.implode(',', $permissions), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return tap(
            User::factory()->create(['branch_id' => $this->branch->id]),
            fn (User $user) => $user->assignRole($role),
        );
    }

    /**
     * @return list<int>
     */
    private function staffIds(string $query = ''): array
    {
        $url = '/api/staff?per_page=100'.($query === '' ? '' : '&'.$query);

        return array_column($this->getJson($url)->assertOk()->json('data'), 'id');
    }

    public function test_a_loan_officer_lists_active_staff_as_id_and_full_name_only(): void
    {
        $officer = $this->userWithRole('loan_officer');
        $colleague = $this->userWithRole('cashier', [
            'first_name' => 'Rosario',
            'last_name' => 'Bautista',
            'username' => 'rbautista_handle',
            'email' => 'rosario.private@example.test',
            'mobile_number' => '09170000001',
        ]);
        $gone = $this->userWithRole('loan_officer', ['status' => 'inactive']);

        $this->assertFalse($officer->can('users:view'));
        $this->actingAs($officer);

        $response = $this->getJson('/api/staff?per_page=100')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $rows = $response->json('data');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(['id', 'full_name'], array_keys($row), 'A staff row must carry exactly id and full_name.');
            $this->assertIsInt($row['id']);
            $this->assertIsString($row['full_name']);
        }

        $ids = array_column($rows, 'id');
        $this->assertContains($officer->id, $ids);
        $this->assertContains($colleague->id, $ids);
        $this->assertNotContains($gone->id, $ids, 'An inactive user cannot be an account officer, so it must not be offered.');

        // Same `full_name` LoanResource returns for `account_officer`.
        $this->assertSame('Rosario Bautista', collect($rows)->firstWhere('id', $colleague->id)['full_name']);

        // Nothing user management protects leaks through the row.
        $response->assertDontSee('rbautista_handle')
            ->assertDontSee('rosario.private@example.test')
            ->assertDontSee('09170000001');
    }

    public function test_a_role_holding_only_loans_update_can_list_staff(): void
    {
        $processor = $this->userWithRole('loan_processor');

        $this->assertTrue($processor->can('loans:update'));
        $this->assertFalse($processor->can('loans:create'));
        $this->actingAs($processor);

        $this->getJson('/api/staff')->assertOk();
    }

    public function test_a_role_holding_only_loans_create_can_list_staff(): void
    {
        $this->actingAs($this->userWithOnlyPermissions('loans:create'));

        $this->getJson('/api/staff')->assertOk();
    }

    /**
     * The restructure form has an officer picker of its own, and
     * RestructureLoanRequest asks for `loans:restructure` alone — so a role
     * built to restructure without creating or editing loans would otherwise
     * open it to a 403.
     */
    public function test_a_role_holding_only_loans_restructure_lists_staff_as_id_and_full_name_only(): void
    {
        $restructurer = $this->userWithOnlyPermissions('loans:restructure');

        $this->assertFalse($restructurer->canAny(['loans:create', 'loans:update']));
        $this->assertFalse($restructurer->can('users:view'));
        $this->actingAs($restructurer);

        $rows = $this->getJson('/api/staff?per_page=100')->assertOk()->json('data');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(['id', 'full_name'], array_keys($row), 'A staff row must carry exactly id and full_name.');
        }

        $this->assertContains($restructurer->id, array_column($rows, 'id'));
    }

    /**
     * The whole restructure picker, acted out by a role that can see and
     * restructure loans but neither create nor edit them: the officer it
     * picks from the staff list is the one the new loan carries.
     */
    public function test_a_restructure_only_role_picks_the_new_loans_account_officer_from_the_staff_list(): void
    {
        $source = $this->createReleasedLoan();
        $colleague = $this->userWithRole('cashier', ['first_name' => 'Maricel', 'last_name' => 'Macaraeg']);
        $restructurer = $this->userWithOnlyPermissions('loans:view', 'loans:restructure');

        $this->assertFalse($restructurer->canAny(['loans:create', 'loans:update']));
        $this->actingAs($restructurer);

        $rows = $this->getJson('/api/staff?search=Macaraeg')->assertOk()->json('data');
        $this->assertSame([['id' => $colleague->id, 'full_name' => 'Maricel Macaraeg']], $rows);

        $pickedId = $rows[0]['id'];

        // The source loan's whole balance (₱60,000 principal + ₱10,800
        // interest), so no shortfall asks for `loans:write_off` as well.
        $response = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800.00,
            'start_date' => now()->toDateString(),
            'account_officer_id' => $pickedId,
        ])->assertCreated()
            ->assertJsonPath('data.source_loan_id', $source->id)
            ->assertJsonPath('data.account_officer_id', $pickedId);

        $this->assertSame($colleague->id, Loan::findOrFail($response->json('data.id'))->account_officer_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithoutLoanWrite(): array
    {
        return [
            'viewer' => ['viewer'],
            'cashier' => ['cashier'],
            'collector' => ['collector'],
            'general_bookkeeper' => ['general_bookkeeper'],
            'manager' => ['manager'],
            'bod1' => ['bod1'],
        ];
    }

    /**
     * 403, not 404: this is not user management, and a list that binds no id
     * has no existence to mask.
     */
    #[DataProvider('rolesWithoutLoanWrite')]
    public function test_a_seeded_role_without_loans_create_update_or_restructure_is_refused(string $role): void
    {
        $caller = $this->userWithRole($role);

        $this->assertFalse($caller->canAny(['loans:create', 'loans:update', 'loans:restructure']), "The seeded [{$role}] now holds a permission that opens the staff list.");
        $this->actingAs($caller);

        $this->getJson('/api/staff')
            ->assertForbidden()
            ->assertJsonMissingPath('data');
    }

    /**
     * Roles the roles screen can build, where the seeded ones above all hold
     * `loans:view`.
     *
     * @return array<string, array{list<string>}>
     */
    public static function permissionSetsWithoutLoanWrite(): array
    {
        return [
            'only loans:view' => [['loans:view']],
            'no loans:* permission at all' => [['dashboard:view', 'borrowers:view', 'payments:view']],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('permissionSetsWithoutLoanWrite')]
    public function test_a_custom_role_without_loans_create_update_or_restructure_is_refused(array $permissions): void
    {
        $this->actingAs($this->userWithOnlyPermissions(...$permissions));

        $this->getJson('/api/staff')
            ->assertForbidden()
            ->assertJsonMissingPath('data');
    }

    /**
     * The gate is those three and no wider. Read from the permissions table
     * rather than listed, so a `loans:*` permission added later is covered
     * without anyone remembering this test.
     */
    public function test_every_other_loans_permission_together_is_refused(): void
    {
        $others = Permission::where('guard_name', 'web')
            ->where('name', 'like', 'loans:%')
            ->whereNotIn('name', ['loans:create', 'loans:update', 'loans:restructure'])
            ->pluck('name')
            ->all();

        $this->assertContains('loans:extend', $others);
        $this->assertContains('loans:write_off', $others);
        $this->actingAs($this->userWithOnlyPermissions(...$others));

        $this->getJson('/api/staff')
            ->assertForbidden()
            ->assertJsonMissingPath('data');
    }

    public function test_admin_and_super_admin_can_list_staff(): void
    {
        $this->getJson('/api/staff')->assertOk();

        $this->actingAs($this->userWithRole('admin'));

        $this->getJson('/api/staff')->assertOk();
    }

    /**
     * The staff list is an addition, not a widening: the users list answers a
     * loan officer exactly as it did before — a 403 from UserController::index
     * (only the five `{user}` routes mask a refusal as 404) — and still gives
     * admin and super_admin the full user shape.
     */
    public function test_the_users_list_is_still_admin_only(): void
    {
        $target = $this->userWithRole('cashier');
        $officer = $this->userWithRole('loan_officer');

        $this->actingAs($officer);

        $this->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonMissingPath('data');
        $this->getJson("/api/users/{$target->id}")->assertNotFound();

        $fullShape = [
            'data' => ['*' => ['id', 'first_name', 'last_name', 'full_name', 'username', 'email', 'mobile_number', 'status', 'roles']],
            'links',
            'meta' => ['stats' => ['active', 'inactive']],
        ];

        $this->actingAs($this->userWithRole('admin'));
        $this->getJson('/api/users')->assertOk()->assertJsonStructure($fullShape);

        $this->actingAs($this->admin);
        $this->getJson('/api/users')->assertOk()->assertJsonStructure($fullShape);
    }

    /**
     * Every name in the search specs is pinned, the caller's included, and
     * none of the tokens occur in Faker's en_US name lists — a factory user
     * called "Marisol" would otherwise make these a coin flip.
     */
    private function actAsNamedLoanOfficer(): void
    {
        $this->actingAs($this->userWithRole('loan_officer', ['first_name' => 'Spec', 'last_name' => 'Caller']));
    }

    public function test_search_matches_first_name_last_name_and_the_full_name(): void
    {
        $luzviminda = User::factory()->create(['first_name' => 'Luzviminda', 'last_name' => 'Quisumbing']);
        $luzvimar = User::factory()->create(['first_name' => 'Luzvimar', 'last_name' => 'Dimaculangan']);
        User::factory()->inactive()->create(['first_name' => 'Luzviminda', 'last_name' => 'Quisumbing']);

        $this->actAsNamedLoanOfficer();

        $this->assertSame([$luzviminda->id], $this->staffIds('search=Luzviminda'));
        $this->assertSame([$luzviminda->id], $this->staffIds('search=Quisumbing'));
        $this->assertSame([$luzviminda->id], $this->staffIds('search='.urlencode('Luzviminda Quisumbing')));
        // Across the space: neither name column holds this, only the CONCAT can.
        $this->assertSame([$luzviminda->id], $this->staffIds('search='.urlencode('minda Quisum')));
        // Both, ordered by first name: Luzvimar before Luzviminda.
        $this->assertSame([$luzvimar->id, $luzviminda->id], $this->staffIds('search=Luzvim'));
    }

    /**
     * Searching a field the response does not return would turn the filter
     * into an oracle for it: `?search=gmail` would reveal who has a gmail
     * address to a caller who is not allowed to read addresses.
     */
    public function test_search_does_not_match_username_or_email(): void
    {
        $hidden = User::factory()->create([
            'first_name' => 'Liwayway',
            'last_name' => 'Dalisay',
            'username' => 'zqxhandle',
            'email' => 'liwayway@zqxmailhost.test',
        ]);

        $this->actAsNamedLoanOfficer();

        $this->assertSame([$hidden->id], $this->staffIds('search=Liwayway'));
        $this->assertSame([], $this->staffIds('search=zqxhandle'));
        $this->assertSame([], $this->staffIds('search=zqxmailhost'));
    }

    public function test_search_is_bounded(): void
    {
        $this->actingAs($this->userWithRole('loan_officer'));

        $this->getJson('/api/staff?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);

        $this->getJson('/api/staff?search='.str_repeat('a', 100))->assertOk();
    }

    public function test_per_page_defaults_to_15_and_is_clamped_to_100(): void
    {
        $this->actingAs($this->userWithRole('loan_officer'));

        $this->assertSame(15, $this->getJson('/api/staff')->assertOk()->json('meta.per_page'));
        $this->assertSame(100, $this->getJson('/api/staff?per_page=9999')->assertOk()->json('meta.per_page'));

        $this->getJson('/api/staff?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/staff?per_page=abc')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_it_pages(): void
    {
        User::factory()->count(4)->create();

        $this->actingAs($this->userWithRole('loan_officer'));

        $total = User::where('status', 'active')->count();
        $response = $this->getJson('/api/staff?per_page=2&page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.current_page'));
        $this->assertSame($total, $response->json('meta.total'));
        $this->assertSame((int) ceil($total / 2), $response->json('meta.last_page'));
    }

    public function test_it_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/staff')->assertUnauthorized();
    }

    /**
     * `routeIs('users.*')` puts a caller holding no users:* permission on a
     * 10/min ceiling. A loan officer holds none, so a staff route named into
     * that family would throttle the picker it exists for.
     */
    public function test_the_route_sits_outside_the_user_management_limiter(): void
    {
        $route = Route::getRoutes()->getByName('staff.index');

        $this->assertNotNull($route);
        $this->assertSame('api/staff', $route->uri());

        $officer = $this->userWithRole('loan_officer');

        $request = Request::create('/api/staff', 'GET');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $officer);

        $limit = RateLimiter::limiter('api')($request);

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame((string) $officer->id, (string) $limit->key);
    }

    /**
     * End to end through the real middleware: the eleventh call in a minute is
     * still answered, which it would not be under the users.* denied ceiling.
     */
    public function test_a_loan_officer_is_not_cut_off_after_ten_calls(): void
    {
        $this->actingAs($this->userWithRole('loan_officer'));

        foreach (range(1, 11) as $call) {
            $this->getJson('/api/staff')->assertOk();
        }
    }
}
