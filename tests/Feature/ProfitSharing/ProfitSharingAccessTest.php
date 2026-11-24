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
 * Who reads the Profit Sharing report (money review, 2026-11-24).
 *
 * It is a whole-cooperative report, and each figure needs its source's
 * permission, since the profit less the income gives away the rest: it needs
 * reports:view, share_capital:view, expenses:view, accounting:view and
 * branches:view_all, and answers 403 in words without any one of them. The
 * member rows also need reports:export, like the Share Capital report's
 * roster. A sent branch_id changes nothing.
 *
 * Main Branch: Ana holds ₱10,000.00, ₱1,000.00 interest, ₱100.00 expenses.
 * North Branch: Ben holds ₱30,000.00, ₱3,000.00 interest, ₱300.00 expenses.
 * Head office: ₱50.00 expenses with no branch.
 * The cooperative: ₱4,000.00 less ₱450.00 is ₱3,550.00, shared 25% and 75%.
 */
class ProfitSharingAccessTest extends TestCase
{
    use BuildsProfitSharing, PostsJournals, SetupLendyPH;

    private const REFUSAL = 'The Profit Sharing report covers the whole cooperative\'s share capital, income, expenses and credit losses, so it needs the permissions to view reports, share capital, expenses and the books, and access to every branch.';

    private const NEEDED = ['reports:view', 'share_capital:view', 'expenses:view', 'accounting:view', BranchAccess::VIEW_ALL];

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

    public function test_each_permission_it_needs_is_refused_in_words_without_it(): void
    {
        foreach (self::NEEDED as $missing) {
            $held = array_values(array_diff([...self::NEEDED, 'reports:export'], [$missing]));
            $this->actingAs($this->user($held));

            $response = $this->profitSharing(self::CUTOFF)
                ->assertForbidden()
                ->assertJsonPath('message', self::REFUSAL);

            $this->assertArrayNotHasKey('data', $response->json(), $missing);
        }
    }

    public function test_the_member_rows_follow_reports_export_and_the_figures_stay(): void
    {
        $this->actingAs($this->user(self::NEEDED));

        $data = $this->profitSharing(self::CUTOFF)->assertOk()->json('data');

        $this->assertNull($data['rows']);
        $this->assertSame('Each member\'s share capital and earnings are limited to roles that can export reports. The profit and the totals are complete.', $data['rows_omitted']['message']);
        $this->assertEquals(3550, $data['profit']['distributable_profit']);
        $this->assertEquals(40000, $data['totals']['share_capital']);
        $this->assertEquals(3550, $data['totals']['earnings']);
        $this->assertSame(2, $data['totals']['member_count']);

        $this->actingAs($this->user([...self::NEEDED, 'reports:export']));

        $rows = $this->profitSharing(self::CUTOFF)->assertOk()->json('data.rows');
        $this->assertEquals([887.5, 2662.5], array_column($rows, 'earnings'));
    }

    public function test_it_is_the_whole_cooperative_and_a_sent_branch_changes_nothing(): void
    {
        $this->actingAs($this->user([...self::NEEDED, 'reports:export']));

        $whole = $this->profitSharing(self::CUTOFF)->assertOk()->json('data');

        // Every branch's income and expenses, head office's included, shared
        // among every member by all members' share capital.
        $this->assertEquals(4000, $whole['profit']['lending_income']);
        $this->assertEquals(450, $whole['profit']['expenses']);
        $this->assertSame(['Ana Aquino', 'Ben Bautista'], array_column($whole['rows'], 'member_name'));
        $this->assertEquals([25, 75], array_column($whole['rows'], 'share_percent'));
        $this->assertArrayNotHasKey('branch_scope', $whole);

        foreach ([$this->branch->id, $this->north->id, 0, 'abc'] as $branch) {
            $sent = $this->getJson('/api/reports/profit-sharing?cutoff_date='.self::CUTOFF.'&branch_id='.$branch)
                ->assertOk()
                ->json('data');

            $this->assertEquals($whole['profit'], $sent['profit'], (string) $branch);
            $this->assertEquals($whole['rows'], $sent['rows'], (string) $branch);
            $this->assertEquals($whole['totals'], $sent['totals'], (string) $branch);
        }
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
