<?php

namespace Tests\Feature\Savings\Reports;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\AccruedInterest;
use App\Services\Savings\SavingsMath;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Interest\SavingsHistory;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The savings reports (design §2, §8 "Reports"): every total is the server's
 * for the whole filter, never one page's rows; a branch user reports on
 * their own branches only; the statement's opening, running and closing
 * balances; and the CSV exports.
 */
class SavingsReportsTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    private SavingsProduct $product;

    private Branch $far;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->far = Branch::factory()->create(['name' => 'Far Branch']);
        $this->reader = $this->userWith(['reports:view', 'savings:view']);
        $this->onDay('2026-11-01');
        $this->product = $this->regularProduct(['name' => 'Regular Savings']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_balances_totals_cover_the_whole_filter_and_stay_in_the_callers_branches(): void
    {
        $one = $this->openAccount($this->product, 100_000);
        $this->openAccount($this->product, 250_050);
        $this->openAccount($this->product, 1_000);
        $this->openAccount($this->product, 999_900, $this->far);

        $page = $this->actingAs($this->reader)->getJson('/api/reports/savings/balances?per_page=1')->assertOk();
        $page->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('totals.account_count', 3)
            ->assertJsonPath('totals.total_balance', '3510.50')
            ->assertJsonPath('totals.total_available', '3510.50')
            ->assertJsonPath('totals.total_hold', '0.00')
            ->assertJsonPath('totals.total_pending', '0.00')
            ->assertJsonPath('as_of', '2026-11-01');

        $row = collect($this->actingAs($this->reader)->getJson('/api/reports/savings/balances')->json('data'))->firstWhere('account_number', $one->account_number);
        $this->assertSame('1000.00', $row['balance']);
        $this->assertSame('Regular Savings', $row['product_name']);
        $this->assertSame($this->branch->name, $row['branch_name']);
        $this->assertSame('active', $row['status']);
        $this->assertArrayHasKey('accrued_interest', $row);
        $this->assertArrayHasKey('borrower_name', $row);
        $this->assertArrayHasKey('borrower_code', $row);
        $this->assertArrayHasKey('last_member_activity_on', $row);

        $this->actingAs($this->reader)->getJson("/api/reports/savings/balances?branch_id={$this->far->id}")->assertForbidden();
        $this->actingAs($this->admin)->getJson("/api/reports/savings/balances?branch_id={$this->far->id}")->assertOk()->assertJsonPath('totals.total_balance', '9999.00');
        $this->actingAs($this->admin)->getJson('/api/reports/savings/balances')->assertOk()->assertJsonPath('totals.account_count', 4);
    }

    public function test_balance_rows_and_totals_carry_each_accounts_own_accrued_interest(): void
    {
        $double = $this->regularProduct(['name' => 'Bonus Savings'], '7.3000');
        $a = $this->openAccount($this->product, 100_000);
        $b = $this->openAccount($this->product, 200_000);
        $c = $this->openAccount($double, 100_000);
        $this->onDay('2026-11-10');

        // Nov 1 to Nov 9: 9 days. At 3.65% the gross is centavo-days ÷ 10,000.
        $rows = collect($this->actingAs($this->reader)->getJson('/api/reports/savings/balances?per_page=1000')->assertOk()
            ->assertJsonPath('totals.total_accrued_interest', '4.50')
            ->assertJsonPath('totals.total_balance', '4000.00')
            ->json('data'))->keyBy('account_id');

        $this->assertSame(['0.90', '1.80', '1.80'], [$rows[$a->id]['accrued_interest'], $rows[$b->id]['accrued_interest'], $rows[$c->id]['accrued_interest']]);

        foreach ([$a, $b, $c] as $account) {
            $alone = app(AccruedInterest::class)->for($account->refresh());
            $this->assertSame(SavingsMath::pesos($alone['gross']), $rows[$account->id]['accrued_interest']);
        }
    }

    public function test_balances_as_of_a_past_day_read_the_ledger_on_that_day(): void
    {
        $account = $this->openAccount($this->product, 100_000);
        $this->onDay('2026-11-05');
        $this->deposit($account, 50_000);

        $this->actingAs($this->reader)->getJson('/api/reports/savings/balances?as_of=2026-11-03')->assertOk()
            ->assertJsonPath('as_of', '2026-11-03')
            ->assertJsonPath('data.0.balance', '1000.00')
            ->assertJsonPath('data.0.available_balance', null)
            ->assertJsonPath('totals.total_balance', '1000.00')
            ->assertJsonPath('totals.total_available', null);
        $this->actingAs($this->reader)->getJson('/api/reports/savings/balances')->assertJsonPath('totals.total_balance', '1500.00');
        $this->actingAs($this->reader)->getJson('/api/reports/savings/balances?as_of=2026-11-06')->assertStatus(422)->assertJsonValidationErrors('as_of');
    }

    public function test_transactions_in_the_period_oldest_first_with_totals_for_the_whole_period(): void
    {
        $account = $this->openAccount($this->product, 100_000);
        $this->onDay('2026-11-03');
        $this->withdraw($account, 30_000);
        $this->onDay('2026-11-04');
        $this->deposit($account, 20_000);
        $this->openAccount($this->product, 5_000, $this->far);

        $response = $this->actingAs($this->reader)->getJson('/api/reports/savings/transactions?date_from=2026-11-02&date_to=2026-11-04&per_page=1')->assertOk();
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.count', 2)
            ->assertJsonPath('totals.total_credit', '200.00')
            ->assertJsonPath('totals.total_debit', '300.00')
            ->assertJsonPath('data.0.type', 'withdrawal')
            ->assertJsonPath('data.0.date', '2026-11-03')
            ->assertJsonPath('data.0.debit', '300.00')
            ->assertJsonPath('data.0.balance_after', '700.00')
            ->assertJsonPath('data.0.account_number', $account->account_number)
            ->assertJsonPath('data.0.method', 'cash');

        $this->actingAs($this->reader)->getJson('/api/reports/savings/transactions?date_from=2026-11-05&date_to=2026-11-01')->assertStatus(422);
    }

    public function test_time_deposit_maturities_split_out_what_is_paid_out(): void
    {
        $make = fn (string $instruction, string $maturity, string $status = 'active') => TimeDeposit::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'principal' => '10000.00', 'net_interest' => '100.00', 'maturity_amount' => '10100.00',
            'maturity_instruction' => $instruction, 'maturity_date' => $maturity, 'status' => $status,
        ]);
        $make('payout', '2026-12-05');
        $make('rollover_principal', '2026-12-10');
        $make('rollover_all', '2026-12-20');
        $make('payout', '2026-12-15', 'preterminated');
        $make('payout', '2027-02-01');

        $this->actingAs($this->reader)->getJson('/api/reports/savings/time-deposit-maturities?date_from=2026-12-01&date_to=2026-12-31&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.maturity_date', '2026-12-05')
            ->assertJsonPath('totals.count', 3)
            ->assertJsonPath('totals.total_principal', '30000.00')
            ->assertJsonPath('totals.total_net_interest', '300.00')
            ->assertJsonPath('totals.total_maturity_amount', '30300.00')
            ->assertJsonPath('totals.payout_amount', '10200.00');
    }

    public function test_interest_postings_in_the_period(): void
    {
        $make = fn (string $start, string $end, string $status, ?string $postedAt, string $gross) => SavingsInterestRun::factory()->create([
            'product_id' => $this->product->id, 'branch_id' => $this->branch->id, 'period_start' => $start, 'period_end' => $end,
            'status' => $status, 'posted_at' => $postedAt, 'account_count' => 3,
            'total_gross' => $gross, 'total_tax' => '1.00', 'total_net' => number_format((float) $gross - 1, 2, '.', ''),
        ]);
        $make('2026-01-01', '2026-03-31', 'posted', '2026-04-02 09:00:00', '100.00');
        $make('2026-04-01', '2026-06-30', 'posted', '2026-07-02 09:00:00', '200.00');
        $make('2026-07-01', '2026-09-30', 'approved', null, '400.00');
        $make('2025-10-01', '2025-12-31', 'reversed', '2026-01-03 09:00:00', '800.00');

        $this->actingAs($this->reader)->getJson('/api/reports/savings/interest-postings?date_from=2026-01-01&date_to=2026-10-31&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('totals.count', 3)
            ->assertJsonPath('totals.reversed_count', 1)
            ->assertJsonPath('totals.total_gross', '300.00')
            ->assertJsonPath('totals.total_tax', '2.00')
            ->assertJsonPath('totals.total_net', '298.00')
            ->assertJsonPath('data.0.product_name', 'Regular Savings')
            ->assertJsonPath('data.0.branch_name', $this->branch->name)
            ->assertJsonPath('data.0.status', 'reversed');

        $this->withoutMiddleware(ThrottleRequests::class);
        $csv = $this->actingAs($this->userWith(['reports:export', 'savings:view']))
            ->get('/api/reports/savings/interest-postings/export?date_from=2026-01-01&date_to=2026-10-31')->streamedContent();
        $this->assertStringContainsString(',Reversed,3,800.00,', $csv);
        $this->assertStringContainsString(',Posted,3,100.00,', $csv);
    }

    public function test_a_one_sided_range_defaults_the_other_end_and_the_ten_year_limit_applies_to_it(): void
    {
        $this->onDay('2026-11-04');

        $this->actingAs($this->reader)->getJson('/api/reports/savings/transactions?date_from=2026-11-03')->assertOk()
            ->assertJsonPath('date_from', '2026-11-03')
            ->assertJsonPath('date_to', '2026-11-04');
        $this->actingAs($this->reader)->getJson('/api/reports/savings/transactions?date_to=2026-10-15')->assertOk()
            ->assertJsonPath('date_from', '2026-10-01')
            ->assertJsonPath('date_to', '2026-10-15');
        $this->actingAs($this->reader)->getJson('/api/reports/savings/time-deposit-maturities?date_from=2027-02-10')->assertOk()
            ->assertJsonPath('date_from', '2027-02-10')
            ->assertJsonPath('date_to', '2027-02-28');
        $this->actingAs($this->reader)->getJson('/api/reports/savings/transactions?date_from=2015-01-01')
            ->assertStatus(422)->assertJsonValidationErrors('date_from');
    }

    public function test_dormant_accounts_only(): void
    {
        $this->openAccount($this->product, 100_000);
        $this->openAccount($this->product, 0, null, ['status' => SavingsAccount::STATUS_DORMANT, 'dormant_since' => '2026-10-01', 'ledger_balance' => '75.00']);

        $this->actingAs($this->reader)->getJson('/api/reports/savings/dormant')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.dormant_since', '2026-10-01')
            ->assertJsonPath('data.0.balance', '75.00')
            ->assertJsonPath('totals.account_count', 1)
            ->assertJsonPath('totals.total_balance', '75.00');
    }

    public function test_the_statement_opens_runs_and_closes_on_the_ledger(): void
    {
        $account = $this->openAccount($this->product, 100_000);
        $this->onDay('2026-11-03');
        $this->deposit($account, 25_000);
        $this->onDay('2026-11-05');
        $this->withdraw($account, 40_000);
        $this->onDay('2026-11-07');
        $this->deposit($account, 5_050);
        $this->onDay('2026-11-10');

        $data = $this->actingAs($this->reader)->getJson("/api/reports/savings/statement/{$account->id}?date_from=2026-11-02&date_to=2026-11-06")->assertOk()->json('data');

        $this->assertSame('1000.00', $data['opening_balance']);
        $this->assertSame(['1250.00', '850.00'], array_column($data['rows'], 'balance_after'));
        $this->assertSame('850.00', $data['closing_balance']);
        $this->assertSame(['credit' => '250.00', 'debit' => '400.00', 'count' => 2], $data['totals']);
        $this->assertSame($account->account_number, $data['account']['account_number']);
        $this->assertSame('Regular Savings', $data['account']['product_name']);
        $this->assertSame($this->branch->name, $data['account']['branch_name']);
        $this->assertSame(['2026-11-02', '2026-11-06'], [$data['date_from'], $data['date_to']]);

        $whole = $this->actingAs($this->reader)->getJson("/api/reports/savings/statement/{$account->id}")->assertOk()->json('data');
        $this->assertSame('0.00', $whole['opening_balance']);
        $this->assertSame('900.50', $whole['closing_balance']);
        $this->assertSame($account->refresh()->ledger_balance, $whole['closing_balance']);
        $this->assertCount(4, $whole['rows']);

        $farAccount = $this->openAccount($this->product, 1_000, $this->far);
        $this->actingAs($this->reader)->getJson("/api/reports/savings/statement/{$farAccount->id}")->assertNotFound();
        $this->actingAs($this->reader)->getJson('/api/reports/savings/statement/999999')->assertNotFound();
    }

    public function test_csv_exports_need_reports_export(): void
    {
        $this->openAccount($this->product, 100_000);
        $exporter = $this->userWith(['reports:export', 'savings:view']);
        // Every export in one test; the exports rate limit is not what it tests.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['balances', 'transactions', 'time-deposit-maturities', 'interest-postings', 'dormant', 'gl-control'] as $report) {
            $this->actingAs($this->reader)->get("/api/reports/savings/{$report}/export", ['Accept' => 'application/json'])->assertForbidden();
            $response = $this->actingAs($exporter)->get("/api/reports/savings/{$report}/export", ['Accept' => 'application/json'])->assertOk();
            $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        }

        $csv = $this->actingAs($exporter)->get('/api/reports/savings/balances/export')->streamedContent();
        $this->assertStringContainsString('1000.00', $csv);
    }

    public function test_every_report_needs_reports_view_and_savings_view(): void
    {
        $account = $this->openAccount($this->product, 100_000);
        $urls = [
            '/api/reports/savings/balances', '/api/reports/savings/transactions', '/api/reports/savings/time-deposit-maturities',
            '/api/reports/savings/interest-postings', '/api/reports/savings/dormant', "/api/reports/savings/statement/{$account->id}",
            '/api/reports/savings/gl-control',
        ];
        $reportsOnly = $this->userWith(['reports:view']);
        $savingsOnly = $this->userWith(['savings:view']);

        foreach ($urls as $url) {
            $this->actingAs($reportsOnly)->getJson($url)->assertForbidden();
            $this->actingAs($savingsOnly)->getJson($url)->assertForbidden();
            $this->actingAs($this->reader)->getJson($url)->assertOk();
        }
    }
}
