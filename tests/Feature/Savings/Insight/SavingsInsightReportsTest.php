<?php

namespace Tests\Feature\Savings\Insight;

use App\Models\SavingsSetting;
use App\Models\User;
use App\Services\Savings\AccruedInterest;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsMath;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The savings reports added in gap round S5 (the rest of the Savings task's
 * TASK 36) on InsightHistory's figures: each report's rows and whole-filter
 * totals, its CSV in words, the product filter on every report, the summary
 * adding up, and the branch scope (another branch 403, another branch's
 * member 404).
 */
class SavingsInsightReportsTest extends TestCase
{
    use InsightHistory, SetupLendyPH;

    private User $reader;

    private User $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->buildHistory();
        $this->reader = $this->userWith(['reports:view', 'savings:view']);
        $this->exporter = $this->userWith(['reports:export', 'savings:view']);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_account_listing_lists_every_account_with_status_counts(): void
    {
        $this->actingAs($this->reader)->getJson('/api/reports/savings/accounts?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('totals.account_count', 4)
            ->assertJsonPath('totals.active_count', 2)
            ->assertJsonPath('totals.dormant_count', 1)
            ->assertJsonPath('totals.frozen_count', 1)
            ->assertJsonPath('totals.closed_count', 0)
            ->assertJsonPath('totals.total_balance', '3059.87');

        $row = collect($this->actingAs($this->reader)->getJson('/api/reports/savings/accounts')->json('data'))->firstWhere('account_number', $this->accounts['E']->account_number);
        $this->assertSame(['dormant', '50.00', '2026-10-01', '2026-11-01'], [$row['status'], $row['balance'], $row['opened_on'], $row['dormant_since']]);
        $this->assertArrayHasKey('account_officer', $row);

        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/accounts/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Account #",Member,"Member Code",Product,Branch,Status,"Balance (₱)",Opened,Closed,"Dormant Since","Last Activity","Account Officer"', $csv);
        $this->assertStringContainsString(',Dormant,50.00,2026-10-01,', $csv);
        $this->assertStringContainsString(',Frozen,0.00,', $csv);
        $this->assertStringNotContainsString(',dormant,', $csv);
    }

    public function test_the_deposit_report_lists_reversed_deposits_but_counts_them_in_no_total(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/reports/savings/deposits?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.count', 2)
            ->assertJsonPath('totals.reversed_count', 1)
            ->assertJsonPath('totals.total_credit', '2000.00')
            ->assertJsonPath('totals.total_amount', '2000.00')
            ->assertJsonPath('date_from', '2026-11-01');

        $reversed = collect($response->json('data'))->firstWhere('reversed', true);
        $this->assertSame(['250.50', '2026-11-04'], [$reversed['amount'], $reversed['reversed_on']]);
        $this->assertNotNull($reversed['reversed_by_reference']);

        $this->actingAs($this->admin)->getJson('/api/reports/savings/deposits?date_from=2026-11-01&date_to=2026-11-10')
            ->assertJsonPath('totals.total_amount', '11999.00');

        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/deposits/export?date_from=2026-11-01&date_to=2026-11-10')->assertOk()->streamedContent();
        $this->assertStringContainsString('Date,Reference,"Account #",Member,Product,Branch,Type,Method,"Amount (₱)","Balance (₱)","Reversed By","Reversed On"', $csv);
        $this->assertStringContainsString(',Deposit,Cash,2000.00,', $csv);
        $this->assertStringContainsString(',Deposit,Cash,250.50,', $csv);
        $this->assertStringContainsString($reversed['reversed_by_reference'], $csv);
    }

    public function test_the_withdrawal_report_leaves_out_money_moved_into_a_time_deposit(): void
    {
        $this->actingAs($this->reader)->getJson('/api/reports/savings/withdrawals?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'withdrawal')
            ->assertJsonPath('data.0.amount', '300.00')
            ->assertJsonPath('data.0.reversed', false)
            ->assertJsonPath('totals.total_debit', '300.00')
            ->assertJsonPath('totals.reversed_count', 0);

        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/withdrawals/export?date_from=2026-11-01&date_to=2026-11-10')->assertOk()->streamedContent();
        $this->assertStringContainsString(',Withdrawal,Cash,300.00,', $csv);
        $this->assertStringNotContainsString('"Savings account"', $csv);
    }

    public function test_the_large_transaction_report_uses_the_alert_amount(): void
    {
        SavingsSetting::current()->update(['large_transaction_alert' => '2000.00']);
        // A ₱2,000.00 move into a time deposit never leaves the cooperative: not a large transaction.
        DB::transaction(fn () => app(SavingsLedger::class)->debit($this->accounts['B'], 'withdrawal', 200_000, [
            'method' => 'savings', 'time_deposit_id' => $this->deposits['TD1']->id,
        ]));

        $this->actingAs($this->admin)->getJson('/api/reports/savings/large-transactions?date_from=2026-10-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('threshold', '2000.00')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.amount', '2000.00')
            ->assertJsonPath('data.1.amount', '9999.00')
            ->assertJsonPath('totals.total_credit', '11999.00');

        $this->actingAs($this->reader)->getJson('/api/reports/savings/large-transactions?date_from=2026-10-01&date_to=2026-11-10')
            ->assertJsonPath('meta.total', 1);

        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/large-transactions/export?date_from=2026-10-01&date_to=2026-11-10')->assertOk()->streamedContent();
        $this->assertStringContainsString(',Deposit,Cash,2000.00,', $csv);
        $this->assertStringNotContainsString('9999.00', $csv);
    }

    public function test_the_time_deposit_report_lists_what_is_outstanding_on_the_day(): void
    {
        $this->actingAs($this->reader)->getJson('/api/reports/savings/time-deposits')->assertOk()
            ->assertJsonPath('as_of', '2026-11-10')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.certificate_number', $this->deposits['TD1']->certificate_number)
            ->assertJsonPath('data.0.days_to_maturity', 10)
            ->assertJsonPath('totals.total_principal', '30200.00')
            ->assertJsonPath('totals.total_maturity_amount', '30351.00')
            ->assertJsonPath('totals.total_net_interest', '151.00');

        // On Oct 31, TD3 was still outstanding and TD1 not yet placed.
        $this->actingAs($this->reader)->getJson('/api/reports/savings/time-deposits?as_of=2026-10-31')->assertOk()
            ->assertJsonPath('totals.count', 2)
            ->assertJsonPath('totals.total_principal', '25000.00');

        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/time-deposits/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Certificate #",Member,Product,Branch,Placed,Maturity,"Days to Maturity","Principal (₱)",Rate,Days,"Net Interest (₱)","Due at Maturity (₱)"', $csv);
        $this->assertStringContainsString(',2026-11-01,2026-11-20,10,10000.00,3%,', $csv);
        $this->assertStringContainsString(',"Pay out principal and interest",Active', $csv);
    }

    public function test_the_interest_accrual_report_matches_each_accounts_accrued_interest(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/reports/savings/interest-accrual?per_page=1000')->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('as_of', '2026-11-10');
        $rows = collect($response->json('data'))->keyBy('account_id');

        $gross = 0;

        foreach ([$this->accounts['A'], $this->accounts['B'], $this->accounts['D'], $this->accounts['E']] as $account) {
            $alone = app(AccruedInterest::class)->for($account);
            $this->assertSame(SavingsMath::pesos($alone['gross']), $rows[$account->id]['gross_interest']);
            $this->assertSame($alone['days'], $rows[$account->id]['days']);
            $gross += $alone['gross'];
        }

        $this->assertGreaterThan(0, $gross);
        $response->assertJsonPath('totals.total_gross_interest', SavingsMath::pesos($gross))
            ->assertJsonPath('totals.account_count', 4);

        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/interest-accrual/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Account #",Member,Product,Branch,Status,"Balance (₱)",From,To,Days,"Average Daily Balance (₱)","Gross Interest (₱)","Tax (₱)","Net Interest (₱)"', $csv);
        $this->assertStringContainsString($rows[$this->accounts['A']->id]['gross_interest'], $csv);
    }

    public function test_the_account_activity_report_opens_moves_and_closes_on_the_ledger(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/reports/savings/account-activity?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('totals.total_opening_balance', '1550.00')
            ->assertJsonPath('totals.total_closing_balance', '3059.87');
        $a = collect($response->json('data'))->firstWhere('account_id', $this->accounts['A']->id);

        $this->assertSame('1500.00', $a['opening_balance']);
        $this->assertSame('262.84', $a['credits']);
        $this->assertSame('852.97', $a['debits']);
        $this->assertSame(7, $a['transaction_count']);
        $this->assertSame('909.87', $a['closing_balance']);
        $this->assertSame('2026-11-07', $a['last_transaction_on']);

        $totals = $response->json('totals');
        $this->assertSame(
            SavingsMath::signedCentavos($totals['total_closing_balance']),
            SavingsMath::signedCentavos($totals['total_opening_balance']) + SavingsMath::centavos($totals['total_credits']) - SavingsMath::centavos($totals['total_debits']),
        );

        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/account-activity/export?date_from=2026-11-01&date_to=2026-11-10')->assertOk()->streamedContent();
        $this->assertStringContainsString('Status,"Opening Balance (₱)","In (₱)","Out (₱)",Transactions,"Closing Balance (₱)","Last Transaction"', $csv);
        $this->assertStringContainsString(',Active,1500.00,262.84,852.97,7,909.87,2026-11-07', $csv);
    }

    public function test_the_member_statement_covers_the_members_accounts_and_time_deposits(): void
    {
        $member = $this->accounts['A']->borrower_id;

        $this->actingAs($this->reader)->getJson("/api/reports/savings/member-statement/{$member}?date_from=2026-11-01&date_to=2026-11-10")->assertOk()
            ->assertJsonPath('data.member.id', $member)
            ->assertJsonMissingPath('data.member.contact_number')
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.opening_balance', '1500.00')
            ->assertJsonPath('data.accounts.0.closing_balance', '909.87')
            ->assertJsonPath('data.totals.closing_balance', '909.87')
            ->assertJsonPath('data.totals.time_deposits', '0.00');

        $tdMember = $this->deposits['TD1']->borrower_id;
        $this->actingAs($this->reader)->getJson("/api/reports/savings/member-statement/{$tdMember}")->assertOk()
            ->assertJsonCount(0, 'data.accounts')
            ->assertJsonCount(1, 'data.time_deposits')
            ->assertJsonPath('data.time_deposits.0.outstanding', true)
            ->assertJsonPath('data.totals.total_deposits', '10000.00');

        $csv = $this->actingAs($this->exporter)->get("/api/reports/savings/member-statement/{$member}/export?date_from=2026-11-01&date_to=2026-11-10")->assertOk()->streamedContent();
        $this->assertStringStartsWith('"Account #",Date,Reference,Type,"In (₱)","Out (₱)","Balance (₱)"', $csv);
        $this->assertStringContainsString(',"Opening balance",,,1500.00', $csv);
        $this->assertStringContainsString(',Reversal,', $csv);
        $this->assertStringContainsString(',"Closing balance",', $csv);

        // An end before the member's first account: the start never falls after it.
        $this->actingAs($this->reader)->getJson("/api/reports/savings/member-statement/{$member}?date_to=2026-09-15")->assertOk()
            ->assertJsonPath('data.date_from', '2026-09-15')
            ->assertJsonPath('data.date_to', '2026-09-15')
            ->assertJsonPath('data.totals.closing_balance', '0.00');

        $farMember = $this->accounts['C']->borrower_id;
        $this->actingAs($this->reader)->getJson("/api/reports/savings/member-statement/{$farMember}")->assertNotFound();
        $this->actingAs($this->exporter)->get("/api/reports/savings/member-statement/{$farMember}/export")->assertNotFound();
        $this->actingAs($this->reader)->getJson('/api/reports/savings/member-statement/999999')->assertNotFound();
        $this->actingAs($this->admin)->getJson("/api/reports/savings/member-statement/{$farMember}")->assertOk()->assertJsonPath('data.totals.closing_balance', '9999.00');
        $this->actingAs($this->admin)->getJson("/api/reports/savings/member-statement/{$farMember}?branch_id={$this->branch->id}")->assertOk()
            ->assertJsonCount(0, 'data.accounts')
            ->assertJsonPath('data.totals.closing_balance', '0.00');
    }

    public function test_the_summary_by_branch_and_by_product_adds_up_to_its_totals(): void
    {
        foreach (['branch', 'product'] as $group) {
            $summary = $this->actingAs($this->admin)->getJson("/api/reports/savings/summary?group_by={$group}&date_from=2026-11-01&date_to=2026-11-10")->assertOk()->json('data');

            $this->assertSame($group, $summary['group_by']);
            $this->assertRowsAddUp($summary['rows'], $summary['totals']);
        }

        $this->actingAs($this->admin)->getJson('/api/reports/savings/summary?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('data.group_by', 'branch')
            ->assertJsonPath('data.totals.opening_balance', '1550.00')
            ->assertJsonPath('data.totals.deposits', '11999.00')
            ->assertJsonPath('data.totals.deposit_count', 2)
            ->assertJsonPath('data.totals.withdrawals', '300.00')
            ->assertJsonPath('data.totals.interest_credited', '12.34')
            ->assertJsonPath('data.totals.tax_withheld', '2.47')
            // The ₱200.00 moved into TD5; the transfer and the reversal net to nothing.
            ->assertJsonPath('data.totals.other_movements', '-200.00')
            ->assertJsonPath('data.totals.net_movement', '11508.87')
            ->assertJsonPath('data.totals.closing_balance', '13058.87')
            ->assertJsonPath('data.totals.new_accounts', 3)
            ->assertJsonPath('data.totals.open_accounts', 5)
            ->assertJsonPath('data.totals.time_deposit_count', 4)
            ->assertJsonPath('data.totals.time_deposits', '37200.00')
            ->assertJsonPath('data.totals.time_deposits_placed_count', 3)
            ->assertJsonPath('data.totals.time_deposits_placed', '17200.00')
            ->assertJsonPath('data.totals.time_deposit_interest', '80.00')
            ->assertJsonPath('data.totals.interest_expense', '92.34')
            ->assertJsonPath('data.totals.total_deposits', '50258.87');

        $csv = $this->actingAs($this->admin)->get('/api/reports/savings/summary/export?group_by=product&date_from=2026-11-01&date_to=2026-11-10')->assertOk()->streamedContent();
        $this->assertStringStartsWith('Product,"Opening Balance (₱)","Deposits (count)","Deposits (₱)",', $csv);
        $this->assertStringContainsString(',"Fees Charged (₱)","Share Capital Transfers (₱)","Other Movements (₱)",', $csv);
        $this->assertStringContainsString("\nTotal,1550.00,2,11999.00,1,300.00,12.34,2.47,0.00,0.00,-200.00,11508.87,13058.87,", $csv);
        $this->assertStringContainsString("\n\"Junior Savings\",", $csv);

        $this->actingAs($this->admin)->getJson('/api/reports/savings/summary?group_by=member')->assertStatus(422)->assertJsonValidationErrors('group_by');
    }

    public function test_an_excess_withdrawal_fee_is_income_and_never_a_withdrawal(): void
    {
        $b = $this->accounts['B'];
        $withdrawal = $this->withdraw($b, 10_000);
        DB::transaction(fn () => app(SavingsLedger::class)->debit($b->refresh(), 'fee', 1_500, ['fee_for_transaction_id' => $withdrawal->id]));

        $this->actingAs($this->admin)->getJson('/api/savings/dashboard')->assertOk()
            ->assertJsonPath('data.kpis.withdrawals', ['count' => 2, 'amount' => '400.00'])
            ->assertJsonPath('data.kpis.fees_charged', ['count' => 1, 'amount' => '15.00'])
            ->assertJsonPath('data.kpis.savings_balance', '12943.87');

        $this->actingAs($this->admin)->getJson('/api/reports/savings/summary?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('data.totals.withdrawals', '400.00')
            ->assertJsonPath('data.totals.fee_count', 1)
            ->assertJsonPath('data.totals.fees_charged', '15.00')
            ->assertJsonPath('data.totals.other_movements', '-200.00')
            ->assertJsonPath('data.totals.closing_balance', '12943.87');

        $this->actingAs($this->reader)->getJson('/api/reports/savings/withdrawals?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.total_debit', '400.00');
    }

    public function test_a_transfer_to_share_capital_is_its_own_figure_never_a_withdrawal(): void
    {
        DB::transaction(fn () => app(SavingsLedger::class)->debit($this->accounts['B'], 'share_capital_transfer', 50_000, ['method' => 'savings']));

        $this->actingAs($this->admin)->getJson('/api/savings/dashboard')->assertOk()
            ->assertJsonPath('data.kpis.withdrawals', ['count' => 1, 'amount' => '300.00'])
            ->assertJsonPath('data.kpis.share_capital_transfers', ['count' => 1, 'amount' => '500.00'])
            ->assertJsonPath('data.kpis.savings_balance', '12558.87');

        $this->actingAs($this->admin)->getJson('/api/reports/savings/summary?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('data.totals.withdrawals', '300.00')
            ->assertJsonPath('data.totals.share_capital_transfer_count', 1)
            ->assertJsonPath('data.totals.share_capital_transfers', '500.00')
            ->assertJsonPath('data.totals.other_movements', '-200.00')
            ->assertJsonPath('data.totals.closing_balance', '12558.87');
    }

    /**
     * Reports read as at the end of their period: a reversal dated after it
     * doesn't change that period's figures; it shows in its own period's
     * other movements.
     */
    public function test_a_later_reversal_never_changes_an_earlier_periods_figures(): void
    {
        $october = $this->accounts['A']->transactions()->where('type', 'deposit')->whereDate('date', '2026-10-15')->firstOrFail();
        DB::transaction(fn () => app(SavingsLedger::class)->debit($this->accounts['A'], 'reversal', 50_000, ['reverses_transaction_id' => $october->id, 'method' => 'cash']));

        $this->actingAs($this->admin)->getJson('/api/reports/savings/summary?date_from=2026-10-01&date_to=2026-10-31')->assertOk()
            ->assertJsonPath('data.totals.deposits', '1550.00')
            ->assertJsonPath('data.totals.deposit_count', 3)
            ->assertJsonPath('data.totals.other_movements', '0.00');
        $this->actingAs($this->admin)->getJson('/api/savings/dashboard?to=2026-10-31')->assertOk()
            ->assertJsonPath('data.kpis.deposits', ['count' => 3, 'amount' => '1550.00']);
        $series = collect($this->actingAs($this->admin)->getJson('/api/savings/dashboard')->json('data.series'))->keyBy('month');
        $this->assertSame('1550.00', $series['2026-10']['deposits']);

        $row = collect($this->actingAs($this->reader)->getJson('/api/reports/savings/deposits?date_from=2026-10-01&date_to=2026-10-31')->assertOk()
            ->assertJsonPath('totals.total_amount', '1550.00')
            ->assertJsonPath('totals.reversed_count', 0)
            ->json('data'))->firstWhere('id', $october->id);
        $this->assertFalse($row['reversed']);
        $this->assertSame('2026-11-10', $row['reversed_on']);
        $csv = $this->actingAs($this->exporter)->get('/api/reports/savings/deposits/export?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->streamedContent();
        $this->assertStringContainsString(",{$row['reversed_by_reference']},2026-11-10\n", $csv);

        // In November it is an other movement, and the deposit isn't counted twice.
        $this->actingAs($this->admin)->getJson('/api/reports/savings/summary?date_from=2026-11-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('data.totals.deposits', '11999.00')
            ->assertJsonPath('data.totals.other_movements', '-700.00')
            ->assertJsonPath('data.totals.closing_balance', '12558.87');
        $this->actingAs($this->reader)->getJson('/api/reports/savings/deposits?date_from=2026-10-01&date_to=2026-11-10')->assertOk()
            ->assertJsonPath('totals.reversed_count', 2);
    }

    public function test_a_branch_user_summarises_only_their_branches(): void
    {
        $summary = $this->actingAs($this->reader)->getJson('/api/reports/savings/summary?date_from=2026-11-01&date_to=2026-11-10')->assertOk()->json('data');

        $this->assertNotContains('Far Branch', array_column($summary['rows'], 'group_name'));
        $this->assertSame('3059.87', $summary['totals']['closing_balance']);
        $this->assertSame('30200.00', $summary['totals']['time_deposits']);
        $this->assertRowsAddUp($summary['rows'], $summary['totals']);
    }

    public function test_the_product_filter_narrows_every_savings_report(): void
    {
        $regular = $this->regular->id;
        $junior = $this->junior->id;
        $time = $this->timeProduct->id;

        $this->actingAs($this->reader)->getJson("/api/reports/savings/balances?product_id={$junior}")->assertOk()->assertJsonPath('totals.total_balance', '2150.00');
        $this->actingAs($this->reader)->getJson("/api/reports/savings/transactions?date_from=2026-11-01&date_to=2026-11-10&product_id={$junior}")->assertOk()->assertJsonPath('totals.count', 2);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/time-deposit-maturities?date_from=2026-11-01&date_to=2026-11-30&product_id={$time}")->assertOk()->assertJsonPath('totals.listed_count', 1);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/time-deposit-maturities?date_from=2026-11-01&date_to=2026-11-30&product_id={$regular}")->assertOk()->assertJsonPath('totals.listed_count', 0);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/interest-postings?product_id={$regular}")->assertOk()->assertJsonPath('totals.count', 0);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/dormant?product_id={$junior}")->assertOk()->assertJsonPath('totals.account_count', 1);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/dormant?product_id={$regular}")->assertOk()->assertJsonPath('totals.account_count', 0);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/accounts?product_id={$regular}")->assertOk()->assertJsonPath('totals.account_count', 2);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/deposits?date_from=2026-11-01&date_to=2026-11-10&product_id={$regular}")->assertOk()->assertJsonPath('totals.count', 1);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/withdrawals?date_from=2026-11-01&date_to=2026-11-10&product_id={$junior}")->assertOk()->assertJsonPath('totals.count', 0);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/time-deposits?product_id={$regular}")->assertOk()->assertJsonPath('totals.count', 0);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/interest-accrual?product_id={$junior}")->assertOk()->assertJsonPath('totals.account_count', 2);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/account-activity?date_from=2026-11-01&date_to=2026-11-10&product_id={$junior}")->assertOk()->assertJsonPath('totals.total_closing_balance', '2150.00');
        $this->actingAs($this->reader)->getJson("/api/reports/savings/summary?group_by=product&date_from=2026-11-01&date_to=2026-11-10&product_id={$junior}")->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.totals.closing_balance', '2150.00');
        $member = $this->accounts['B']->borrower_id;
        $this->actingAs($this->reader)->getJson("/api/reports/savings/member-statement/{$member}?product_id={$regular}")->assertOk()
            ->assertJsonCount(0, 'data.accounts')
            ->assertJsonPath('data.totals.closing_balance', '0.00');
        $this->actingAs($this->reader)->getJson("/api/reports/savings/member-statement/{$member}?product_id={$junior}")->assertOk()
            ->assertJsonPath('data.totals.closing_balance', '2100.00');
        $tdMember = $this->deposits['TD1']->borrower_id;
        $this->actingAs($this->reader)->getJson("/api/reports/savings/member-statement/{$tdMember}?product_id={$junior}")->assertOk()
            ->assertJsonCount(0, 'data.time_deposits');
        $this->actingAs($this->reader)->getJson('/api/reports/savings/balances?product_id=999999')->assertStatus(422)->assertJsonValidationErrors('product_id');

        $csv = $this->actingAs($this->exporter)->get("/api/reports/savings/balances/export?product_id={$junior}")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Regular Savings', $csv);
        $this->assertStringContainsString('Junior Savings', $csv);
    }

    public function test_the_new_reports_keep_the_branch_scope_and_their_permissions(): void
    {
        $member = $this->accounts['A']->borrower_id;

        foreach (['accounts', 'deposits', 'withdrawals', 'large-transactions', 'time-deposits', 'interest-accrual', 'account-activity', 'summary', "member-statement/{$member}"] as $report) {
            $this->actingAs($this->reader)->getJson("/api/reports/savings/{$report}?branch_id={$this->far->id}")->assertForbidden();
            $this->actingAs($this->userWith(['reports:view']))->getJson("/api/reports/savings/{$report}")->assertForbidden();
            $this->actingAs($this->reader)->get("/api/reports/savings/{$report}/export", ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAs($this->exporter)->get("/api/reports/savings/{$report}/export")->assertOk();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     */
    private function assertRowsAddUp(array $rows, array $totals): void
    {
        foreach ($totals as $field => $total) {
            $sum = array_sum(array_map(fn (array $row) => is_string($row[$field]) ? SavingsMath::signedCentavos($row[$field]) : $row[$field], $rows));

            $this->assertSame(is_string($total) ? SavingsMath::signedCentavos($total) : $total, $sum, "The rows' {$field} add up to the total.");
        }
    }
}
