<?php

namespace Tests\Feature\Accounting\LoanAccounting;

use App\Models\AccountingJournal;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /accounting/loan-accounting/loans: each collectible loan's age, its
 * money in integer centavos, and the same rows grouped by member.
 */
class LoanAgingListTest extends TestCase
{
    use LoanAccountingTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_days_past_due_and_buckets_at_the_edges(): void
    {
        $expected = [0 => 'current', 1 => '1_30', 30 => '1_30', 31 => '31_60', 120 => '91_120', 121 => 'over_120'];

        foreach (array_keys($expected) as $days) {
            $loan = $this->agedLoan(['loan_account_number' => sprintf('LN-D%03d', $days)]);
            // The oldest unpaid period sets the age; a later one never does.
            $this->period($loan, $days, principal: 100);
            $this->period($loan, -30, principal: 100);
        }

        $rows = $this->rowsByAccount();

        foreach ($expected as $days => $bucket) {
            $row = $rows[sprintf('LN-D%03d', $days)];
            $this->assertSame($days, $row['days_past_due'], "{$days} days");
            $this->assertSame($bucket, $row['bucket'], "{$days} days");
            $this->assertSame($days > 0, $row['is_past_due'], "{$days} days");
            $this->assertSame(
                $days > 0 ? Carbon::today()->subDays($days)->toDateString() : null,
                $row['oldest_late_due_date'],
                "{$days} days",
            );
        }
    }

    public function test_a_period_already_paid_does_not_age_the_loan(): void
    {
        $loan = $this->agedLoan(['loan_account_number' => 'LN-PAID1']);
        $this->period($loan, 50, principal: 100, status: 'paid', principalPaid: 100);
        $this->period($loan, 10, principal: 100);

        $row = $this->rowsByAccount()['LN-PAID1'];

        $this->assertSame(10, $row['days_past_due']);
        $this->assertSame('1_30', $row['bucket']);
    }

    public function test_a_loan_late_inside_its_grace_is_in_1_30_but_not_past_due(): void
    {
        $inside = $this->agedLoan(['loan_account_number' => 'LN-GRACE2', 'grace_period_days' => 3]);
        $this->period($inside, 2, principal: 100);
        $past = $this->agedLoan(['loan_account_number' => 'LN-GRACE4', 'grace_period_days' => 3]);
        $this->period($past, 4, principal: 100);

        $rows = $this->rowsByAccount();

        $this->assertSame('1_30', $rows['LN-GRACE2']['bucket']);
        $this->assertSame(2, $rows['LN-GRACE2']['days_past_due']);
        $this->assertFalse($rows['LN-GRACE2']['is_past_due']);
        $this->assertSame(0, $rows['LN-GRACE2']['past_due_amount']);
        $this->assertSame('released', $rows['LN-GRACE2']['display_status']);

        $this->assertTrue($rows['LN-GRACE4']['is_past_due']);
        $this->assertSame(10000, $rows['LN-GRACE4']['past_due_amount']);
        $this->assertSame('past_due', $rows['LN-GRACE4']['display_status']);
    }

    public function test_a_defaulted_loan_is_listed_and_finished_or_unreleased_loans_are_not(): void
    {
        foreach (['released', 'ongoing', 'defaulted', 'completed', 'draft', 'approved', 'restructured', 'void'] as $status) {
            $loan = $this->agedLoan(['loan_account_number' => 'LN-'.strtoupper($status), 'status' => $status]);
            $this->period($loan, 5, principal: 100);
        }
        $settled = $this->agedLoan(['loan_account_number' => 'LN-SETTLED']);
        $this->period($settled, 5, principal: 100, status: 'paid', principalPaid: 100);

        $rows = $this->rowsByAccount();

        $this->assertEqualsCanonicalizing(['LN-RELEASED', 'LN-ONGOING', 'LN-DEFAULTED'], array_keys($rows));
        $this->assertSame('defaulted', $rows['LN-DEFAULTED']['display_status']);
        $this->assertTrue($rows['LN-DEFAULTED']['is_past_due']);
    }

    public function test_money_is_in_whole_centavos_from_the_loan_figures(): void
    {
        $loan = $this->agedLoan(['loan_account_number' => 'LN-MONEY1', 'insurance_remaining_balance' => 12.34]);
        $this->period($loan, 10, principal: 1000.00, interest: 200.00, penalty: 34.56);
        $this->period($loan, -20, principal: 500.01, interest: 100.00);

        $row = $this->rowsByAccount()['LN-MONEY1'];

        $this->assertSame(150001, $row['outstanding_principal']);
        // 1,234.56 + 600.01 + the premium owed 12.34.
        $this->assertSame(184691, $row['total_outstanding']);
        $this->assertSame(123456, $row['past_due_amount']);
    }

    public function test_a_loan_row_has_exactly_the_contract_fields_and_no_member_contact_data(): void
    {
        $member = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Santos',
            'suffix' => null,
            'contact_number' => '09170000001',
            'email' => 'maria.santos@example.test',
        ]);
        $loan = $this->agedLoan(['loan_account_number' => 'LN-SHAPE1'], $member);
        $this->period($loan, 5, principal: 100);

        $response = $this->getJson('/api/accounting/loan-accounting/loans')->assertOk();
        $row = $response->json('data.0');

        $this->assertSame([
            'loan_id', 'account_number', 'member', 'branch', 'display_status', 'oldest_late_due_date',
            'days_past_due', 'bucket', 'is_past_due', 'outstanding_principal', 'total_outstanding', 'past_due_amount',
        ], array_keys($row));
        $this->assertSame(['id' => $member->id, 'name' => 'Maria Santos', 'code' => $member->fresh()->borrower_code], $row['member']);
        $this->assertSame(['id' => $this->branch->id, 'name' => $this->branch->name], $row['branch']);
        $this->assertSame($loan->id, $row['loan_id']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('09170000001', $body);
        $this->assertStringNotContainsString('maria.santos@example.test', $body);
    }

    public function test_rows_order_by_days_past_due_highest_first_then_account_number(): void
    {
        foreach (['LN-ORD-B' => 10, 'LN-ORD-A' => 10, 'LN-ORD-C' => 40, 'LN-ORD-D' => 0] as $account => $days) {
            $this->period($this->agedLoan(['loan_account_number' => $account]), $days, principal: 100);
        }

        $this->assertSame(
            ['LN-ORD-C', 'LN-ORD-A', 'LN-ORD-B', 'LN-ORD-D'],
            array_column($this->loans()['data'], 'account_number'),
        );
    }

    public function test_member_grouping_sums_its_loans_and_takes_its_worst_age(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $first = $this->agedLoan([], $member);
        $this->period($first, 5, principal: 100.10, interest: 10);
        $second = $this->agedLoan([], $member);
        $this->period($second, 40, principal: 200.20, penalty: 1.01);
        $this->period($second, -10, principal: 50);
        $other = $this->agedLoan();
        $this->period($other, 0, principal: 70);

        $body = $this->loans('&group=member');
        $rows = collect($body['data'])->keyBy(fn (array $row) => $row['member']['id']);

        $this->assertCount(2, $rows);
        $row = $rows[$member->id];
        $this->assertSame([
            'member', 'loan_count', 'outstanding_principal', 'total_outstanding', 'past_due_amount', 'days_past_due', 'bucket',
        ], array_keys($row));
        $this->assertSame(['id', 'name', 'code'], array_keys($row['member']));
        $this->assertSame(2, $row['loan_count']);
        $this->assertSame(35030, $row['outstanding_principal']);
        $this->assertSame(36131, $row['total_outstanding']);
        $this->assertSame(31131, $row['past_due_amount']);
        $this->assertSame(40, $row['days_past_due']);
        $this->assertSame('31_60', $row['bucket']);

        // Worst member first.
        $this->assertSame($member->id, $body['data'][0]['member']['id']);
        $this->assertSame(['count' => 2, 'outstanding_principal' => 42030, 'total_outstanding' => 43131, 'past_due_amount' => 31131], $body['meta']['totals']);
    }

    public function test_pages_hold_25_and_meta_totals_equal_the_rows_across_every_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $loan = $this->agedLoan();
            $this->period($loan, $i * 5, principal: 100 + $i / 100, interest: 7.77);
            $this->period($loan, -15, principal: 33.33);
        }

        $first = $this->getJson('/api/accounting/loan-accounting/loans')->assertOk()->json();
        $second = $this->getJson('/api/accounting/loan-accounting/loans?page=2')->assertOk()->json();

        $this->assertCount(25, $first['data']);
        $this->assertCount(5, $second['data']);
        $this->assertSame(25, $first['meta']['per_page']);
        $this->assertSame(30, $first['meta']['total']);

        $rows = array_merge($first['data'], $second['data']);
        $this->assertCount(30, array_unique(array_column($rows, 'loan_id')));
        $this->assertSame([
            'count' => 30,
            'outstanding_principal' => array_sum(array_column($rows, 'outstanding_principal')),
            'total_outstanding' => array_sum(array_column($rows, 'total_outstanding')),
            'past_due_amount' => array_sum(array_column($rows, 'past_due_amount')),
        ], $first['meta']['totals']);
        $this->assertSame($first['meta']['totals'], $second['meta']['totals']);
    }

    public function test_member_pages_total_every_member_row(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
            $this->period($this->agedLoan([], $member), $i, principal: 10 + $i);
            $this->period($this->agedLoan([], $member), 2 * $i, principal: 5.05);
        }

        $first = $this->getJson('/api/accounting/loan-accounting/loans?group=member')->assertOk()->json();
        $second = $this->getJson('/api/accounting/loan-accounting/loans?group=member&page=2')->assertOk()->json();
        $rows = array_merge($first['data'], $second['data']);

        $this->assertCount(25, $first['data']);
        $this->assertCount(27, $rows);
        $this->assertSame(54, array_sum(array_column($rows, 'loan_count')));
        $this->assertSame(27, $first['meta']['totals']['count']);
        $this->assertSame(array_sum(array_column($rows, 'outstanding_principal')), $first['meta']['totals']['outstanding_principal']);
        $this->assertSame(array_sum(array_column($rows, 'total_outstanding')), $first['meta']['totals']['total_outstanding']);
    }

    public function test_the_bucket_filter_narrows_rows_and_totals_but_not_the_stats(): void
    {
        $this->period($this->agedLoan(['loan_account_number' => 'LN-F-CUR']), 0, principal: 10);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-F-130A']), 3, principal: 20);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-F-130B']), 30, principal: 30);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-F-OVER']), 200, principal: 40);

        $body = $this->loans('&bucket=1_30');

        $this->assertEqualsCanonicalizing(['LN-F-130A', 'LN-F-130B'], array_column($body['data'], 'account_number'));
        $this->assertSame(['count' => 2, 'outstanding_principal' => 5000, 'total_outstanding' => 5000, 'past_due_amount' => 5000], $body['meta']['totals']);
        $this->assertSame(
            ['current' => 1, '1_30' => 2, '31_60' => 0, '61_90' => 0, '91_120' => 0, 'over_120' => 1],
            $body['meta']['stats'],
        );
    }

    public function test_member_bucket_filter_follows_the_members_worst_loan(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->period($this->agedLoan([], $member), 5, principal: 10);
        $this->period($this->agedLoan([], $member), 70, principal: 20);
        $young = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->period($this->agedLoan([], $young), 5, principal: 30);

        $body = $this->loans('&group=member&bucket=1_30');

        $this->assertSame([$young->id], array_column(array_column($body['data'], 'member'), 'id'));
        $this->assertSame(3000, $body['meta']['totals']['outstanding_principal']);
        $this->assertSame(1, $body['meta']['stats']['1_30']);
        $this->assertSame(1, $body['meta']['stats']['61_90']);

        $worst = $this->loans('&group=member&bucket=61_90')['data'];
        $this->assertSame([$member->id], array_column(array_column($worst, 'member'), 'id'));
        // The member's row still carries every one of their loans.
        $this->assertSame(3000, $worst[0]['outstanding_principal']);
        $this->assertSame(2, $worst[0]['loan_count']);
    }

    public function test_past_due_only_keeps_loans_past_grace(): void
    {
        $this->period($this->agedLoan(['loan_account_number' => 'LN-PD-IN', 'grace_period_days' => 3]), 2, principal: 10);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-PD-OUT', 'grace_period_days' => 3]), 4, principal: 20);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-PD-CUR']), 0, principal: 30);

        $body = $this->loans('&past_due_only=1');

        $this->assertSame(['LN-PD-OUT'], array_column($body['data'], 'account_number'));
        $this->assertSame(1, $body['meta']['totals']['count']);
        $this->assertSame(2000, $body['meta']['totals']['outstanding_principal']);
        $this->assertSame(1, $body['meta']['stats']['1_30']);
        $this->assertSame(0, $body['meta']['stats']['current']);
    }

    public function test_past_due_only_accepts_true_and_false_as_well_as_1_and_0(): void
    {
        $this->period($this->agedLoan(['loan_account_number' => 'LN-PB-LATE']), 4, principal: 20);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-PB-CUR']), 0, principal: 30);

        foreach (['true', '1'] as $value) {
            $this->assertSame(['LN-PB-LATE'], array_column($this->loans("&past_due_only={$value}")['data'], 'account_number'), $value);
        }

        foreach (['false', '0'] as $value) {
            $this->assertCount(2, $this->loans("&past_due_only={$value}")['data'], $value);
        }

        $this->getJson('/api/accounting/loan-accounting/loans?past_due_only=yes')->assertUnprocessable();
    }

    public function test_search_matches_account_number_member_name_or_code_literally(): void
    {
        $this->period($this->agedLoan(['loan_account_number' => 'LN-50%']), 1, principal: 10);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-500']), 1, principal: 10);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-A_B']), 1, principal: 10);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-AXB']), 1, principal: 10);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-C\\D']), 1, principal: 10);
        $named = Borrower::factory()->create([
            'branch_id' => $this->branch->id, 'first_name' => 'Juana', 'middle_name' => null,
            'last_name' => 'Dimagiba', 'suffix' => null, 'contact_number' => '09998887777',
        ]);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-NAMED'], $named), 1, principal: 10);

        $search = fn (string $term): array => array_column(
            $this->loans('&search='.rawurlencode($term))['data'],
            'account_number',
        );

        $this->assertSame(['LN-50%'], $search('50%'));
        $this->assertSame(['LN-A_B'], $search('A_B'));
        $this->assertSame(['LN-C\\D'], $search('C\\D'));
        $this->assertSame(['LN-NAMED'], $search('juana dimagiba'));
        $this->assertSame(['LN-NAMED'], $search($named->fresh()->borrower_code));
        $this->assertSame([], $search('09998887777'));
        $this->assertSame(1, $this->loans('&search=50%25')['meta']['totals']['count']);
    }

    public function test_as_of_ages_the_loans_on_that_date(): void
    {
        $loan = $this->agedLoan(['loan_account_number' => 'LN-ASOF']);
        $this->period($loan, 10, principal: 100);

        $row = $this->rowsByAccount('&as_of='.Carbon::today()->subDays(4)->toDateString())['LN-ASOF'];

        $this->assertSame(6, $row['days_past_due']);
        $this->assertSame('1_30', $row['bucket']);
    }

    public function test_the_query_is_validated(): void
    {
        foreach (['bucket=1-30', 'group=branch', 'per_page=101', 'per_page=0', 'as_of=2026-13-01', 'as_of=09/30/2026', 'page=0'] as $query) {
            $this->getJson("/api/accounting/loan-accounting/loans?{$query}")->assertUnprocessable();
        }

        $this->getJson('/api/accounting/loan-accounting/loans?per_page=100&group=member&bucket=over_120&past_due_only=0')->assertOk();
    }

    public function test_a_branch_user_sees_only_their_branches_loans(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-HERE']), 5, principal: 10);
        $this->period($this->agedLoan(['loan_account_number' => 'LN-THERE', 'branch_id' => $far->id]), 5, principal: 20);

        $this->assertEqualsCanonicalizing(['LN-HERE', 'LN-THERE'], array_keys($this->rowsByAccount()));
        $this->assertSame(['LN-THERE'], array_keys($this->rowsByAccount("&branch_id={$far->id}")));

        $this->actingAs($this->branchUser($this->branch));

        $body = $this->loans();
        $this->assertSame(['LN-HERE'], array_column($body['data'], 'account_number'));
        $this->assertSame(1000, $body['meta']['totals']['outstanding_principal']);
        $this->assertSame(1, $this->loans('&group=member')['meta']['totals']['count']);
        $this->getJson("/api/accounting/loan-accounting/loans?branch_id={$far->id}")->assertForbidden();
        $this->getJson("/api/accounting/loan-accounting/loans?branch_id={$this->branch->id}")->assertOk();
    }

    public function test_a_user_with_no_branch_sees_no_loans(): void
    {
        $this->period($this->agedLoan(), 5, principal: 10);
        $user = $this->branchUser($this->branch);
        $user->branches()->detach();
        $user->forceFill(['branch_id' => null])->save();

        $this->actingAs($user->fresh());

        $body = $this->loans();
        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['meta']['totals']['count']);
    }

    public function test_without_accounting_view_it_answers_403(): void
    {
        $this->actingAs($this->branchUser($this->branch, ['loans:view', 'reports:view']));

        $this->getJson('/api/accounting/loan-accounting/loans')->assertForbidden();
        $this->getJson('/api/accounting/loan-accounting/summary')->assertForbidden();
    }

    public function test_reading_the_list_and_summary_writes_nothing(): void
    {
        $loan = $this->agedLoan();
        $this->period($loan, 45, principal: 100, interest: 10, penalty: 3);
        $this->period($loan, -15, principal: 100);

        $tables = ['loans', 'amortization_schedules', (new AccountingJournal)->getTable(), 'audit_logs'];
        $before = $this->footprint($tables);

        $this->getJson('/api/accounting/loan-accounting/loans')->assertOk();
        $this->getJson('/api/accounting/loan-accounting/loans?group=member&past_due_only=1')->assertOk();
        $this->getJson('/api/accounting/loan-accounting/summary')->assertOk();

        $this->assertSame($before, $this->footprint($tables));
        $this->assertSame('pending', AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')->value('status'));
        $this->assertSame('released', Loan::find($loan->id)->status);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, array{int, int|null, string|null}>
     */
    private function footprint(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table) => [$table => [
            DB::table($table)->count(),
            DB::table($table)->max('id'),
            Schema::hasColumn($table, 'updated_at') ? DB::table($table)->max('updated_at') : null,
        ]])->all();
    }
}
