<?php

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicValidator;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordBuilder;
use App\Services\Cic\Records\CicRecordSet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * Which loans a file reports (money review M-H2, M-M3, M-L6, M-L9): a loan
 * released on the reference date waits a month; a closure is reported once,
 * even when its last payment is backdated into a month already filed; a loan
 * released and closed on the same day, and a completed loan with no closing
 * event, are left out with an issue, never silently.
 */

beforeEach(function () {
    $this->cicOn();
});

function cicClosuresBuild(string $date = '2026-11-30'): CicRecordSet
{
    return app(CicRecordBuilder::class)->build(Carbon::parse($date));
}

function cicClosuresRecord(CicRecordSet $set, Loan $loan): ?CicRecord
{
    return collect([...$set->ofType('CI'), ...$set->ofType('CN')])->first(fn (CicRecord $r) => $r->loanId === $loan->id);
}

/** A complete month-end capture holding each loan's contract record in the given phase. */
function cicClosuresMonthEnd(string $date, array $phases): CicCapture
{
    $capture = CicCapture::factory()->monthEnd($date)->create();

    foreach ($phases as $loanId => $phase) {
        $values = ['CI1' => 'CI', 'CI7' => "LN-{$loanId}", 'CI9' => $phase];
        CicCaptureRecord::factory()->for($capture, 'capture')->create([
            'record_type' => 'CI', 'record_key' => "LN-{$loanId}", 'loan_id' => $loanId, 'values' => $values, 'checksum' => CicCaptureRecord::checksumOf($values),
        ]);
    }

    $capture->forceFill(['status' => CicCapture::STATUS_COMPLETE, 'contract_count' => count($phases), 'checksum' => $capture->computeChecksum(), 'finished_at' => now()])->save();

    return $capture->refresh();
}

function cicClosuresComplete(Loan $loan, string $paidOn): void
{
    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => $paidOn, 'amount_paid' => 100, 'status' => 'posted']);
    DB::table('loans')->where('id', $loan->id)->update(['status' => 'completed']);
}

it('reports only loans released before the reference date; a loan released on it waits for the next month', function () {
    $before = $this->cicLoan(releasedAt: '2026-11-29 16:00:00');
    $onTheDay = $this->cicLoan(releasedAt: '2026-11-30 08:00:00');

    $november = cicClosuresBuild();
    $december = cicClosuresBuild('2026-12-31');

    expect(cicClosuresRecord($november, $before))->not->toBeNull()
        ->and(cicClosuresRecord($november, $onTheDay))->toBeNull()
        ->and($november->leftOut)->toBe([])
        ->and(cicClosuresRecord($december, $onTheDay))->not->toBeNull();
});

it('leaves a loan released and closed on the same day out, with a warning', function () {
    $loan = $this->cicLoan(releasedAt: '2026-11-10 09:00:00');
    cicClosuresComplete($loan, '2026-11-10');

    $set = cicClosuresBuild();
    $issues = app(CicValidator::class)->issuesForSet($set);

    expect(cicClosuresRecord($set, $loan))->toBeNull()
        ->and($set->leftOut)->toBe([[
            'record_type' => 'CI', 'record_key' => $loan->loan_account_number, 'field_id' => 'CI16', 'severity' => 'warning', 'code' => 'SAME_DAY_PAYOFF',
            'message' => 'This loan was released and closed on the same day; it is left out until CIC confirms how to send it (Q17).',
            'loan_id' => $loan->id,
        ]])
        ->and(collect($issues)->where('code', 'SAME_DAY_PAYOFF')->sole())->toMatchArray(['record_key' => $loan->loan_account_number, 'severity' => 'warning']);
});

it('reports a closure once, with its true end date, when the last payment is backdated into a month already filed', function () {
    $loan = $this->cicLoan();
    cicClosuresMonthEnd('2026-10-31', [$loan->id => 'AC']);
    cicClosuresComplete($loan, '2026-10-31');

    $record = cicClosuresRecord(cicClosuresBuild(), $loan);

    expect($record)->not->toBeNull()
        ->and($record->values['CI9'])->toBe('CA')
        ->and($record->values['CI16'])->toBe('31102026')
        ->and($record->values['CI17'])->toBe('31102026');

    // November's month-end holds it closed, so December leaves it out.
    cicClosuresMonthEnd('2026-11-30', [$loan->id => 'CA']);
    expect(cicClosuresRecord(cicClosuresBuild('2026-12-31'), $loan))->toBeNull();
});

it('never drops a completed loan with no closing event silently: a warning, or an error when it was reported active', function () {
    $quiet = $this->cicLoan();
    $reported = $this->cicLoan();
    $old = $this->cicLoan();
    DB::table('loans')->whereIn('id', [$quiet->id, $reported->id])->update(['status' => 'completed', 'updated_at' => '2026-11-20 10:00:00']);
    DB::table('loans')->where('id', $old->id)->update(['status' => 'completed', 'updated_at' => '2026-09-20 10:00:00']);
    cicClosuresMonthEnd('2026-10-31', [$reported->id => 'AC']);

    $set = cicClosuresBuild();
    $leftOut = collect($set->leftOut)->keyBy('record_key');

    expect(cicClosuresRecord($set, $quiet))->toBeNull()
        ->and(cicClosuresRecord($set, $reported))->toBeNull()
        ->and($leftOut->keys()->sort()->values()->all())->toBe(collect([$quiet->loan_account_number, $reported->loan_account_number])->sort()->values()->all())
        ->and($leftOut[$quiet->loan_account_number])->toMatchArray(['severity' => 'warning', 'code' => 'NO_CLOSING_EVENT'])
        ->and($leftOut[$reported->loan_account_number])->toMatchArray(['severity' => 'error', 'code' => 'NO_CLOSING_EVENT'])
        ->and($leftOut[$reported->loan_account_number]['message'])->toContain('reported active last month');
});

it('freezes what it left out with the capture, and its validation reads it', function () {
    config(['cic.test_captures' => true]);
    $this->cicLoan();
    $sameDay = $this->cicLoan(releasedAt: '2026-11-10 09:00:00');
    cicClosuresComplete($sameDay, '2026-11-10');

    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);

    expect($capture->status)->toBe('complete')
        ->and($capture->contract_count)->toBe(1)
        ->and($capture->left_out)->toHaveCount(1)
        ->and($capture->left_out[0])->toMatchArray(['record_key' => $sameDay->loan_account_number, 'code' => 'SAME_DAY_PAYOFF'])
        ->and(collect(app(CicValidator::class)->issues($capture))->where('code', 'SAME_DAY_PAYOFF')->count())->toBe(1);
});
