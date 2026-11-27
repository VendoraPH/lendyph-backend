<?php

namespace Tests\Feature\ProfitSharing;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The Profit Sharing report's other outcomes: a rounding remainder reported,
 * a negative profit shared with nobody, members with no or negative share
 * capital left out, and the cutoff date's rules.
 */
class ProfitSharingOutcomesTest extends TestCase
{
    use BuildsProfitSharing, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
    }

    public function test_the_rounding_remainder_is_reported_and_never_spread(): void
    {
        foreach (['Aquino', 'Bautista', 'Cruz'] as $last) {
            $this->capital($this->member('Member', $last), '2026-01-05', 1000);
        }
        $this->payment($this->releasedLoan('2025-11-03 09:00:00'), '2026-03-01', interest: 100);

        $data = $this->profitSharing('2026-06-30')->assertOk()->json('data');

        $this->assertEquals(100, $data['profit']['distributable_profit']);
        $this->assertEquals([33.33, 33.33, 33.33], array_column($data['rows'], 'earnings'));
        $this->assertEquals([33.3333, 33.3333, 33.3333], array_column($data['rows'], 'share_percent'));
        $this->assertEquals(99.99, $data['totals']['earnings']);
        $this->assertEquals(0.01, $data['totals']['rounding_remainder']);
        // The rows' own shares added up, so the column's total reads true.
        $this->assertEquals(99.9999, $data['totals']['share_percent']);
        $this->assertEquals(3099.99, $data['totals']['capital_plus_earnings']);
    }

    public function test_a_negative_profit_reads_as_such_and_shares_nothing(): void
    {
        $this->seedChartOfAccounts();
        $this->capital($this->member('Ana', 'Aquino'), '2026-01-05', 4000);
        $this->capital($this->member('Ben', 'Bautista'), '2026-01-05', 6000);
        $this->payment($this->releasedLoan('2025-11-03 09:00:00'), '2026-03-01', interest: 500);
        $this->expense('2026-04-01', 200_000);

        $data = $this->profitSharing('2026-06-30')->assertOk()->json('data');

        $this->assertEquals(500, $data['profit']['lending_income']);
        $this->assertEquals(2000, $data['profit']['expenses']);
        $this->assertEquals(-1500, $data['profit']['distributable_profit']);
        $this->assertSame('no_profit', $data['distribution']);
        $this->assertEquals([0, 0], array_column($data['rows'], 'earnings'));
        $this->assertEquals([4000, 6000], array_column($data['rows'], 'capital_plus_earnings'));
        $this->assertEquals([40, 60], array_column($data['rows'], 'share_percent'));
        $this->assertEquals(0, $data['totals']['earnings']);
        $this->assertEquals(10000, $data['totals']['capital_plus_earnings']);
        $this->assertEquals(0, $data['totals']['rounding_remainder']);
    }

    public function test_members_with_no_or_negative_share_capital_are_left_out(): void
    {
        $this->capital($this->member('Ana', 'Aquino'), '2026-01-05', 3000);
        $zero = $this->member('Ben', 'Bautista');
        $this->capital($zero, '2026-01-05', 500);
        $this->capital($zero, '2026-02-05', 0, 500);
        $negative = $this->member('Carla', 'Cruz');
        $this->capital($negative, '2026-02-05', 0, 250);
        $this->payment($this->releasedLoan('2025-11-03 09:00:00'), '2026-03-01', interest: 300);

        $data = $this->profitSharing('2026-06-30')->assertOk()->json('data');

        $this->assertSame(['Ana Aquino'], array_column($data['rows'], 'member_name'));
        $this->assertEquals([300], array_column($data['rows'], 'earnings'));
        $this->assertSame(1, $data['totals']['member_count']);
        $this->assertEquals(3000, $data['totals']['share_capital']);
        $this->assertSame(1, $data['totals']['members_with_negative_balance']);
    }

    public function test_a_pending_or_rejected_registration_holds_no_membership(): void
    {
        $this->capital($this->member('Ana', 'Aquino'), '2026-01-05', 3000);
        foreach (['pending' => 'Bautista', 'rejected' => 'Cruz'] as $status => $last) {
            $registration = $this->member('Applicant', $last);
            $registration->forceFill(['status' => $status])->save();
            $this->capital($registration, '2026-01-05', 2000);
        }
        $this->payment($this->releasedLoan('2025-11-03 09:00:00'), '2026-03-01', interest: 300);

        $data = $this->profitSharing('2026-06-30')->assertOk()->json('data');

        $this->assertSame(['Ana Aquino'], array_column($data['rows'], 'member_name'));
        $this->assertEquals(3000, $data['totals']['share_capital']);
        $this->assertEquals([300], array_column($data['rows'], 'earnings'));
    }

    public function test_with_no_share_capital_the_profit_stands_and_nobody_shares_it(): void
    {
        $this->payment($this->releasedLoan('2025-11-03 09:00:00'), '2026-03-01', interest: 300);

        $data = $this->profitSharing('2026-06-30')->assertOk()->json('data');

        $this->assertEquals(300, $data['profit']['distributable_profit']);
        $this->assertSame('no_share_capital', $data['distribution']);
        $this->assertSame([], $data['rows']);
        $this->assertNull($data['totals']['share_percent']);
        $this->assertEquals(0, $data['totals']['earnings']);
        $this->assertEquals(0, $data['totals']['rounding_remainder']);
    }

    public function test_the_cutoff_date_is_a_written_date_no_later_than_today_and_today_by_default(): void
    {
        $sentence = 'The cutoff date must be a date written as year-month-day, such as 2026-11-24.';

        // Left out, it is today, as the other reports' dates default.
        $this->getJson('/api/reports/profit-sharing')
            ->assertOk()
            ->assertJsonPath('data.cutoff_date', '2026-11-24')
            ->assertJsonPath('data.period_from', '2026-01-01');

        foreach (['20260630', '2026/06/30', '2026-02-30', 'June 30'] as $written) {
            $this->profitSharing($written)
                ->assertUnprocessable()
                ->assertJsonPath('errors.cutoff_date.0', $sentence);
        }

        $this->profitSharing('2026-11-25')
            ->assertUnprocessable()
            ->assertJsonPath('errors.cutoff_date.0', 'The cutoff date can\'t be after today.');

        $this->profitSharing('2026-11-24')->assertOk();
    }
}
