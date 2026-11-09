<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditScore;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\CreditScoring\Capacity;
use App\Services\CreditScoring\Recalculator;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

it('works the task\'s capacity example to the centavo', function () {
    $capacity = Capacity::calculate(4_000_000, 2_000_000, 500_000, 500_000, CreditScoringSetting::current());

    expect($capacity->debtServiceRatioText())->toBe('25.00')
        ->and($capacity->disposableCentavos)->toBe(1_500_000)
        ->and($capacity->suggestedMaximumCentavos)->toBe(600_000)
        ->and($capacity->assessment)->toBe(Capacity::MANAGEABLE)
        ->and($capacity->paymentCover())->toBe(3.0)
        ->and($capacity->proposedFits())->toBeTrue()
        ->and($capacity->toArray())->toMatchArray([
            'monthly_income' => '40000.00',
            'disposable_income' => '15000.00',
            'suggested_maximum_payment' => '6000.00',
            'debt_service_ratio' => '25.00',
        ]);
});

it('reads the ratio levels from the settings', function (int $debt, string $assessment) {
    expect(Capacity::calculate(1_000_000, null, $debt, null, CreditScoringSetting::current())->assessment)->toBe($assessment);
})->with([
    '30% manageable' => [300_000, Capacity::MANAGEABLE],
    '30.01% caution' => [300_100, Capacity::CAUTION],
    '40% caution' => [400_000, Capacity::CAUTION],
    '40.01% above capacity' => [400_100, Capacity::ABOVE_CAPACITY],
]);

it('caps the suggested maximum at the income share less existing debt, and never below zero', function () {
    $settings = CreditScoringSetting::current();

    expect(Capacity::calculate(4_000_000, 0, 1_500_000, null, $settings)->suggestedMaximumCentavos)->toBe(100_000)
        ->and(Capacity::calculate(4_000_000, 3_000_000, 2_000_000, null, $settings)->suggestedMaximumCentavos)->toBe(0)
        ->and(Capacity::calculate(4_000_000, null, 0, null, $settings)->suggestedMaximumCentavos)->toBeNull();
});

it('rounds shares half up to the centavo', function () {
    expect(Capacity::calculate(333_333, 0, 0, null, CreditScoringSetting::current())->suggestedMaximumCentavos)->toBe(133_333)
        ->and(Capacity::calculate(100_001, 0, 0, null, CreditScoringSetting::current())->suggestedMaximumCentavos)->toBe(40_000)
        ->and(Capacity::calculate(100_004, 0, 0, null, CreditScoringSetting::current())->suggestedMaximumCentavos)->toBe(40_002);
});

it('assesses a loan application from its previewed schedule and declared details', function () {
    $member = $this->member(['monthly_income' => 35000]);
    $product = LoanProduct::factory()->create(['interest_rate' => 0, 'term' => 6, 'frequency' => 'monthly']);
    $loan = Loan::factory()->create([
        'borrower_id' => $member->id,
        'branch_id' => $member->branch_id,
        'loan_product_id' => $product->id,
        'status' => 'draft',
        'principal_amount' => 30000,
        'interest_rate' => 0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
        'start_date' => '2026-11-10',
        'maturity_date' => '2027-05-10',
        'created_by' => User::query()->value('id'),
    ]);
    CreditApplicationProfile::query()->create([
        'loan_id' => $loan->id,
        'other_income' => 5000,
        'household_expenses' => 20000,
        'outside_debt_payments' => 5000,
    ]);

    $outcome = app(Recalculator::class)->assessLoan($loan->fresh(), 'Assessment requested');
    $score = CreditScore::query()->findOrFail($outcome['credit_score_id']);

    expect($score->context)->toBe('assessment')
        ->and($score->loan_id)->toBe($loan->id)
        ->and($score->capacity)->toMatchArray([
            'monthly_income' => '40000.00',
            'household_expenses' => '20000.00',
            'existing_debt_payments' => '5000.00',
            'proposed_payment' => '5000.00',
            'disposable_income' => '15000.00',
            'suggested_maximum_payment' => '6000.00',
            'debt_service_ratio' => '25.00',
            'assessment' => 'manageable',
            'proposed_fits' => true,
        ]);

    $factors = $score->factors->keyBy('factor_key');
    expect($factors['debt_service_ratio']->points_earned)->toBe(12)
        ->and($factors['payment_cover']->points_earned)->toBe(10);
});
