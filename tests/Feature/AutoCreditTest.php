<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class AutoCreditTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_status_returns_expected_keys(): void
    {
        $this->getJson('/api/auto-credit/status')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'active_count', 'total_to_credit', 'disabled_count',
                    'no_pledge_count', 'last_run', 'active_members',
                    'disabled_members', 'no_pledge_members',
                ],
            ]);
    }

    public function test_process_creates_ledger_entries_for_eligible_pledges(): void
    {
        $b1 = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $b2 = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $b3 = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        // Eligible: auto_credit=true, amount > 0
        $b1->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);
        $b2->shareCapitalPledge->update(['amount' => 1000, 'auto_credit' => true]);

        // Not eligible: auto_credit=false (default)
        $b3->shareCapitalPledge->update(['amount' => 500]);

        $response = $this->postJson('/api/auto-credit/process');

        $response->assertCreated()
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.total_amount', 1500)
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseCount('share_capital_ledger', 2);
        $this->assertDatabaseHas('share_capital_ledger', ['borrower_id' => $b1->id, 'credit' => 500]);
        $this->assertDatabaseHas('share_capital_ledger', ['borrower_id' => $b2->id, 'credit' => 1000]);
    }

    public function test_process_skips_pledges_with_zero_amount(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        // auto_credit=true but amount=0 — should NOT be processed
        $borrower->shareCapitalPledge->update(['auto_credit' => true]);

        $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 0);

        $this->assertDatabaseCount('share_capital_ledger', 0);
    }

    public function test_status_reflects_active_count(): void
    {
        $b1 = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $b2 = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $b1->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);
        $b2->shareCapitalPledge->update(['amount' => 300, 'auto_credit' => false]);

        $response = $this->getJson('/api/auto-credit/status')->assertOk();

        $this->assertEquals(1, $response->json('data.active_count'));
        $this->assertEquals(500.0, $response->json('data.total_to_credit'));
        $this->assertEquals(1, $response->json('data.disabled_count'));
    }

    /**
     * The financial-safety test.
     *
     * toggleAutoCredit() performs no status check, so a pending applicant's
     * auto-created pledge can carry auto_credit = true with a real amount —
     * and now that the pledge list is member-scoped, nobody can see it to turn
     * it off. The run must never post ledger credits for a non-member.
     */
    public function test_process_skips_a_pending_borrower_even_when_auto_credit_is_enabled(): void
    {
        $applicant = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);
        $applicant->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);

        $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.total_amount', 0);

        $this->assertDatabaseCount('share_capital_ledger', 0);
    }

    public function test_process_credits_inactive_and_blacklisted_members(): void
    {
        $inactive = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'inactive']);
        $blacklisted = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'blacklisted']);

        $inactive->shareCapitalPledge->update(['amount' => 200, 'auto_credit' => true]);
        $blacklisted->shareCapitalPledge->update(['amount' => 300, 'auto_credit' => true]);

        $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.total_amount', 500);

        $this->assertDatabaseCount('share_capital_ledger', 2);
    }

    public function test_status_excludes_pending_applicants_from_the_no_pledge_figures(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $applicant = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/auto-credit/status')->assertOk();

        $this->assertSame(1, $response->json('data.no_pledge_count'), 'Only the member is missing a pledge amount.');

        $names = collect($response->json('data.no_pledge_members'))->pluck('borrower_name');
        $this->assertContains($member->full_name, $names);
        $this->assertNotContains($applicant->full_name, $names);
    }

    public function test_status_includes_inactive_and_blacklisted_members(): void
    {
        $inactive = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'inactive']);
        $blacklisted = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'blacklisted']);

        $inactive->shareCapitalPledge->update(['amount' => 200, 'auto_credit' => true]);
        $blacklisted->shareCapitalPledge->update(['amount' => 300, 'auto_credit' => false]);

        $response = $this->getJson('/api/auto-credit/status')->assertOk();

        $this->assertSame(1, $response->json('data.active_count'));
        $this->assertEquals(200.0, $response->json('data.total_to_credit'));
        $this->assertSame(1, $response->json('data.disabled_count'));

        $activeNames = collect($response->json('data.active_members'))->pluck('borrower_name');
        $disabledNames = collect($response->json('data.disabled_members'))->pluck('borrower_name');
        $this->assertContains($inactive->full_name, $activeNames);
        $this->assertContains($blacklisted->full_name, $disabledNames);
    }

    public function test_last_run_appears_in_status_after_processing(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $borrower->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);

        $this->postJson('/api/auto-credit/process');

        $response = $this->getJson('/api/auto-credit/status')->assertOk();
        $this->assertNotNull($response->json('data.last_run'));
        $this->assertEquals('completed', $response->json('data.last_run.status'));
    }
}
