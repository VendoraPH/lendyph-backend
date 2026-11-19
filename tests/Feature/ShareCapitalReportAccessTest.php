<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 decision 10 (FX-4): the Share Capital report is members' share
 * capital, totals and roster alike, so it needs `share_capital:view` on top of
 * `reports:view` (and `reports:export` for the roster). Without it the report
 * answers 403 in words, as GET /share-capital/ledger does.
 */
class ShareCapitalReportAccessTest extends TestCase
{
    use SetupLendyPH;

    private const REFUSAL = 'The Share Capital report shows members\' share capital, so it needs the permission to view share capital as well as the permission to view reports.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => now()->toDateString(), 'credit' => 4000, 'debit' => 0]);
    }

    public function test_reports_view_without_share_capital_view_is_refused_in_words(): void
    {
        $this->actingAs($this->user(['reports:view']));

        $response = $this->getJson('/api/reports/share-capital')
            ->assertForbidden()
            ->assertJsonPath('message', self::REFUSAL);

        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_reports_export_without_share_capital_view_gets_no_roster_either(): void
    {
        $this->actingAs($this->user(['reports:view', 'reports:export']));

        $this->getJson('/api/reports/share-capital')
            ->assertForbidden()
            ->assertJsonPath('message', self::REFUSAL)
            ->assertJsonMissingPath('data.by_member');
    }

    public function test_share_capital_view_alone_does_not_open_the_report(): void
    {
        $this->actingAs($this->user(['share_capital:view']));

        $this->getJson('/api/reports/share-capital')->assertForbidden();
    }

    public function test_both_permissions_read_the_totals_and_the_roster_follows_reports_export(): void
    {
        $this->actingAs($this->user(['reports:view', 'share_capital:view']));

        $totalsOnly = $this->getJson('/api/reports/share-capital')->assertOk()->json('data');
        $this->assertEqualsWithDelta(4000, $totalsOnly['credits'], 0.01);
        $this->assertNull($totalsOnly['by_member']);

        $this->actingAs($this->user(['reports:view', 'reports:export', 'share_capital:view']));

        $withRoster = $this->getJson('/api/reports/share-capital')->assertOk()->json('data');
        $this->assertCount(1, $withRoster['by_member']);
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo([...$permissions, BranchAccess::VIEW_ALL]);

        return $user;
    }
}
