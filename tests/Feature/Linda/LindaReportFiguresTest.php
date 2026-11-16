<?php

namespace Tests\Feature\Linda;

use App\Models\Loan;
use App\Models\Repayment;
use App\Services\PortfolioOutstanding;
use App\Services\ReportService;

/**
 * The figures Linda adds to the reports (payment count, active loans per
 * branch, past due by member) are read on the reports' own basis, and every
 * existing key and value stays as it was.
 */
class LindaReportFiguresTest extends LindaAnswersTestCase
{
    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    // -----------------------------------------------------------------
    // Payment count
    // -----------------------------------------------------------------

    public function test_daily_collection_counts_the_payments_total_received_is_made_of(): void
    {
        $here = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));
        $there = $this->releaseTo($this->member($this->far, 'Ben', 'Reyes'));

        $this->pay($here, 1500);
        $this->pay($here, 700);
        // Outside the period.
        $this->pay($there, 900, now()->subDays(40)->toDateString());
        $this->pay($there, 2500);
        $this->pay($there, 300);

        // A voided receipt is not money taken in, so it isn't a payment either.
        $voided = Repayment::query()->where('loan_id', $there->id)->latest('id')->first();
        $this->actingAs($this->admin)->patchJson("/api/repayments/{$voided->id}/void", ['void_reason' => 'Entered twice'])->assertOk();

        $today = now()->toDateString();
        $all = $this->reports()->dailyCollection(['branch_ids' => null, 'date_from' => $today, 'date_to' => $today]);
        $main = $this->reports()->dailyCollection(['branch_ids' => [$this->branch->id], 'date_from' => $today, 'date_to' => $today]);

        $receipts = fn (?array $branches) => Repayment::query()
            ->where('status', 'posted')
            ->whereBetween('payment_date', [$today, $today])
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::EVER_RELEASED_STATUSES)
                ->when($branches !== null, fn ($lq) => $lq->whereIn('branch_id', $branches)));

        $this->assertSame(3, $all['payment_count']);
        $this->assertSame($receipts(null)->count(), $all['payment_count']);
        $this->assertSame(2, $main['payment_count']);
        $this->assertSame($receipts([$this->branch->id])->count(), $main['payment_count']);

        // total_received is unchanged: the same receipts, less any premium.
        $this->assertEquals(
            round((float) $receipts(null)->get()->sum(fn ($r) => (float) $r->amount_paid - (float) $r->insurance_applied), 2),
            $all['total_received'],
        );

        // Collection Efficiency shares Daily Collection's figures, so it carries the same count.
        $efficiency = $this->reports()->collectionEfficiency(['branch_ids' => null, 'date_from' => $today, 'date_to' => $today]);
        $this->assertSame($all['payment_count'], $efficiency['payment_count']);
    }

    public function test_the_payment_count_is_zero_without_payments(): void
    {
        $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));

        $report = $this->reports()->dailyCollection(['branch_ids' => null, 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);

        $this->assertSame(0, $report['payment_count']);
        $this->assertEquals(0, $report['total_received']);
    }

    // -----------------------------------------------------------------
    // Active loans per branch
    // -----------------------------------------------------------------

    public function test_each_branch_row_counts_its_active_loans_and_the_rows_add_up_to_active_loans(): void
    {
        $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));
        $this->releaseTo($this->member($this->branch, 'Carla', 'Diaz'))->update(['status' => 'ongoing']);
        $this->releaseTo($this->member($this->branch, 'Dan', 'Lim'))->update(['status' => 'defaulted']);
        $this->releaseTo($this->member($this->branch, 'Eva', 'Tan'))->update(['status' => 'completed']);
        $this->releaseTo($this->member($this->far, 'Ben', 'Reyes'));

        $all = $this->reports()->loanBalanceSummary(['branch_ids' => null]);
        $counts = array_column($all['by_branch'], 'active_loan_count', 'branch_id');

        $this->assertSame(2, $counts[$this->branch->id]);
        $this->assertSame(1, $counts[$this->far->id]);
        $this->assertSame(3, $all['active_loans']);
        $this->assertSame($all['active_loans'], array_sum($counts));
        // The footer keeps its columns: the rows' counts already add up to active_loans.
        $this->assertArrayNotHasKey('active_loan_count', $all['by_branch_totals']);

        $main = $this->reports()->loanBalanceSummary(['branch_ids' => [$this->branch->id]]);
        $this->assertSame([$this->branch->id => 2], array_column($main['by_branch'], 'active_loan_count', 'branch_id'));
        $this->assertSame(2, $main['active_loans']);
    }

    // -----------------------------------------------------------------
    // Past due by member
    // -----------------------------------------------------------------

    public function test_past_due_by_member_equals_the_past_due_pages_member_grouping_and_the_portfolio_total(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $this->releaseTo($ana, 60000);
        $this->releaseTo($ana, 30000, now()->subMonths(3)->toDateString());
        $ben = $this->member($this->far, 'Ben', 'Reyes');
        $this->releaseTo($ben, 90000);
        // Due yesterday, inside the 3-day grace: owed, not late.
        $this->releaseTo($this->member($this->branch, 'Carla', 'Diaz'), 40000, now()->subMonth()->subDay()->toDateString());
        // Late but part paid: still listed, for what's left.
        $dan = $this->member($this->branch, 'Dan', 'Lim');
        $this->pay($this->releaseTo($dan, 20000), 1000);

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $scope = $this->scopeOf($user);
            $figures = $this->reports()->overdueMembers($scope);

            $expected = $this->pastDuePageMembers($user);
            $actual = [];
            foreach ($figures['members'] as $member) {
                $actual[$member['code']] = [
                    'past_due_amount' => $member['past_due_amount'],
                    'days_overdue' => $member['days_overdue'],
                    'loan_count' => $member['loan_count'],
                ];
            }
            ksort($expected);
            ksort($actual);

            $this->assertSame($expected, $actual);
            $this->assertSame(count($expected), $figures['member_count']);
            $this->assertSame(array_sum(array_column($expected, 'loan_count')), $figures['loan_count']);

            $portfolio = app(PortfolioOutstanding::class)->totals($scope['branch_ids']);
            $this->assertSame(number_format($portfolio['overdue_amount'], 2, '.', ''), $figures['past_due_amount']);

            $page = $this->actingAs($user)->getJson('/api/reports/due-past-due?past_due_only=1&per_page=1000')->assertOk();
            $this->assertSame(number_format((float) $page->json('totals.past_due_amount'), 2, '.', ''), $figures['past_due_amount']);
        }

        $all = $this->reports()->overdueMembers(['branch_ids' => null]);
        $this->assertSame(3, $all['member_count']);
        $this->assertSame([$ana->id, $ben->id, $dan->id], collect($all['members'])->sortBy('borrower_id')->pluck('borrower_id')->values()->all());
        $this->assertSame(2, collect($all['members'])->firstWhere('borrower_id', $ana->id)['loan_count']);
        $this->assertSame('Ana Cruz', collect($all['members'])->firstWhere('borrower_id', $ana->id)['name']);
        $this->assertSame($ana->borrower_code, collect($all['members'])->firstWhere('borrower_id', $ana->id)['code']);

        $main = $this->reports()->overdueMembers(['branch_ids' => [$this->branch->id]]);
        $this->assertSame(2, $main['member_count']);
        $this->assertNotContains($ben->id, array_column($main['members'], 'borrower_id'));

        $far = $this->reports()->overdueMembers(['branch_ids' => null, 'branch_id' => $this->far->id]);
        $this->assertSame([$ben->id], array_column($far['members'], 'borrower_id'));
    }

    public function test_past_due_members_are_sorted_by_amount_then_name(): void
    {
        $this->releaseTo($this->member($this->branch, 'Zed', 'Uy'), 30000);
        $this->releaseTo($this->member($this->branch, 'Abe', 'Uy'), 30000);
        $this->releaseTo($this->member($this->branch, 'Mia', 'Go'), 90000);

        $members = $this->reports()->overdueMembers(['branch_ids' => null])['members'];

        $this->assertSame(['Mia Go', 'Abe Uy', 'Zed Uy'], array_column($members, 'name'));
        $this->assertSame($members[1]['past_due_amount'], $members[2]['past_due_amount']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $members[0]['past_due_amount']);
    }

    public function test_no_past_due_reads_zero_members_and_a_zero_total(): void
    {
        $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000, now()->toDateString());

        $figures = $this->reports()->overdueMembers(['branch_ids' => null]);

        $this->assertSame(['member_count' => 0, 'loan_count' => 0, 'past_due_amount' => '0.00', 'members' => []], $figures);
        $this->assertSame(['member_count' => 0, 'loan_count' => 0, 'past_due_amount' => '0.00', 'members' => []], $this->reports()->overdueMembers(['branch_ids' => []]));
    }

    // -----------------------------------------------------------------
    // Existing figures unchanged
    // -----------------------------------------------------------------

    public function test_the_reports_keep_every_existing_key_and_only_add_the_new_ones(): void
    {
        $loan = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));
        $this->pay($loan, 1500);
        $today = now()->toDateString();
        $filters = ['branch_ids' => null, 'date_from' => $today, 'date_to' => $today];

        $daily = $this->reports()->dailyCollection($filters);
        $this->assertSame([
            'date', 'date_from', 'date_to', 'total_due', 'total_collected', 'collection_rate', 'uncollected',
            'collected_in_full', 'partially_collected', 'counts', 'penalty_collected', 'total_received', 'payment_count', 'generated_at',
        ], array_keys($daily));
        $this->assertEquals(1500, $daily['total_received']);

        $efficiency = $this->reports()->collectionEfficiency($filters);
        $this->assertSame([
            'total_due', 'total_collected', 'collection_rate', 'uncollected', 'collected_in_full', 'partially_collected',
            'counts', 'penalty_collected', 'total_received', 'payment_count', 'date_from', 'date_to', 'by_branch',
            'by_branch_totals', 'by_period', 'note', 'generated_at',
        ], array_keys($efficiency));

        $summary = $this->reports()->loanBalanceSummary(['branch_ids' => null]);
        $this->assertSame([
            'portfolio', 'active_loans', 'total_outstanding', 'outstanding_principal', 'outstanding_balance', 'at_risk_amount',
            'par_ratio', 'par_threshold_days', 'outstanding', 'overdue', 'by_branch', 'by_branch_totals', 'generated_at',
        ], array_keys($summary));
        $this->assertSame([
            'branch_id', 'branch_name', 'loan_count', 'total_released', 'total_outstanding', 'outstanding_principal',
            'outstanding_balance', 'active_loan_count',
        ], array_keys($summary['by_branch'][0]));
        $this->assertSame([
            'loan_count', 'total_released', 'total_outstanding', 'outstanding_principal', 'outstanding_balance',
        ], array_keys($summary['by_branch_totals']));
        $this->assertSame(1, $summary['by_branch_totals']['loan_count']);
        $this->assertEquals(60000, $summary['by_branch_totals']['total_released']);
        $this->assertEquals($summary['total_outstanding'], $summary['by_branch_totals']['total_outstanding']);

        $totals = app(PortfolioOutstanding::class)->totals(null);
        $this->assertSame(['total_outstanding', 'outstanding_principal', 'overdue_amount', 'overdue_count'], array_keys($totals));
    }

    /**
     * The Past Due Loans page's grouping, as the frontend does it
     * (past-due-loans.ts): GET /reports/due-past-due?past_due_only=1 rows,
     * one entry per loan with its highest days past due and the loan's
     * `loan_past_due_amount`, then per member (`borrower_code`).
     *
     * @return array<string, array{past_due_amount: string, days_overdue: int, loan_count: int}>
     */
    private function pastDuePageMembers($user): array
    {
        $rows = $this->actingAs($user)->getJson('/api/reports/due-past-due?past_due_only=1&per_page=1000')->assertOk()->json('data');

        $loans = [];
        foreach ($rows as $row) {
            $loan = $loans[$row['loan_id']] ?? ['code' => $row['borrower_code'], 'days' => 0, 'amount' => $row['loan_past_due_amount']];
            $loan['days'] = max($loan['days'], $row['days_overdue']);
            $loans[$row['loan_id']] = $loan;
        }

        $members = [];
        foreach ($loans as $loan) {
            $member = $members[$loan['code']] ?? ['centavos' => 0, 'days_overdue' => 0, 'loan_count' => 0];
            $member['centavos'] += (int) round((float) $loan['amount'] * 100);
            $member['days_overdue'] = max($member['days_overdue'], $loan['days']);
            $member['loan_count']++;
            $members[$loan['code']] = $member;
        }

        return array_map(fn (array $m) => [
            'past_due_amount' => number_format($m['centavos'] / 100, 2, '.', ''),
            'days_overdue' => $m['days_overdue'],
            'loan_count' => $m['loan_count'],
        ], $members);
    }
}
