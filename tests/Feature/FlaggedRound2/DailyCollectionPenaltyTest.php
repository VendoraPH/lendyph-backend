<?php

use App\Models\Branch;
use App\Models\Loan;
use App\Models\Repayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * The Daily Collection report follows the dashboard's daily-dues rule
 * (product-decisions.md, "Dashboard daily dues"): a period counts as collected
 * only once its principal and interest are paid, penalty paid never counts,
 * a penalty-only payment leaves it pending, periods with nothing due are left
 * out, and the collection rate never passes 100%. Penalty and the cash taken in
 * are reported as their own server figures (plan, decision 2).
 *
 * Loan shape (SetupLendyPH::createReleasedLoan): 60,000 principal, 6 monthly
 * periods, 3% straight interest, so each period is 10,000 + 1,800 = 11,800 due.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

function flaggedMovePeriod(Loan $loan, int $periodNumber, int $daysAgo): void
{
    $loan->amortizationSchedules()
        ->where('period_number', $periodNumber)
        ->firstOrFail()
        ->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);
}

function flaggedPostPayment($test, Loan $loan, float $amount): Repayment
{
    $id = $test->postJson("/api/loans/{$loan->id}/repayments", [
        'payment_date' => Carbon::today()->toDateString(),
        'amount_paid' => $amount,
        'method' => 'cash',
    ])->assertCreated()->json('data.id');

    return Repayment::findOrFail($id);
}

it('counts a penalty-only payment as nothing collected towards the period due', function () {
    $loan = $this->createReleasedLoan();
    flaggedMovePeriod($loan, 1, 40);

    $payment = flaggedPostPayment($this, $loan, 100);

    // Setup check: the 100 went to penalty only.
    expect((float) $payment->penalty_applied)->toBe(100.0);
    expect((float) $payment->principal_applied + (float) $payment->interest_applied)->toBe(0.0);

    $from = Carbon::today()->subDays(40)->toDateString();
    $to = Carbon::today()->toDateString();
    $report = $this->getJson("/api/reports/daily-collection?date_from={$from}&date_to={$to}")->assertOk();

    expect((float) $report->json('data.total_due'))->toBe(11800.0);
    expect((float) $report->json('data.total_collected'))->toBe(0.0);
    expect((float) $report->json('data.collection_rate'))->toBe(0.0);
    expect((float) $report->json('data.uncollected'))->toBe(11800.0);
});

it('never reports a collection rate above 100% when more is paid than fell due', function () {
    $loan = $this->createReleasedLoan();
    flaggedMovePeriod($loan, 1, 0);

    // Period 1 in full plus period 2 in advance.
    flaggedPostPayment($this, $loan, 23600);

    $today = Carbon::today()->toDateString();
    $report = $this->getJson("/api/reports/daily-collection?date_from={$today}&date_to={$today}")->assertOk();

    expect((float) $report->json('data.total_due'))->toBe(11800.0);
    expect((float) $report->json('data.total_collected'))->toBe(11800.0);
    expect((float) $report->json('data.collection_rate'))->toBe(100.0);
    expect((float) $report->json('data.uncollected'))->toBe(0.0);
});

it('reports penalty collected and cash received as their own server figures', function () {
    $loan = $this->createReleasedLoan();
    flaggedMovePeriod($loan, 1, 40);

    flaggedPostPayment($this, $loan, 100);

    $from = Carbon::today()->subDays(40)->toDateString();
    $to = Carbon::today()->toDateString();
    $report = $this->getJson("/api/reports/daily-collection?date_from={$from}&date_to={$to}")->assertOk();

    expect($report->json('data.penalty_collected'))->not->toBeNull();
    expect((float) $report->json('data.penalty_collected'))->toBe(100.0);
    expect($report->json('data.total_received'))->not->toBeNull();
    expect((float) $report->json('data.total_received'))->toBe(100.0);
});

it('counts periods as collected, partial or pending and leaves out periods with nothing due', function () {
    $paidInFull = $this->createReleasedLoan();
    flaggedMovePeriod($paidInFull, 1, 0);
    flaggedPostPayment($this, $paidInFull, 11800);

    $partlyPaid = $this->createReleasedLoan();
    flaggedMovePeriod($partlyPaid, 1, 0);
    flaggedPostPayment($this, $partlyPaid, 5900);

    $unpaid = $this->createReleasedLoan();
    flaggedMovePeriod($unpaid, 1, 0);
    // A period closed with nothing due, on the same day: not a due.
    flaggedMovePeriod($unpaid, 2, 0);
    $unpaid->amortizationSchedules()->where('period_number', 2)->update(['total_due' => 0]);

    $today = Carbon::today()->toDateString();
    $report = $this->getJson("/api/reports/daily-collection?date_from={$today}&date_to={$today}")->assertOk();

    expect($report->json('data.counts'))->toBe(['collected' => 1, 'partial' => 1, 'pending' => 1]);
    expect((float) $report->json('data.total_due'))->toBe(35400.0);
    expect((float) $report->json('data.total_collected'))->toBe(17700.0);
    expect((float) $report->json('data.collection_rate'))->toBe(50.0);
});

it('agrees with the dashboard daily dues for the same day', function () {
    $paidInFull = $this->createReleasedLoan();
    flaggedMovePeriod($paidInFull, 1, 0);
    flaggedPostPayment($this, $paidInFull, 11800);

    $partlyPaid = $this->createReleasedLoan();
    flaggedMovePeriod($partlyPaid, 1, 0);
    flaggedPostPayment($this, $partlyPaid, 3000.55);

    $unpaid = $this->createReleasedLoan();
    flaggedMovePeriod($unpaid, 1, 0);

    $today = Carbon::today()->toDateString();
    $report = $this->getJson("/api/reports/daily-collection?date_from={$today}&date_to={$today}")->assertOk()->json('data');
    $dashboard = $this->getJson("/api/dashboard/daily-dues?date={$today}")->assertOk()->json('summary');

    foreach (['total_due', 'total_collected', 'collected_in_full', 'partially_collected', 'uncollected'] as $figure) {
        expect((float) $report[$figure])->toBe((float) $dashboard[$figure], $figure);
    }
    expect($report['counts'])->toBe($dashboard['counts']);
    // The dashboard rounds the rate to one decimal, the report to two.
    expect(round($report['collection_rate'], 1))->toBe(round((float) $dashboard['collection_rate'], 1));
});

it('gives Collection Efficiency the same overall figures, and caps every branch and month row', function () {
    $loan = $this->createReleasedLoan();
    flaggedMovePeriod($loan, 1, 0);
    flaggedPostPayment($this, $loan, 23600);

    $today = Carbon::today()->toDateString();
    $daily = $this->getJson("/api/reports/daily-collection?date_from={$today}&date_to={$today}")->assertOk()->json('data');
    $efficiency = $this->getJson("/api/reports/collection-efficiency?date_from={$today}&date_to={$today}")->assertOk()->json('data');

    foreach (['total_due', 'total_collected', 'collection_rate', 'uncollected', 'collected_in_full', 'partially_collected', 'counts', 'penalty_collected', 'total_received'] as $figure) {
        expect($efficiency[$figure])->toBe($daily[$figure], $figure);
    }
    expect((float) $efficiency['total_received'])->toBe(23600.0);

    foreach ([...$efficiency['by_branch'], ...$efficiency['by_period']] as $row) {
        expect((float) $row['collection_rate'])->toBeLessThanOrEqual(100.0);
    }
    expect((float) $efficiency['by_branch'][0]['total_collected'])->toBe(11800.0);
});

it('narrows penalty collected and cash received to the branch filter', function () {
    $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);

    $main = $this->createReleasedLoan();
    flaggedMovePeriod($main, 1, 40);
    flaggedPostPayment($this, $main, 100);

    $other = $this->createReleasedLoan();
    DB::table('loans')->where('id', $other->id)->update(['branch_id' => $satellite->id]);
    flaggedMovePeriod($other, 1, 40);
    $payment = flaggedPostPayment($this, $other, 150);
    expect((float) $payment->penalty_applied)->toBe(150.0);

    $from = Carbon::today()->subDays(40)->toDateString();
    $to = Carbon::today()->toDateString();
    $figures = fn (string $extra) => $this->getJson("/api/reports/daily-collection?date_from={$from}&date_to={$to}{$extra}")->assertOk()->json('data');

    $mainOnly = $figures("&branch_id={$this->branch->id}");
    expect((float) $mainOnly['penalty_collected'])->toBe(100.0);
    expect((float) $mainOnly['total_received'])->toBe(100.0);
    expect((float) $mainOnly['total_due'])->toBe(11800.0);

    $satelliteOnly = $figures("&branch_id={$satellite->id}");
    expect((float) $satelliteOnly['penalty_collected'])->toBe(150.0);
    expect((float) $satelliteOnly['total_received'])->toBe(150.0);

    $all = $figures('');
    expect((float) $all['penalty_collected'])->toBe(250.0);
    expect((float) $all['total_received'])->toBe(250.0);
    expect((float) $all['total_collected'])->toBe(0.0);
});

it('takes a voided payment back out of both reports', function () {
    $loan = $this->createReleasedLoan();
    flaggedMovePeriod($loan, 1, 0);
    $payment = flaggedPostPayment($this, $loan, 5900);

    $this->patchJson("/api/repayments/{$payment->id}/void", [
        'void_reason' => 'Duplicate receipt captured at the counter.',
    ])->assertOk();

    $today = Carbon::today()->toDateString();
    foreach (['daily-collection', 'collection-efficiency'] as $report) {
        $data = $this->getJson("/api/reports/{$report}?date_from={$today}&date_to={$today}")->assertOk()->json('data');

        expect((float) $data['total_due'])->toBe(11800.0, $report);
        expect((float) $data['total_collected'])->toBe(0.0, $report);
        expect((float) $data['collection_rate'])->toBe(0.0, $report);
        expect((float) $data['total_received'])->toBe(0.0, $report);
        expect($data['counts'])->toBe(['collected' => 0, 'partial' => 0, 'pending' => 1], $report);
    }
});

it('has branch rows and month rows that add up to the overall figures', function () {
    $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
    $monthOne = Carbon::today()->startOfMonth()->subMonths(2)->addDays(14);
    $monthTwo = Carbon::today()->startOfMonth()->subMonth()->addDays(14);

    $setPeriod = function (Loan $loan, int $period, Carbon $due, float $principalPaid, float $interestPaid, float $penaltyPaid = 0): void {
        $loan->amortizationSchedules()->where('period_number', $period)->update([
            'due_date' => $due->toDateString(),
            'principal_paid' => $principalPaid,
            'interest_paid' => $interestPaid,
            'penalty_paid' => $penaltyPaid,
        ]);
    };

    // Main: month one paid in full, month two 5,000 paid.
    $main = $this->createReleasedLoan();
    $setPeriod($main, 1, $monthOne, 10000, 1800);
    $setPeriod($main, 2, $monthTwo, 3200, 1800);

    // Satellite: month one unpaid, month two paid in full plus penalty (never counted).
    $other = $this->createReleasedLoan();
    DB::table('loans')->where('id', $other->id)->update(['branch_id' => $satellite->id]);
    $setPeriod($other, 1, $monthOne, 0, 0);
    $setPeriod($other, 2, $monthTwo, 10000, 1800, 300);

    $from = Carbon::today()->startOfMonth()->subMonths(2)->toDateString();
    $to = Carbon::today()->startOfMonth()->subMonth()->endOfMonth()->toDateString();
    $data = $this->getJson("/api/reports/collection-efficiency?date_from={$from}&date_to={$to}")->assertOk()->json('data');

    expect((float) $data['total_due'])->toBe(47200.0);
    expect((float) $data['total_collected'])->toBe(28600.0);
    expect((float) $data['uncollected'])->toBe(18600.0);
    expect((float) $data['collection_rate'])->toBe(60.59);

    $branches = collect($data['by_branch'])->keyBy('branch_id');
    expect($branches)->toHaveCount(2);
    expect((float) $branches[$this->branch->id]['total_collected'])->toBe(16800.0);
    expect((float) $branches[$satellite->id]['total_collected'])->toBe(11800.0);

    $months = collect($data['by_period'])->keyBy('period');
    expect($months)->toHaveCount(2);
    expect((float) $months[$monthOne->format('Y-m')]['total_collected'])->toBe(11800.0);
    expect((float) $months[$monthTwo->format('Y-m')]['total_collected'])->toBe(16800.0);

    foreach (['by_branch', 'by_period'] as $rows) {
        foreach (['total_due', 'total_collected', 'uncollected'] as $figure) {
            expect(round(collect($data[$rows])->sum(fn (array $row) => (float) $row[$figure]), 2))
                ->toBe((float) $data[$figure], "{$rows} {$figure}");
        }
    }
});
