<?php

use App\Models\Loan;
use App\Models\Repayment;
use Carbon\Carbon;
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
