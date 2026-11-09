<?php

use App\Models\Branch;
use App\Models\CreditApplicationProfile;
use App\Models\CreditPolicyFlag;
use App\Services\CreditScoring\Recalculator;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

it('scores a thin file on the factors it has, scaled, as Limited History with low confidence', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member);
    $this->paidPeriod($loan, 1, '2026-10-01', '2026-10-01');
    $this->period($loan, 2, '2026-12-01');

    $result = $this->scoreOf($member);

    // Earned 15+8+7+5 (repayment) + 6+4 (exposure) + 1+0+0 (relationship)
    // + 0+1 (application) = 47 of the 58 points with data.
    expect($result['status'])->toBe('scored')
        ->and($result['score'])->toBe((int) round(100 * 47 / 58))
        ->and($result['score'])->toBe(81)
        ->and($result['risk_level'])->toBe('low')
        ->and($result['score_type'])->toBe('behavioral')
        ->and($result['confidence'])->toBe('low')
        ->and($result['limited_history'])->toBeTrue()
        ->and($this->factorOf($result, 'debt_service_ratio')['has_data'])->toBeFalse()
        ->and($this->factorOf($result, 'debt_service_ratio')['points_earned'])->toBe(0)
        ->and(collect($result['categories'])->firstWhere('key', 'capacity'))->toMatchArray(['has_data' => false, 'points_possible' => 0]);
});

it('answers insufficient data with no repayment and no capacity data', function () {
    $member = $this->member();

    $result = $this->scoreOf($member);

    expect($result['status'])->toBe('insufficient_data')
        ->and($result['score'])->toBeNull()
        ->and($result['risk_level'])->toBeNull()
        ->and($result['score_type'])->toBe('application')
        ->and($result['confidence'])->toBe('low')
        ->and($result['recommendation'])->toContain('Not enough data');
});

it('scores a new member on capacity alone as an application score', function () {
    $member = $this->member(['monthly_income' => 40000]);

    $result = $this->scoreOf($member);

    expect($result['status'])->toBe('scored')
        ->and($result['score_type'])->toBe('application')
        ->and($this->factorOf($result, 'debt_service_ratio')['points_earned'])->toBe(15)
        ->and($result['limited_history'])->toBeTrue();
});

it('counts loans in every branch', function () {
    $member = $this->member();
    $other = Branch::factory()->create();
    $here = $this->releasedLoan($member);
    $there = $this->releasedLoan($member, ['branch_id' => $other->id]);
    $this->paidPeriod($here, 1, '2026-10-01', '2026-10-01');
    $this->paidPeriod($there, 1, '2026-10-05', '2026-10-20');
    $this->period($here, 2, '2026-12-01');
    $this->period($there, 2, '2026-12-05');

    $result = $this->scoreOf($member);

    expect($result['data_used']['loan_ids'])->toEqualCanonicalizing([$here->id, $there->id])
        ->and($result['data_used']['branch_ids'])->toEqualCanonicalizing([$member->branch_id, $other->id])
        ->and($result['data_used']['matured_periods'])->toBe(2)
        ->and($this->factorOf($result, 'active_loans')['raw_value']['value'])->toBe(2)
        ->and($this->factorOf($result, 'on_time_rate')['raw_value']['value'])->toEqual(50)
        ->and($this->factorOf($result, 'worst_days_late')['raw_value']['value'])->toBe(15);
});

it('leaves out an imported loan\'s periods due before its arrears baseline', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member, ['imported_arrears_baseline' => '2026-09-15', 'external_loan_no' => 'OLD-1']);
    $this->period($loan, 1, '2026-08-01');
    $this->paidPeriod($loan, 2, '2026-10-01', '2026-10-01');

    $result = $this->scoreOf($member);

    expect($result['data_used']['matured_periods'])->toBe(1)
        ->and($result['data_used']['days_past_due'])->toBe(0)
        ->and($this->factorOf($result, 'on_time_rate')['raw_value']['value'])->toEqual(100)
        ->and($this->factorOf($result, 'days_past_due')['points_earned'])->toBe(7);
});

it('reads lateness, arrears and defaults from the schedule and its payments', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member, ['status' => 'defaulted']);
    $this->paidPeriod($loan, 1, '2026-08-01', '2026-08-11');
    $this->period($loan, 2, '2026-09-24');
    $this->period($loan, 3, '2026-11-07');

    $result = $this->scoreOf($member);

    // Period 3 is within its 3 days of grace, so not matured yet.
    expect($result['data_used']['matured_periods'])->toBe(2)
        ->and($result['data_used']['days_past_due'])->toBe(45)
        ->and($this->factorOf($result, 'on_time_rate')['points_earned'])->toBe(0)
        ->and($this->factorOf($result, 'worst_days_late')['raw_value']['value'])->toBe(45)
        ->and($this->factorOf($result, 'worst_days_late')['points_earned'])->toBe(1)
        ->and($this->factorOf($result, 'days_past_due')['points_earned'])->toBe(0)
        ->and($this->factorOf($result, 'defaults_restructures')['points_earned'])->toBe(0)
        ->and(collect($result['flags'])->pluck('rule_key')->all())->toContain('past_due_over_limit', 'existing_default')
        ->and($result['recommendation'])->toStartWith('Manual review required: ');
});

it('takes the job tenure or business age, whichever is longer, and blanks cost nothing', function () {
    $member = $this->member(['monthly_income' => 40000, 'date_hired' => '2025-11-01']);
    $loan = $this->releasedLoan($member);
    $this->paidPeriod($loan, 1, '2026-10-01', '2026-10-01');
    CreditApplicationProfile::query()->create(['loan_id' => $loan->id, 'business_start_date' => '2020-01-01', 'years_at_residence' => 2.5]);

    $result = $this->scoreOf($member);

    expect($this->factorOf($result, 'tenure')['points_earned'])->toBe(6)
        ->and($this->factorOf($result, 'residence')['points_earned'])->toBe(3)
        ->and($this->factorOf($result, 'income_verified')['has_data'])->toBeFalse()
        ->and($this->factorOf($result, 'verified_references')['has_data'])->toBeFalse();
});

it('caps confidence at medium when most matured periods rely on payments from before 1 October 2026', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member, ['status' => 'completed']);
    $this->paidMonthly($loan, '2025-09-01', 12);
    CreditApplicationProfile::query()->create(['loan_id' => $loan->id, 'household_expenses' => 10000]);

    $result = $this->scoreOf($member);

    expect($result['data_used']['legacy_periods'])->toBe(12)
        ->and($result['confidence'])->toBe('medium')
        ->and($result['confidence_reason'])->toContain('Capped at medium');
});

it('gives high confidence to 12 matured periods with income and expenses on current records', function () {
    $this->pinToday('2027-11-08 09:00:00');
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member, ['status' => 'completed']);
    $this->paidMonthly($loan, '2026-10-01', 12);
    CreditApplicationProfile::query()->create(['loan_id' => $loan->id, 'household_expenses' => 10000]);

    $result = $this->scoreOf($member);

    expect($result['data_used']['legacy_periods'])->toBe(0)
        ->and($result['confidence'])->toBe('high')
        ->and($result['limited_history'])->toBeFalse()
        ->and($this->factorOf($result, 'completed_loans')['points_earned'])->toBe(2)
        ->and($this->factorOf($result, 'clean_completed_loans')['points_earned'])->toBe(1);
});

it('keeps policy flags in step: opened when raised, cleared when no longer', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member);
    $late = $this->period($loan, 1, '2026-09-24');
    $recalculator = app(Recalculator::class);

    $recalculator->scoreMember($member->id, 'Nightly review');
    $flag = CreditPolicyFlag::query()->where('borrower_id', $member->id)->where('rule_key', 'past_due_over_limit')->firstOrFail();
    expect($flag->type)->toBe('hard_flag')->and($flag->cleared_at)->toBeNull()->and($flag->loan_id)->toBeNull();

    $late->update(['principal_paid' => 1000, 'interest_paid' => 100, 'status' => 'paid']);
    $this->travel(1)->minutes();
    $recalculator->scoreMember($member->id, 'Payment recorded or changed');

    expect($flag->fresh()->cleared_at)->not->toBeNull()
        ->and(CreditPolicyFlag::query()->where('borrower_id', $member->id)->open()->where('rule_key', 'past_due_over_limit')->exists())->toBeFalse();
});

it('raises a soft flag for a possible duplicate member record', function () {
    $member = $this->member(['first_name' => 'Juana', 'last_name' => 'Dela Cruz']);
    $this->member(['first_name' => 'juana ', 'last_name' => 'DELA CRUZ']);

    $flags = collect($this->scoreOf($member)['flags']);

    expect($flags->firstWhere('rule_key', 'possible_duplicate'))->toMatchArray(['type' => 'soft_flag', 'scope' => 'member']);
});
