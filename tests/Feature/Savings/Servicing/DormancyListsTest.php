<?php

namespace Tests\Feature\Savings\Servicing;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsSetting;
use App\Services\Savings\Notices\DormancyWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The dormant report's lists (gap round S4, Savings TASK 20): accounts
 * approaching dormancy within `dormancy_warning_days`, each with the day it
 * turns dormant by the nightly job's own rule, and the accounts reactivated
 * this Manila month, in the caller's branches. Today is Nov 10, 2026, with
 * 24 months and 60 days: today's cut-off is Nov 10, 2024 and the window's
 * Jan 9, 2025.
 */
class DormancyListsTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function memberAccount(array $overrides = [], ?Branch $branch = null): SavingsAccount
    {
        $member = Borrower::factory()->create(['branch_id' => ($branch ?? $this->branch)->id, 'status' => 'active']);

        return $this->account(['borrower_id' => $member->id, 'branch_id' => $member->branch_id, 'opened_on' => '2024-01-02', ...$overrides]);
    }

    public function test_the_approaching_list_and_its_edges(): void
    {
        $dueTonight = $this->memberAccount(['last_member_activity_on' => '2024-11-10']);
        $tomorrow = $this->memberAccount(['last_member_activity_on' => '2024-11-11', 'ledger_balance' => '120.50']);
        $lastDay = $this->memberAccount(['last_member_activity_on' => '2025-01-09', 'ledger_balance' => '10.00']);
        $this->memberAccount(['last_member_activity_on' => '2025-01-10']);
        $byOpening = $this->memberAccount(['last_member_activity_on' => null, 'opened_on' => '2024-12-01']);
        $this->memberAccount(['status' => 'dormant', 'dormant_since' => '2026-11-01', 'last_member_activity_on' => '2024-11-20']);
        $this->memberAccount(['status' => 'frozen', 'last_member_activity_on' => '2024-11-20']);

        $reader = $this->userWith(['reports:view', 'savings:view']);
        $response = $this->actingAs($reader)->getJson('/api/reports/savings/dormant?list=approaching')->assertOk()
            ->assertJsonPath('list', 'approaching')
            ->assertJsonPath('dormancy_warning_days', 60)
            ->assertJsonPath('totals.approaching_count', 3)
            ->assertJsonPath('totals.approaching_balance', '130.50');

        $rows = collect($response->json('data'))->keyBy('account_id');
        $this->assertSame([$tomorrow->id, $byOpening->id, $lastDay->id], $rows->keys()->all());
        $this->assertSame(['2026-11-11', 1], [$rows[$tomorrow->id]['dormant_on'], $rows[$tomorrow->id]['days_left']]);
        $this->assertSame(['2026-12-01', 21, '2024-12-01'], [$rows[$byOpening->id]['dormant_on'], $rows[$byOpening->id]['days_left'], $rows[$byOpening->id]['last_member_activity_on']]);
        $this->assertSame(['2027-01-09', 60], [$rows[$lastDay->id]['dormant_on'], $rows[$lastDay->id]['days_left']]);
        $this->assertArrayNotHasKey($dueTonight->id, $rows->all());

        $this->getJson('/api/reports/savings/dormant')->assertOk()
            ->assertJsonPath('list', 'dormant')
            ->assertJsonPath('totals.account_count', 1)
            ->assertJsonPath('totals.approaching_count', 3);
        $this->getJson('/api/reports/savings/dormant?list=sleepy')->assertUnprocessable()
            ->assertJsonPath('errors.list.0', 'Choose the dormant accounts or the ones approaching dormancy.');
    }

    public function test_the_warning_days_setting_narrows_the_window(): void
    {
        SavingsSetting::current()->forceFill(['dormancy_warning_days' => 1])->save();
        $tomorrow = $this->memberAccount(['last_member_activity_on' => '2024-11-11']);
        $this->memberAccount(['last_member_activity_on' => '2024-11-12']);

        $this->actingAs($this->userWith(['reports:view', 'savings:view']))->getJson('/api/reports/savings/dormant?list=approaching')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_id', $tomorrow->id);
    }

    public function test_the_dormant_date_follows_the_nightly_job_at_month_end(): void
    {
        SavingsSetting::current()->forceFill(['dormancy_months' => 1])->save();
        $account = $this->memberAccount(['last_member_activity_on' => '2026-10-31']);
        $window = new DormancyWindow(CarbonImmutable::parse('2026-11-10'), 1, 60);

        $this->assertSame('2026-12-01', $window->dormantOn($account)->toDateString());

        $this->on('2026-11-30', fn () => $this->artisan('savings:mark-dormant')->assertSuccessful()->run());
        $this->assertSame('active', $account->refresh()->status);
        $this->on('2026-12-01', fn () => $this->artisan('savings:mark-dormant')->assertSuccessful()->run());
        $this->assertSame('dormant', $account->refresh()->status);
    }

    public function test_reactivated_this_month_counts_each_account_once_in_the_callers_branches(): void
    {
        $elsewhere = Branch::factory()->create();
        $here = $this->memberAccount(['status' => 'dormant', 'dormant_since' => '2026-10-01', 'last_member_activity_on' => '2024-09-01']);
        $lastMonth = $this->memberAccount(['status' => 'dormant', 'dormant_since' => '2026-10-01', 'last_member_activity_on' => '2024-09-01']);
        $there = $this->memberAccount(['status' => 'dormant', 'dormant_since' => '2026-10-01', 'last_member_activity_on' => '2024-09-01'], $elsewhere);

        $this->fund($lastMonth, 10_000, '2026-10-31');
        $this->fund($here, 10_000, '2026-11-01');
        $here->forceFill(['status' => 'dormant', 'dormant_since' => '2026-11-02'])->save();
        $this->fund($here, 10_000);
        $this->fund($there, 10_000);

        $this->actingAs($this->userWith(['reports:view', 'savings:view']))->getJson('/api/reports/savings/dormant')->assertOk()
            ->assertJsonPath('totals.reactivated_this_month', 1);
        $this->actingAs($this->admin)->getJson('/api/reports/savings/dormant')->assertOk()
            ->assertJsonPath('totals.reactivated_this_month', 2);
        $this->getJson("/api/reports/savings/dormant?branch_id={$elsewhere->id}")->assertOk()
            ->assertJsonPath('totals.reactivated_this_month', 1);
    }
}
