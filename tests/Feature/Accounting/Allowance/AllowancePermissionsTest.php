<?php

namespace Tests\Feature\Accounting\Allowance;

use App\Models\AccountingAllowancePosting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Lead's call 3 and 7: reading the allowance needs `accounting:view`, posting
 * it needs `accounting:allowance` (super_admin, admin and accountant), and
 * every route needs `branches:view_all`.
 */
class AllowancePermissionsTest extends TestCase
{
    use AllowanceTestHelpers, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->grantTheAllowancePermission();
        $this->seedChartOfAccounts();
        $this->period($this->agedLoan(), 10, principal: 10000);
    }

    /** @return array<string, array{0: string}> */
    public static function readRoutes(): array
    {
        return [
            'the preview' => ['/api/accounting/allowance/preview'],
            'the postings list' => ['/api/accounting/allowance/postings'],
        ];
    }

    #[DataProvider('readRoutes')]
    public function test_reading_needs_accounting_view(string $uri): void
    {
        $this->actingAs($this->branchUser($this->branch, ['branches:view_all', 'loans:view']));

        $this->getJson($uri)->assertForbidden();
    }

    #[DataProvider('readRoutes')]
    public function test_reading_needs_branches_view_all(string $uri): void
    {
        $this->actingAs($this->branchUser($this->branch, ['accounting:view', 'accounting:allowance', 'loans:view']));

        $this->getJson($uri)->assertForbidden();
    }

    public function test_posting_needs_branches_view_all(): void
    {
        $expected = $this->preview()['totals']['adjustment'];
        $this->actingAs($this->branchUser($this->branch, ['accounting:view', 'accounting:allowance']));

        $this->postJson('/api/accounting/allowance/postings', ['as_of' => $this->today(), 'expected_adjustment' => $expected])
            ->assertForbidden();
        $this->assertSame(0, AccountingAllowancePosting::count());
    }

    /** @return array<string, array{0: string}> */
    public static function rolesWithoutTheAllowance(): array
    {
        return [
            'general bookkeeper' => ['general_bookkeeper'],
            'manager' => ['manager'],
        ];
    }

    #[DataProvider('rolesWithoutTheAllowance')]
    public function test_a_role_without_accounting_allowance_can_preview_but_not_post(string $role): void
    {
        $this->actingAs($this->userInRole($role));

        $preview = $this->preview();
        $this->assertFalse($preview['can_post']);
        $this->assertContains('You don\'t have permission to post the allowance for credit losses.', $preview['blocking_reasons']);

        $this->postJson('/api/accounting/allowance/postings', [
            'as_of' => $this->today(),
            'expected_adjustment' => $preview['totals']['adjustment'],
        ])->assertForbidden();
        $this->assertSame(0, AccountingAllowancePosting::count());
    }

    /** @return array<string, array{0: string}> */
    public static function rolesWithTheAllowance(): array
    {
        return [
            'super admin' => ['super_admin'],
            'admin' => ['admin'],
            'accountant' => ['accountant'],
        ];
    }

    #[DataProvider('rolesWithTheAllowance')]
    public function test_super_admin_admin_and_accountant_can_post(string $role): void
    {
        $user = $role === 'super_admin' ? $this->admin : $this->userInRole($role);
        $this->actingAs($user);

        $preview = $this->preview();
        $this->assertTrue($preview['can_post']);

        $this->postTheRun()->assertCreated()->assertJsonPath('data.totals.adjustment', 50_000);
        $this->assertSame($user->id, AccountingAllowancePosting::query()->sole()->created_by);
    }
}
