<?php

namespace Tests\Feature\ProfitSharing;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/reports/profit-sharing (finish run round 2, decision 17; ClickUp
 * 14zfbg0e9gg), on a hand-checked example: three members holding ₱10,000.00,
 * ₱20,000.00 and ₱30,000.00 on the cutoff date, 30 June 2026, share the
 * year's distributable profit:
 *
 *   interest ₱5,000.00 + penalty ₱500.00 + processing fee ₱1,000.00
 *   − expenses ₱1,500.00 = ₱5,000.00
 *
 * so they earn ₱833.33, ₱1,666.67 and ₱2,500.00, with nothing left over.
 * Everything outside 1 January to 30 June 2026, voided, reversed or principal
 * is left out.
 */
class ProfitSharingReportTest extends TestCase
{
    use BuildsProfitSharing, PostsJournals, SetupLendyPH;

    private const CUTOFF = '2026-06-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        $ana = $this->member('Ana', 'Aquino');
        $this->capital($ana, '2025-12-01', 10000);

        $ben = $this->member('Ben', 'Bautista');
        $this->capital($ben, '2026-02-01', 25000);
        $this->capital($ben, '2026-05-01', 0, 5000);

        $carla = $this->member('Carla', 'Cruz');
        // On the cutoff date counts; the day after doesn't.
        $this->capital($carla, self::CUTOFF, 30000);
        $this->capital($carla, '2026-07-01', 5000);

        // Paid in and taken out again: nothing held on the cutoff date.
        $dante = $this->member('Dante', 'Dizon');
        $this->capital($dante, '2026-01-15', 2000);
        $this->capital($dante, '2026-03-15', 0, 2000);

        // Joined after the cutoff date.
        $this->capital($this->member('Elena', 'Estrada'), '2026-08-01', 1000);

        $loan = $this->releasedLoan('2026-02-10 09:00:00', processingFee: 1000);
        $this->payment($loan, '2026-03-10', interest: 3000, penalty: 200, principal: 5000);
        $this->payment($loan, self::CUTOFF, interest: 2000, penalty: 300, principal: 4000);
        $this->payment($loan, '2026-07-01', interest: 999);
        $this->payment($loan, '2026-04-01', interest: 500, status: 'voided');

        // Released, and paid on, the year before.
        $lastYear = $this->releasedLoan('2025-12-20 09:00:00', processingFee: 777);
        $this->payment($lastYear, '2025-12-31', interest: 700);
        $this->payment($lastYear, '2026-01-02', interest: 0, principal: 1000);

        $this->expense('2026-05-15', 150_000);
        $this->expense('2026-07-02', 40_000);
        $this->expense('2025-12-31', 10_000);
        $this->reverseJournal($this->expense('2026-04-01', 25_000)->journal_id, '2026-04-02');
    }

    public function test_a_hand_checked_example(): void
    {
        $data = $this->profitSharing(self::CUTOFF)->assertOk()->json('data');

        $this->assertSame(self::CUTOFF, $data['cutoff_date']);
        $this->assertSame('2026-01-01', $data['period_from']);
        $this->assertSame(self::CUTOFF, $data['period_to']);
        $this->assertSame('organisation', $data['branch_scope']);

        $this->assertEquals([
            'interest_income' => 5000,
            'penalty_income' => 500,
            'processing_fees' => 1000,
            'service_fee' => 0,
            'other_fees' => 0,
            'lending_fees' => 1000,
            'lending_income' => 6500,
            'expenses' => 1500,
            'allowance_for_credit_losses' => 0,
            'principal_written_off' => 0,
            'written_off_covered' => 0,
            'losses' => 0,
            'distributable_profit' => 5000,
        ], $data['profit']);

        $this->assertSame('shared', $data['distribution']);
        $this->assertNull($data['rows_omitted']);

        $rows = array_map(fn (array $row): array => array_diff_key($row, ['borrower_id' => true, 'member_code' => true]), $data['rows']);
        $this->assertEquals([
            ['member_name' => 'Ana Aquino', 'share_capital' => 10000, 'share_percent' => 16.6667, 'earnings' => 833.33, 'capital_plus_earnings' => 10833.33],
            ['member_name' => 'Ben Bautista', 'share_capital' => 20000, 'share_percent' => 33.3333, 'earnings' => 1666.67, 'capital_plus_earnings' => 21666.67],
            ['member_name' => 'Carla Cruz', 'share_capital' => 30000, 'share_percent' => 50, 'earnings' => 2500, 'capital_plus_earnings' => 32500],
        ], $rows);
        $this->assertMatchesRegularExpression('/^BRW-/', $data['rows'][0]['member_code']);

        $this->assertEquals([
            'member_count' => 3,
            'share_capital' => 60000,
            'share_percent' => 100,
            'earnings' => 5000,
            'capital_plus_earnings' => 65000,
            'rounding_remainder' => 0,
            'members_with_negative_balance' => 0,
        ], $data['totals']);

        $this->assertStringStartsWith('To confirm with the owner', $data['basis_note']);
    }

    public function test_the_income_is_the_income_reports_own(): void
    {
        $income = $this->getJson('/api/reports/income?date_from=2026-01-01&date_to='.self::CUTOFF)->assertOk()->json('data');
        $profit = $this->profitSharing(self::CUTOFF)->assertOk()->json('data.profit');

        $this->assertEquals($income['interest_income'], $profit['interest_income']);
        $this->assertEquals($income['penalty_income'], $profit['penalty_income']);
        $this->assertEquals($income['processing_fees'], $profit['processing_fees']);
        $this->assertEquals($income['service_fee'], $profit['service_fee']);
        $this->assertEquals($income['other_fees'], $profit['other_fees']);
        $this->assertEquals($income['total'], $profit['lending_income']);
    }

    public function test_share_capital_is_the_ledgers_balance_on_the_cutoff_date(): void
    {
        // A day later Carla's ₱5,000.00 of 1 July counts, and the whole year
        // to 1 July is the period: the 1 July interest of ₱999.00 too.
        $data = $this->profitSharing('2026-07-01')->assertOk()->json('data');

        $this->assertEquals([10000, 20000, 35000], array_column($data['rows'], 'share_capital'));
        $this->assertEquals(65000, $data['totals']['share_capital']);
        $this->assertEquals(5999, $data['profit']['interest_income']);
    }
}
