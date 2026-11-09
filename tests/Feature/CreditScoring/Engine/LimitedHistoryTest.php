<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditScore;
use App\Models\Loan;
use App\Models\User;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

/**
 * Limited History follows the product rule: zero completed loans is no
 * data, unless one of the member's loans ended badly (defaulted, written off
 * or restructured), and a thin file always reads Low confidence.
 */
uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

it('reads Low for a member with no loan, even with verified income and declared expenses', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $application = Loan::factory()->create([
        'borrower_id' => $member->id, 'branch_id' => $member->branch_id, 'status' => 'draft',
        'created_by' => User::query()->value('id'),
    ]);
    CreditApplicationProfile::query()->create(['loan_id' => $application->id, 'household_expenses' => 10000, 'income_verification' => 'verified']);

    $result = $this->scoreOf($member, $application->fresh());

    expect($result['limited_history'])->toBeTrue()
        ->and($result['confidence'])->toBe('low');
});

it('reads a first loan in progress as Limited History, Low, however many periods have matured', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $this->paidMonthly($this->releasedLoan($member, ['released_at' => '2025-09-01']), '2025-10-01', 12);

    $result = $this->scoreOf($member);

    expect($result['data_used']['matured_periods'])->toBe(12)
        ->and($result['limited_history'])->toBeTrue()
        ->and($result['confidence'])->toBe('low');
});

it('leaves Limited History once a loan is completed, and confidence follows the periods again', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $this->paidMonthly($this->releasedLoan($member, ['status' => 'completed', 'released_at' => '2025-09-01']), '2025-10-01', 6);
    $this->paidMonthly($this->releasedLoan($member, ['released_at' => '2026-04-01']), '2026-05-01', 6);

    $result = $this->scoreOf($member);

    expect($result['limited_history'])->toBeFalse()
        ->and($result['confidence'])->toBe('medium');
});

it('counts a loan that ended badly as history, not a thin file', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $defaulted = $this->releasedLoan($member, ['status' => 'defaulted']);
    $this->paidMonthly($defaulted, '2026-05-01', 3);
    $this->period($defaulted, 4, '2026-08-01');

    $result = $this->scoreOf($member);

    expect($result['limited_history'])->toBeFalse()
        ->and($result['confidence'])->not->toBe('low');
});

it('writes a new row when only the Limited History label or confidence reason changes, and none when nothing does', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $this->paidMonthly($this->releasedLoan($member), '2026-06-01', 4);
    $recalculator = app(Recalculator::class);
    $recalculator->scoreMember($member->id, 'Nightly review');
    $rows = fn () => CreditScore::query()->where('borrower_id', $member->id)->count();

    $recalculator->scoreMember($member->id, 'Nightly review');
    expect($rows())->toBe(1);

    // A row written under the old rule: same score, band and confidence, another label.
    DB::table('credit_scores')->where('borrower_id', $member->id)
        ->update(['limited_history' => false, 'confidence_reason' => 'Only 4 matured periods of Lendy repayment history.']);

    $recalculator->scoreMember($member->id, 'Nightly review');
    $latest = CreditScore::query()->where('borrower_id', $member->id)->latest('id')->first();

    expect($rows())->toBe(2)
        ->and($latest->limited_history)->toBeTrue()
        ->and($latest->confidence_reason)->toStartWith('Limited History');

    $recalculator->scoreMember($member->id, 'Nightly review');
    expect($rows())->toBe(2);
});
