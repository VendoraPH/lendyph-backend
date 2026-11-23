<?php

use App\Enums\TermUnit;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Models\OriginationRuleRevision;
use App\Models\User;
use App\Services\CreditScoring\Capacity;
use App\Services\CreditScoring\Centavos;
use App\Services\CreditScoring\Exposure;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\LoanListTotals;
use App\Services\LoanOrigination\Assessment\CapacityAssessor;
use App\Services\LoanService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Tests\Feature\Phase6\LoanOrigination\OriginationFixtures;
use Tests\TestCase;

/*
 * LO-B4: the capacity assessment (origination decisions 14 and 15). It is
 * worked out on the server from the loan form's preview schedule and Credit
 * Scoring's Capacity and Exposure, so the figures equal theirs.
 */
uses(TestCase::class, OriginationFixtures::class);

beforeEach(fn () => $this->pinOriginationToday());

function lo4Assessor(): CapacityAssessor
{
    return app(CapacityAssessor::class);
}

/** @return array{max_dti: string, min_coverage: string, min_remaining_income: string} */
function lo4Limits(string $dti = '40', string $coverage = '1.25', string $remaining = '0.00'): array
{
    return ['max_dti' => $dti, 'min_coverage' => $coverage, 'min_remaining_income' => $remaining];
}

it('takes the proposed payment from preview rows by Credit Scoring\'s own rule, equal to the loan-based entry', function (string $method, int $term) {
    $product = $this->originationProduct(['interest_method' => $method, 'term' => $term]);
    $loan = Loan::factory()->create([
        'borrower_id' => Borrower::factory()->create()->id,
        'loan_product_id' => $product->id,
        'status' => 'draft',
        'principal_amount' => 30000,
        'interest_rate' => 2,
        'interest_method' => $method,
        'term' => $term,
        'frequency' => $method === 'upon_maturity' ? 'upon_maturity' : 'monthly',
        'start_date' => '2026-11-19',
        'maturity_date' => Carbon::parse('2026-11-19')->addMonths($term),
        'created_by' => User::query()->value('id'),
    ]);
    $loans = app(LoanService::class);

    $fromRows = Capacity::proposedPaymentFromRows($loans->buildAmortizationPreview($loan), $term, TermUnit::Months);

    expect($fromRows)->not->toBeNull()
        ->and($fromRows)->toBe(Capacity::proposedPayment($loan, $loans));
})->with([
    'straight, 6 months' => ['straight', 6],
    'upon maturity, 3 months' => ['upon_maturity', 3],
]);

it('answers no proposed payment for no rows', function () {
    expect(Capacity::proposedPaymentFromRows([], 6, TermUnit::Months))->toBeNull();
});

it('judges DTI exactly, (debt + instalment) × 100 against the maximum × income, and shows it rounded up so a failing DTI never reads as within the limit', function () {
    $at = CapacityAssessor::judge(1_000_000, 0, 0, 400_000, lo4Limits());
    $justOver = CapacityAssessor::judge(1_000_000, 0, 100_000, 300_040, lo4Limits());
    $over = CapacityAssessor::judge(1_000_000, 0, 0, 400_100, lo4Limits());
    $within = CapacityAssessor::judge(1_000_000, 0, 23_401, 100_000, lo4Limits());

    expect($at['dti'])->toBe('40.00')
        ->and($at['checks']['dti'])->toBeTrue()
        ->and($justOver['dti'])->toBe('40.01')
        ->and($justOver['checks']['dti'])->toBeFalse()
        ->and($over['dti'])->toBe('40.01')
        ->and($over['checks']['dti'])->toBeFalse()
        ->and($within['dti'])->toBe('12.35')
        ->and($within['checks']['dti'])->toBeTrue()
        ->and(CapacityAssessor::judge(1_000_000, 0, 0, 125_000, lo4Limits('12.5'))['checks']['dti'])->toBeTrue()
        ->and(CapacityAssessor::judge(1_000_000, 0, 0, 125_001, lo4Limits('12.5'))['checks']['dti'])->toBeFalse();
});

it('explains a DTI of 40.004% as 40.01%, above the 40% maximum', function () {
    $application = $this->originationApplication('under_assessment', null, [], ['expenses' => ['housing' => '0', 'food' => '0']]);
    $instalment = Capacity::proposedPaymentFromRows(app(LoanService::class)->formPreview([
        'loan_product_id' => $application->loan_product_id, 'principal_amount' => 30000, 'interest_rate' => 2.0, 'term' => 6, 'frequency' => 'monthly', 'start_date' => '2026-11-19',
    ])['amortization']['rows'], 6, TermUnit::Months)['centavos'];
    // An income of 100 instalments and other debts of 39.004: (debts + instalment) ÷ income is 40.004%.
    $income = Centavos::toPesos(100 * $instalment);
    $debts = Centavos::toPesos(intdiv(39_004 * $instalment + 500, 1_000));

    $outcome = lo4Assessor()->assess($application, $income, $debts);

    expect($outcome->checks['dti'])->toBeFalse()
        ->and($outcome->figures['dti_ratio'])->toBe('40.01')
        ->and($outcome->result)->toBe('above_capacity')
        ->and($outcome->explanation)->toBe('Above capacity: the debt-to-income ratio of 40.01% is above the 40% maximum.');
});

it('judges coverage exactly against the minimum, and shows it rounded down so a shortfall never reads as the minimum', function () {
    $exact = CapacityAssessor::judge(1_000_000, 0, 0, 800_000, lo4Limits('100'));
    $justUnder = CapacityAssessor::judge(1_000_000, 0, 0, 800_001, lo4Limits('100'));
    $above = CapacityAssessor::judge(1_000_000, 0, 0, 300_000, lo4Limits('100'));

    expect($exact['coverage'])->toBe('1.25')
        ->and($exact['checks']['coverage'])->toBeTrue()
        ->and($justUnder['coverage'])->toBe('1.24')
        ->and($justUnder['checks']['coverage'])->toBeFalse()
        ->and($above['coverage'])->toBe('3.33');
});

it('assesses at the start date it is given, today by default', function () {
    $product = $this->originationProduct(['frequency' => 'weekly', 'term' => 3]);
    $application = $this->originationApplication('under_assessment', $product, [], ['loan' => ['frequency' => 'weekly', 'term' => '3']]);
    $loans = app(LoanService::class);
    $instalmentFrom = fn (string $date) => Capacity::proposedPaymentFromRows($loans->formPreview([
        'loan_product_id' => $product->id, 'principal_amount' => 30000, 'interest_rate' => 2.0, 'term' => 3, 'frequency' => 'weekly', 'start_date' => $date,
    ])['amortization']['rows'], 3, TermUnit::Months)['centavos'];

    $today = lo4Assessor()->assess($application, '40000', '1500');
    $monday = lo4Assessor()->assess($application, '40000', '1500', '2026-11-23');

    expect($today->request['start_date'])->toBe('2026-11-19')
        ->and($monday->request['start_date'])->toBe('2026-11-23')
        ->and($today->figures['proposed_monthly_installment'])->toBe(number_format($instalmentFrom('2026-11-19') / 100, 2, '.', ''))
        ->and($monday->figures['proposed_monthly_installment'])->toBe(number_format($instalmentFrom('2026-11-23') / 100, 2, '.', ''))
        ->and($monday->figures['proposed_monthly_installment'])->not->toBe($today->figures['proposed_monthly_installment']);
});

it('judges remaining income as disposable income less the instalment, against the minimum in pesos', function () {
    $zero = CapacityAssessor::judge(1_000_000, 600_000, 0, 400_000, lo4Limits('100', '0'));
    $short = CapacityAssessor::judge(1_000_000, 600_001, 0, 400_000, lo4Limits('100', '0'));
    $floor = CapacityAssessor::judge(1_000_000, 0, 0, 400_000, lo4Limits('100', '0', '6000.01'));

    expect($zero['remaining_centavos'])->toBe(0)
        ->and($zero['checks']['remaining_income'])->toBeTrue()
        ->and($short['remaining_centavos'])->toBe(-1)
        ->and($short['checks']['remaining_income'])->toBeFalse()
        ->and($floor['remaining_centavos'])->toBe(600_000)
        ->and($floor['checks']['remaining_income'])->toBeFalse();
});

it('assesses an application from the loan form\'s preview and Capacity, figure for figure', function () {
    $application = $this->originationApplication('under_assessment');
    $loans = app(LoanService::class);

    $outcome = lo4Assessor()->assess($application, '40000', '1500');

    $preview = $loans->formPreview([
        'loan_product_id' => $application->loan_product_id,
        'principal_amount' => 30000,
        'interest_rate' => (float) $application->loanProduct->interest_rate,
        'term' => 6,
        'frequency' => 'monthly',
        'start_date' => '2026-11-19',
    ]);
    $instalment = Capacity::proposedPaymentFromRows($preview['amortization']['rows'], 6, TermUnit::Months);
    $capacity = Capacity::calculate(4_000_000, 1_400_000, 150_000, $instalment['centavos'], CreditScoringSetting::current());

    expect($outcome->result)->toBe('within_capacity')
        ->and($outcome->provisional)->toBeFalse()
        ->and($outcome->figures['declared_monthly_income'])->toBe('40000.00')
        ->and($outcome->figures['eligible_monthly_income'])->toBe('40000.00')
        ->and($outcome->figures['monthly_expenses'])->toBe('14000.00')
        ->and($outcome->figures['monthly_debt_payments'])->toBe('1500.00')
        ->and($outcome->figures['proposed_monthly_installment'])->toBe(number_format($instalment['centavos'] / 100, 2, '.', ''))
        ->and($outcome->figures['disposable_income'])->toBe($capacity->toArray()['disposable_income'])
        ->and($outcome->figures['dti_ratio'])->toBe($this->ratioAsAssessed($capacity))
        ->and($outcome->figures['coverage_ratio'])->toBe((string) BigDecimal::of($capacity->disposableCentavos)->dividedBy($instalment['centavos'], 2, RoundingMode::Down))
        ->and($outcome->figures['remaining_income'])->toBe(number_format(($capacity->disposableCentavos - $instalment['centavos']) / 100, 2, '.', ''))
        ->and($outcome->request)->toMatchArray(['loan_product_id' => $application->loan_product_id, 'requested_amount' => '30000.00', 'requested_term' => 6, 'frequency' => 'monthly', 'start_date' => '2026-11-19']);
});

it('adds the linked member\'s Lendy loans by Exposure, as Credit Scoring counts them', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id')]);
    $old = Loan::factory()->create([
        'borrower_id' => $member->id, 'branch_id' => $member->branch_id, 'status' => 'ongoing', 'principal_amount' => 12000,
        'released_at' => now()->subMonths(2), 'created_by' => User::query()->value('id'),
    ]);
    AmortizationSchedule::factory()->create([
        'loan_id' => $old->id, 'period_number' => 3, 'due_date' => '2026-12-10',
        'principal_due' => 2000, 'interest_due' => 240, 'total_due' => 2240,
        'principal_paid' => 0, 'interest_paid' => 0, 'penalty_paid' => 0, 'status' => 'pending',
    ]);
    $application = $this->originationApplication('under_assessment', null, ['borrower_id' => $member->id]);

    $outcome = lo4Assessor()->assess($application, '40000', '1500');
    $exposure = Exposure::from(MemberCreditData::load($member->id, null, now()), app(LoanListTotals::class));

    expect($exposure->monthlyDebtCentavos)->toBeGreaterThan(0)
        ->and($outcome->figures['lendy_debt_payments'])->toBe(number_format($exposure->monthlyDebtCentavos / 100, 2, '.', ''))
        ->and($outcome->figures['monthly_debt_payments'])->toBe(number_format(($exposure->monthlyDebtCentavos + 150_000) / 100, 2, '.', ''))
        ->and($outcome->borrowerId)->toBe($member->id);
});

it('is provisional and needs further review while the income or debts aren\'t verified', function () {
    $outcome = lo4Assessor()->assess($this->originationApplication('under_assessment'));

    expect($outcome->result)->toBe('further_review')
        ->and($outcome->provisional)->toBeTrue()
        ->and($outcome->figures['verified_monthly_income'])->toBeNull()
        ->and($outcome->missingInformation)->toContain('The verified monthly income hasn\'t been entered yet.');
});

it('is above capacity when any limit fails, and says which in words', function () {
    $application = $this->originationApplication('under_assessment', null, [], ['employment' => ['gross_monthly_income' => '9000']]);

    $outcome = lo4Assessor()->assess($application, '9000', '1500');

    expect($outcome->result)->toBe('above_capacity')
        ->and($outcome->checks['dti'])->toBeFalse()
        ->and($outcome->explanation)->toContain('debt-to-income ratio')
        ->and($outcome->explanation)->toContain('above the 40% maximum')
        ->and($outcome->goesToPolicyException())->toBeTrue();
});

it('has insufficient information without income, or without a schedule to take the instalment from', function () {
    $noIncome = lo4Assessor()->assess($this->originationApplication('under_assessment', null, [], ['employment' => ['gross_monthly_income' => '']]));
    $zeroRate = lo4Assessor()->assess($this->originationApplication('under_assessment', $this->originationProduct(['interest_rate' => 0])), '40000', '0');

    expect($noIncome->result)->toBe('insufficient_information')
        ->and($noIncome->goesToPolicyException())->toBeTrue()
        ->and($zeroRate->result)->toBe('insufficient_information')
        ->and($zeroRate->figures['proposed_monthly_installment'])->toBeNull();
});

it('applies each income source\'s recognition percentage and a product\'s own limits from the current rule revision', function () {
    $product = $this->originationProduct();
    $rules = OriginationRuleRevision::current()->rules;
    $rules['income_recognition']['employed'] = '80';
    $rules['product_overrides'] = [['loan_product_id' => $product->id, 'max_dti' => '12.5']];
    OriginationRuleRevision::query()->create(['version' => OriginationRuleRevision::current()->version + 1, 'rules' => $rules]);

    $outcome = lo4Assessor()->assess($this->originationApplication('under_assessment', $product), '40000', '1500');

    expect($outcome->figures['eligible_monthly_income'])->toBe('32000.00')
        ->and($outcome->thresholds['max_dti'])->toBe('12.5')
        ->and($outcome->thresholds['min_coverage'])->toBe('1.25')
        ->and($outcome->ruleRevision->version)->toBe(OriginationRuleRevision::current()->version);
});

it('reads a missing required verification as insufficient information when the rules say so', function () {
    $rules = OriginationRuleRevision::current()->rules;
    $rules['require_verified_income'] = true;
    $rules['missing_information_rule'] = 'insufficient';
    OriginationRuleRevision::query()->create(['version' => OriginationRuleRevision::current()->version + 1, 'rules' => $rules]);

    expect(lo4Assessor()->assess($this->originationApplication('under_assessment'))->result)->toBe('insufficient_information');
});

it('needs further review on a short employment history below the rules\' minimum', function () {
    $rules = OriginationRuleRevision::current()->rules;
    $rules['min_employment_years'] = 6;
    OriginationRuleRevision::query()->create(['version' => OriginationRuleRevision::current()->version + 1, 'rules' => $rules]);

    $outcome = lo4Assessor()->assess($this->originationApplication('under_assessment'), '40000', '1500');

    expect($outcome->result)->toBe('further_review')
        ->and($outcome->conflicts)->toContain('Employed or in business for 5 years, below the 6-year minimum.');
});

it('lists differences from a linked member\'s record as conflicts, without their values', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'birthdate' => '1985-01-01', 'branch_id' => Branch::query()->value('id')]);
    $application = $this->originationApplication('under_assessment', null, ['borrower_id' => $member->id]);

    $outcome = lo4Assessor()->assess($application, '40000', '1500');

    expect($outcome->result)->toBe('further_review')
        ->and($outcome->conflicts)->toContain('The linked member\'s date of birth differs from the application\'s.')
        ->and(implode(' ', $outcome->conflicts))->not->toContain('1985');
});
