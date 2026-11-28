<?php

use App\Models\AmortizationSchedule;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordBuilder;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * Money review M-L8: CI29 and CI30 read the loan summary's next-due rule
 * through one helper (AmortizationSchedule::nextDue), never a copy.
 */

beforeEach(function () {
    $this->cicOn();
});

it('picks the unpaid period with the earliest due date, as the loan summary does', function () {
    $loan = $this->cicLoan();
    $loan->amortizationSchedules()->where('period_number', 1)->update(['status' => 'paid', 'principal_paid' => 10000, 'interest_paid' => 300]);
    $loan->amortizationSchedules()->where('period_number', 3)->update(['due_date' => '2026-10-20']);

    $next = AmortizationSchedule::nextDue($loan->fresh()->amortizationSchedules);
    $summary = app(RepaymentService::class)->getLoanSummary($loan->fresh());
    $record = collect(app(CicRecordBuilder::class)->build(Carbon::parse('2026-11-30'))->ofType('CI'))->first(fn (CicRecord $r) => $r->loanId === $loan->id);

    expect($next->period_number)->toBe(3)
        ->and($summary['next_due_date'])->toBe('2026-10-20')
        ->and($record->values['CI29'])->toBe('20102026')
        ->and($record->values['CI30'])->toBe('10300')
        ->and(AmortizationSchedule::nextDue(collect()))->toBeNull();
});
