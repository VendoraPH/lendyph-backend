<?php

use App\Models\Borrower;
use App\Models\CreditApplicationProfile;
use App\Models\CreditScore;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\CreditScoring\Capacity;
use App\Services\CreditScoring\Centavos;
use App\Services\CreditScoring\Recalculator;
use App\Services\LoanService;
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

it('bands the ratio on the same two decimals it shows', function () {
    $capacity = Capacity::calculate(1_000_000, null, 300_049, null, CreditScoringSetting::current());

    expect($capacity->debtServiceRatioText())->toBe('30.00')
        ->and($capacity->assessment)->toBe(Capacity::MANAGEABLE)
        ->and($capacity->debtServiceRatio)->toBe(30.0);
});

function singlePaymentApplication(Borrower $member, int $term, string $termUnit): Loan
{
    $product = LoanProduct::factory()->create(['interest_rate' => 1, 'term' => $term, 'frequency' => 'upon_maturity']);

    return Loan::factory()->create([
        'borrower_id' => $member->id,
        'branch_id' => $member->branch_id,
        'loan_product_id' => $product->id,
        'status' => 'draft',
        'principal_amount' => 30000,
        'interest_rate' => 1,
        'interest_method' => 'straight',
        'term' => $term,
        'term_unit' => $termUnit,
        'frequency' => 'upon_maturity',
        'start_date' => '2026-11-10',
        'maturity_date' => $termUnit === 'months' ? Carbon\Carbon::parse('2026-11-10')->addMonthsNoOverflow($term)->toDateString() : Carbon\Carbon::parse('2026-11-10')->addDays($term)->toDateString(),
        'created_by' => User::query()->value('id'),
    ])->fresh();
}

/**
 * The previewed schedule's total in integer centavos.
 */
function previewTotalCentavos(Loan $loan): int
{
    return collect(app(LoanService::class)->buildAmortizationPreview($loan))
        ->sum(fn (array $row) => Centavos::of($row['total_due']));
}

/**
 * Centavos divided by a whole number, rounded half up, as two-decimal pesos.
 */
function centavosOver(int $centavos, int $divisor): string
{
    return Centavos::toPesos(intdiv(2 * $centavos + $divisor, 2 * $divisor));
}

it('spreads a single payment over its term in months for capacity, and says so', function () {
    $loan = singlePaymentApplication($this->member(['monthly_income' => 40000]), 3, 'months');

    $result = $this->scoreOf($loan->borrower, $loan);

    expect($result['capacity']['proposed_payment'])->toBe(centavosOver(previewTotalCentavos($loan), 3))
        ->and($result['capacity']['proposed_payment_basis'])->toContain('over 3 months')
        ->and($this->factorOf($result, 'debt_service_ratio')['detail'])->toContain('monthly equivalent');
});

it('keeps a one-month single payment whole, and a days term spread over its 30-day months', function () {
    $oneMonth = singlePaymentApplication($this->member(['monthly_income' => 40000]), 1, 'months');
    $days = singlePaymentApplication($this->member(['monthly_income' => 40000]), 90, 'days');
    $short = singlePaymentApplication($this->member(['monthly_income' => 40000]), 15, 'days');

    expect($this->scoreOf($oneMonth->borrower, $oneMonth)['capacity']['proposed_payment'])->toBe(Centavos::toPesos(previewTotalCentavos($oneMonth)))
        ->and($this->scoreOf($days->borrower, $days)['capacity']['proposed_payment'])->toBe(centavosOver(previewTotalCentavos($days), 3))
        ->and($this->scoreOf($short->borrower, $short)['capacity']['proposed_payment'])->toBe(Centavos::toPesos(previewTotalCentavos($short)));
});
