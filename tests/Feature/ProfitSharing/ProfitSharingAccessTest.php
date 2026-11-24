<?php

namespace Tests\Feature\ProfitSharing;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Who reads the Profit Sharing report, and what of it.
 *
 * It is members' share capital, so like the Share Capital report (Phase 6
 * decision 10) it needs `share_capital:view` on top of `reports:view`, and
 * the member rows also need `reports:export`. It follows the reports' branch
 * rule: a branch user's figures are their branches' own lending income,
 * expenses and losses, shared among the members now in those branches.
 *
 * Main Branch: Ana holds ₱10,000.00, ₱1,000.00 interest, ₱100.00 expenses.
 * North Branch: Ben holds ₱30,000.00, ₱3,000.00 interest, ₱300.00 expenses.
 * Head office: ₱50.00 expenses with no branch.
 */
class ProfitSharingAccessTest extends TestCase
{
    use BuildsProfitSharing, PostsJournals, SetupLendyPH;

    private const REFUSAL = 'The Profit Sharing report shows members\' share capital, so it needs the permission to view share capital as well as the permission to view reports.';

    private const CUTOFF = '2026-06-30';

    private Branch $north;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->north = Branch::factory()->create(['name' => 'North Branch']);

        $this->capital($this->member('Ana', 'Aquino'), '2026-01-05', 10000);
        $this->capital($this->member('Ben', 'Bautista', $this->north), '2026-01-05', 30000);

        $this->payment($this->releasedLoan('2025-11-03 09:00:00'), '2026-03-01', interest: 1000);
        $this->payment($this->releasedLoan('2025-11-03 09:00:00', branch: $this->north), '2026-03-01', interest: 3000);

        $this->expense('2026-04-01', 10_000);
        $this->expense('2026-04-01', 30_000, $this->north);
        $this->expense('2026-04-01', 5_000, withBranch: false);
    }

    public function test_reports_view_without_share_capital_view_is_refused_in_words(): void
    {
        $this->actingAs($this->user(['reports:view', 'reports:export'], viewAll: true));

        $response = $this->profitSharing(self::CUTOFF)
            ->assertForbidden()
            ->assertJsonPath('message', self::REFUSAL);

        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_share_capital_view_alone_does_not_open_it(): void
    {
        $this->actingAs($this->user(['share_capital:view'], viewAll: true));

        $this->profitSharing(self::CUTOFF)
            ->assertForbidden()
            ->assertJsonPath('message', self::REFUSAL);
    }

    public function test_the_member_rows_follow_reports_export_and_the_figures_stay(): void
    {
        $this->actingAs($this->user(['reports:view', 'share_capital:view'], viewAll: true));

        $data = $this->profitSharing(self::CUTOFF)->assertOk()->json('data');

        $this->assertNull($data['rows']);
        $this->assertSame('Each member\'s share capital and earnings are limited to roles that can export reports. The profit and the totals are complete.', $data['rows_omitted']['message']);
        $this->assertEquals(3550, $data['profit']['distributable_profit']);
        $this->assertEquals(40000, $data['totals']['share_capital']);
        $this->assertEquals(3550, $data['totals']['earnings']);
        $this->assertSame(2, $data['totals']['member_count']);

        $this->actingAs($this->user(['reports:view', 'share_capital:view', 'reports:export'], viewAll: true));

        $rows = $this->profitSharing(self::CUTOFF)->assertOk()->json('data.rows');
        $this->assertEquals([887.5, 2662.5], array_column($rows, 'earnings'));
    }

    public function test_a_branch_user_reads_their_branches_own_figures_and_members(): void
    {
        $this->actingAs($this->user(['reports:view', 'share_capital:view', 'reports:export']));

        $data = $this->profitSharing(self::CUTOFF)->assertOk()->json('data');

        $this->assertSame('branches', $data['branch_scope']);
        $this->assertEquals(1000, $data['profit']['lending_income']);
        // Not North's ₱300.00, nor head office's ₱50.00 with no branch.
        $this->assertEquals(100, $data['profit']['expenses']);
        $this->assertEquals(900, $data['profit']['distributable_profit']);
        $this->assertSame(['Ana Aquino'], array_column($data['rows'], 'member_name'));
        $this->assertEquals([100], array_column($data['rows'], 'share_percent'));
        $this->assertEquals([900], array_column($data['rows'], 'earnings'));
    }

    public function test_another_branch_is_refused_to_a_branch_user(): void
    {
        $this->actingAs($this->user(['reports:view', 'share_capital:view', 'reports:export']));

        $this->profitSharing(self::CUTOFF, $this->north->id)->assertForbidden();
    }

    public function test_head_office_reads_one_branch_as_that_branch_reads_itself(): void
    {
        $this->actingAs($this->user(['reports:view', 'share_capital:view', 'reports:export']));
        $own = $this->profitSharing(self::CUTOFF)->assertOk()->json('data');

        $this->actingAs($this->user(['reports:view', 'share_capital:view', 'reports:export'], viewAll: true));
        $picked = $this->profitSharing(self::CUTOFF, $this->branch->id)->assertOk()->json('data');

        $this->assertEquals($own['profit'], $picked['profit']);
        $this->assertEquals($own['rows'], $picked['rows']);
        $this->assertEquals($own['totals'], $picked['totals']);

        $whole = $this->profitSharing(self::CUTOFF)->assertOk()->json('data');
        $this->assertSame('organisation', $whole['branch_scope']);
        $this->assertEquals(4000, $whole['profit']['lending_income']);
        $this->assertEquals(450, $whole['profit']['expenses']);
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions, bool $viewAll = false): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo($viewAll ? [...$permissions, BranchAccess::VIEW_ALL] : $permissions);

        return $user;
    }
}
