<?php

use App\Models\LoanProduct;
use App\Services\LoanOrigination\DraftRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
 * LO-B1: the draft rules (origination decisions 5 and 18, audit §3.3 and
 * §3.5). The server keeps only whitelisted fields, works out the completion
 * the way the wizard does, requires gender and civil status at submit, and
 * adds the declared amounts up itself (`declared_totals`), half up to the
 * centavo, so the browser never sums money.
 */
uses(TestCase::class);

function loRules(): DraftRules
{
    return app(DraftRules::class);
}

/** A complete draft for an employed applicant. */
function loCompleteDraft(int $productId): array
{
    return [
        'personal' => [
            'first_name' => 'Juana', 'middle_name' => '', 'last_name' => 'Dela Cruz', 'suffix' => '',
            'date_of_birth' => '1990-05-04', 'gender' => 'female', 'civil_status' => 'single', 'nationality' => 'Filipino',
            'region' => 'NCR', 'province' => 'Metro Manila', 'city' => 'Quezon City', 'barangay' => 'Bagumbayan',
            'street_address' => '12 Mabini St', 'postal_code' => '1110', 'years_at_residence' => '3',
            'residence_ownership' => 'rented', 'dependents' => '2', 'membership_number' => '', 'branch_id' => null,
        ],
        'employment' => [
            'income_source' => 'employed', 'employer_name' => 'Acme', 'employment_type' => 'regular', 'job_title' => 'Clerk',
            'years_employed' => '4', 'employer_address' => '', 'gross_monthly_income' => '25,000', 'net_monthly_income' => '21000',
            'other_regular_income' => '1500.50', 'salary_frequency' => 'semi_monthly',
        ],
        'expenses' => [
            'housing' => '5000', 'utilities' => '1500', 'food' => '6000', 'transportation' => '1000', 'education' => '',
            'family_support' => '', 'other_essential' => '', 'credit_card_payments' => '1000', 'other_debt_payments' => '',
            'other_commitments' => '', 'has_existing_loans' => true,
            'existing_loans' => [
                ['institution' => 'Bank A', 'outstanding_balance' => '30000', 'payment_amount' => '500.33', 'payment_frequency' => 'weekly', 'remaining_term' => '12'],
            ],
        ],
        'loan' => ['loan_product_id' => $productId, 'amount' => '20000', 'purpose' => 'Tuition', 'term' => '6', 'frequency' => 'monthly', 'release_method' => 'cash'],
    ];
}

function loProduct(): LoanProduct
{
    return LoanProduct::factory()->create([
        'min_amount' => 5000, 'max_amount' => 50000, 'min_term' => 3, 'max_term' => 12, 'term_unit' => 'months',
        'frequencies' => ['monthly', 'semi_monthly'],
    ]);
}

it('keeps only whitelisted fields and fills the rest blank', function () {
    $draft = loRules()->normalize([
        'personal' => ['first_name' => 'Juana', 'is_admin' => true, 'branch_id' => 3],
        'injected' => ['x' => 1],
        'expenses' => ['existing_loans' => [['institution' => 'Bank A', 'evil' => 'x']]],
    ]);

    expect(array_keys($draft))->toBe(['personal', 'employment', 'expenses', 'loan'])
        ->and($draft['personal']['first_name'])->toBe('Juana')
        ->and($draft['personal'])->not->toHaveKey('is_admin')
        ->and($draft['personal']['branch_id'])->toBe(3)
        ->and($draft['personal']['last_name'])->toBe('')
        ->and($draft['expenses']['has_existing_loans'])->toBeNull()
        ->and($draft['expenses']['existing_loans'][0])->not->toHaveKey('evil')
        ->and($draft['expenses']['existing_loans'][0]['payment_amount'])->toBe('')
        ->and($draft['loan']['loan_product_id'])->toBeNull();
});

it('refuses a field of the wrong shape or length with 422 on its own key', function (array $input, string $key) {
    try {
        loRules()->normalize($input);
        $this->fail('The draft was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
})->with([
    'array for text' => [['personal' => ['first_name' => ['x']]], 'draft.personal.first_name'],
    'too long' => [['personal' => ['street_address' => str_repeat('a', 256)]], 'draft.personal.street_address'],
    'unknown income source' => [['employment' => ['income_source' => 'lottery']], 'draft.employment.income_source'],
    'unknown frequency' => [['employment' => ['salary_frequency' => 'hourly']], 'draft.employment.salary_frequency'],
    'too many loans' => [['expenses' => ['existing_loans' => array_fill(0, 11, [])]], 'draft.expenses.existing_loans'],
    'product as text' => [['loan' => ['loan_product_id' => 'abc']], 'draft.loan.loan_product_id'],
    'section as text' => [['personal' => 'x'], 'draft.personal'],
]);

it('works out completion as the wizard does: seven steps before Review', function () {
    $product = loProduct();
    $rules = loRules();
    $draft = $rules->normalize(loCompleteDraft($product->id));

    $all = $rules->completedSteps($draft, $product, contactVerified: true, documentsComplete: true, today: '2026-11-19');

    expect($all)->toBe(['product', 'contact', 'personal', 'employment', 'expenses', 'loan', 'documents'])
        ->and($rules->completionPercent($all))->toBe(100);

    $draft['employment']['income_source'] = '';
    $some = $rules->completedSteps($draft, $product, contactVerified: true, documentsComplete: false, today: '2026-11-19');

    expect($some)->toBe(['product', 'contact', 'personal', 'expenses', 'loan'])
        ->and($rules->completionPercent($some))->toBe(71)
        ->and($rules->lastCompletedStep($some))->toBe('loan');
});

it('requires gender and civil status at submit, as a member record does, and the product\'s own fields', function () {
    $product = loProduct();
    $draft = loRules()->normalize(loCompleteDraft($product->id));
    $draft['personal']['gender'] = '';
    $draft['personal']['civil_status'] = '';
    $draft['personal']['postal_code'] = '';

    $missing = loRules()->missingFields($draft, $product, today: '2026-11-19', requiredFields: ['personal.postal_code']);

    expect($missing)->toBe([
        'personal.gender' => 'Choose your gender.',
        'personal.civil_status' => 'Choose your civil status.',
        'personal.postal_code' => 'Enter your postal code.',
    ]);
});

it('checks the loan request against the product, in pesos with two decimals', function () {
    $product = loProduct();
    $draft = loRules()->normalize(loCompleteDraft($product->id));
    $draft['loan']['amount'] = '60000';
    $draft['loan']['term'] = '24';
    $draft['loan']['frequency'] = '';

    expect(loRules()->missingFields($draft, $product, today: '2026-11-19'))->toBe([
        'loan.amount' => 'Enter an amount from ₱5,000.00 to ₱50,000.00.',
        'loan.term' => 'Choose a term from 3 to 12 months.',
        'loan.frequency' => 'Choose how often you will pay.',
    ]);
});

it('refuses an applicant under 18', function () {
    $product = loProduct();
    $draft = loRules()->normalize(loCompleteDraft($product->id));
    $draft['personal']['date_of_birth'] = '2008-11-20';

    expect(loRules()->missingFields($draft, $product, today: '2026-11-19'))
        ->toBe(['personal.date_of_birth' => 'You must be at least 18 years old to apply.']);
});

it('adds up the declared amounts on the server, each line monthly and half up to the centavo', function () {
    $product = loProduct();
    $totals = loRules()->declaredTotals(loRules()->normalize(loCompleteDraft($product->id)));

    // Weekly 500.33 a month = 500.33 × 52 ÷ 12 = 2,168.0966… → 2,168.10.
    expect($totals['monthly_income'])->toBe('26500.50')
        ->and($totals['monthly_expenses'])->toBe('13500.00')
        ->and($totals['monthly_debt_payments'])->toBe('3168.10')
        ->and($totals['monthly_remaining'])->toBe('9832.40')
        ->and($totals['income'])->toBe([
            ['key' => 'gross_monthly_income', 'label' => 'Gross monthly pay', 'monthly' => '25000.00'],
            ['key' => 'other_regular_income', 'label' => 'Other regular income a month', 'monthly' => '1500.50'],
        ])
        ->and($totals['debts'][1])->toBe(['key' => 'existing_loans.0', 'label' => 'Bank A', 'monthly' => '2168.10']);
});

it('counts business income as net, or sales less costs when no net is given, and other sources by their frequency', function () {
    $rules = loRules();

    $business = $rules->normalize(['employment' => ['income_source' => 'business_owner', 'monthly_revenue' => '40000', 'monthly_business_expenses' => '28000.40']]);
    $farmer = $rules->normalize(['employment' => ['income_source' => 'farmer', 'other_income_amount' => '90000', 'other_income_frequency' => 'semi_annual']]);

    expect($rules->declaredTotals($business)['monthly_income'])->toBe('11999.60')
        ->and($rules->declaredTotals($farmer)['monthly_income'])->toBe('15000.00');
});

it('leaves income blank when nothing usable was declared, and ignores existing loans the applicant said they don\'t have', function () {
    $rules = loRules();
    $draft = $rules->normalize(['expenses' => ['has_existing_loans' => false, 'existing_loans' => [['institution' => 'X', 'payment_amount' => '900', 'payment_frequency' => 'monthly']]]]);

    $totals = $rules->declaredTotals($draft);

    expect($totals['monthly_income'])->toBeNull()
        ->and($totals['monthly_remaining'])->toBeNull()
        ->and($totals['monthly_debt_payments'])->toBe('0.00')
        ->and($totals['debts'])->toBe([]);
});
