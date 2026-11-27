<?php

use App\Models\Branch;
use App\Models\CreditApplicationProfile;
use App\Models\CreditPolicyFlag;
use App\Models\LoanAdjustment;
use App\Models\User;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Support\Facades\DB;
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

    // Earned 15+8+7+5 (repayment) + 6+4 (exposure) + 1 (membership; no
    // completed loan is no data) + 0+1 (application) = 47 of the 52 points
    // with data.
    expect($result['status'])->toBe('scored')
        ->and($result['score'])->toBe((int) round(100 * 47 / 52))
        ->and($result['score'])->toBe(90)
        ->and($result['risk_level'])->toBe('very_low')
        ->and($result['score_type'])->toBe('behavioral')
        ->and($result['confidence'])->toBe('low')
        ->and($result['limited_history'])->toBeTrue()
        ->and($this->factorOf($result, 'debt_service_ratio')['has_data'])->toBeFalse()
        ->and($this->factorOf($result, 'debt_service_ratio')['points_earned'])->toBe(0)
        ->and(collect($result['categories'])->firstWhere('key', 'capacity'))->toMatchArray(['has_data' => false, 'points_possible' => 0])
        ->and($this->factorOf($result, 'completed_loans')['has_data'])->toBeFalse();
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

it('leaves the completed-loan factors out for a first-time member, who never scores below a member with no loan', function () {
    $newcomer = $this->member(['monthly_income' => 40000]);
    $borrowerNow = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($borrowerNow);
    $this->paidMonthly($loan, '2026-08-01', 3);
    $this->period($loan, 4, '2026-12-01');

    $without = $this->scoreOf($newcomer);
    $with = $this->scoreOf($borrowerNow);

    expect($this->factorOf($with, 'completed_loans')['has_data'])->toBeFalse()
        ->and($this->factorOf($with, 'clean_completed_loans')['has_data'])->toBeFalse()
        ->and($with['score'])->toBeGreaterThanOrEqual($without['score']);
});

it('scores zero completed loans when one of the member\'s loans ended badly', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member, ['status' => 'defaulted']);
    $this->period($loan, 1, '2026-09-01');

    $result = $this->scoreOf($member);

    expect($this->factorOf($result, 'completed_loans'))->toMatchArray(['has_data' => true, 'points_earned' => 0])
        ->and($this->factorOf($result, 'clean_completed_loans'))->toMatchArray(['has_data' => true, 'points_earned' => 0]);
});

it('stops counting lateness on a restructured loan at the restructure date', function () {
    $member = $this->member();
    $source = $this->releasedLoan($member, ['status' => 'restructured', 'restructured_at' => '2026-09-01 10:00:00']);
    $this->period($source, 1, '2026-08-20');
    $this->period($source, 2, '2026-10-01');

    $result = $this->scoreOf($member);

    expect($result['data_used']['matured_periods'])->toBe(1)
        ->and($result['data_used']['worst_days_late_24m'])->toBe(12)
        ->and($result['data_used']['days_past_due'])->toBe(0);
});

it('counts an in-place restructure as a restructure', function () {
    $member = $this->member();
    $loan = $this->releasedLoan($member);
    $this->paidPeriod($loan, 1, '2026-10-01', '2026-10-01');
    LoanAdjustment::factory()->create([
        'loan_id' => $loan->id,
        'adjustment_type' => 'restructure',
        'status' => 'applied',
        'applied_at' => '2026-10-15 10:00:00',
        'new_values' => ['interest_rate' => 2],
        'adjusted_by' => User::query()->value('id'),
    ]);
    LoanAdjustment::factory()->create([
        'loan_id' => $loan->id,
        'adjustment_type' => 'restructure',
        'status' => 'pending',
        'adjusted_by' => User::query()->value('id'),
    ]);

    $result = $this->scoreOf($member);

    expect($result['data_used']['restructured_loans'])->toBe(1)
        ->and($this->factorOf($result, 'defaults_restructures')['points_earned'])->toBe(3)
        ->and($this->factorOf($result, 'completed_loans')['has_data'])->toBeTrue();
});

it('counts unpaid past-due principal and interest as this month\'s debt', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member, ['maturity_date' => '2026-09-01']);
    $this->period($loan, 1, '2026-08-01', ['principal_paid' => 500, 'interest_paid' => 100, 'status' => 'partial']);
    $this->period($loan, 2, '2026-09-01');

    $result = $this->scoreOf($member);

    expect($result['data_used']['lendy_monthly_payments'])->toBe('1600.00')
        ->and($result['capacity']['existing_debt_payments'])->toBe('1600.00')
        ->and($result['capacity']['debt_service_ratio'])->toBe('4.00');
});

it('counts a period due on the restructure date only when it was paid', function () {
    $paid = $this->member();
    $paidSource = $this->releasedLoan($paid, ['status' => 'restructured', 'restructured_at' => '2026-09-01 10:00:00']);
    $this->paidPeriod($paidSource, 1, '2026-09-01', '2026-09-01');

    $unpaid = $this->member();
    $unpaidSource = $this->releasedLoan($unpaid, ['status' => 'restructured', 'restructured_at' => '2026-09-01 10:00:00']);
    $this->period($unpaidSource, 1, '2026-09-01');

    $paidResult = $this->scoreOf($paid);
    $unpaidResult = $this->scoreOf($unpaid);

    expect($paidResult['data_used']['matured_periods'])->toBe(1)
        ->and($paidResult['data_used']['on_time_periods'])->toBe(1)
        ->and($unpaidResult['data_used']['matured_periods'])->toBe(0)
        ->and($unpaidResult['data_used']['days_past_due'])->toBe(0);
});

it('counts only the unpaid principal and interest of this month\'s and later periods as monthly debt', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member, ['maturity_date' => '2026-12-20']);
    $this->period($loan, 1, '2026-11-20', [
        'principal_paid' => 400,
        'penalty_amount' => 50,
        'total_due' => 1150,
        'status' => 'partial',
    ]);
    $this->period($loan, 2, '2026-12-20');

    $result = $this->scoreOf($member);

    // November owes ₱1,100.00 less the ₱400.00 paid; December's whole ₱1,100.00 is the higher month.
    expect($result['data_used']['lendy_monthly_payments'])->toBe('1100.00');

    $this->period($loan, 3, '2026-12-25', ['principal_paid' => 1000, 'interest_paid' => 100, 'status' => 'paid']);
    DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 2)
        ->update(['principal_paid' => 900, 'penalty_amount' => 300, 'total_due' => 1400]);

    // November ₱700.00 against December ₱200.00 unpaid: penalty never counts.
    expect($this->scoreOf($member)['data_used']['lendy_monthly_payments'])->toBe('700.00');
});
