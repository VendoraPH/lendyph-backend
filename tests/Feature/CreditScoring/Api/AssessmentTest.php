<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditDecision;
use App\Models\CreditScore;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CreditScoring\Api\AssessmentService;
use App\Services\CreditScoring\Centavos;
use App\Services\CreditScoring\PolicyFlags;
use App\Services\CreditScoring\Recalculator;
use App\Services\CreditScoring\Scorer;
use App\Services\LoanService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * GET and PUT /credit-scoring/loans/{loan}/assessment: a loan application's
 * credit assessment. Advisory: saving one writes the application details and
 * a score, never the loan.
 */
uses(TestCase::class, CreditApiFixtures::class);

const ASSESSMENT_KEYS = [
    'loan_id', 'loan_status', 'editable', 'borrower_id', 'borrower_name', 'borrower_code', 'notice_version', 'privacy_notice',
    'application_profile', 'application_figures_hidden', 'score', 'capacity', 'policy_flags', 'decisions', 'decisions_total', 'employment_statuses',
];

const APPLICATION_PROFILE_KEYS = [
    'other_income', 'business_income', 'household_expenses', 'outside_debt_payments', 'outside_lenders_count', 'employment_status',
    'business_start_date', 'years_at_residence', 'verified_references_count', 'income_verification', 'notice_version',
    'notice_acknowledged_at', 'notice_acknowledged',
];

beforeEach(function () {
    $this->pinToday();
    $this->officer = $this->branchAnalyst();
});

it('answers an unassessed application with nulls and empty lists', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));

    $data = $this->actingAs($this->officer)->getJson("/api/credit-scoring/loans/{$loan->id}/assessment")->assertOk()->json('data');

    $keys = array_keys($data);
    sort($keys);
    $expected = ASSESSMENT_KEYS;
    sort($expected);
    expect($keys)->toBe($expected)
        ->and($data['application_profile'])->toBeNull()
        ->and($data['score'])->toBeNull()
        ->and($data['capacity'])->toBeNull()
        ->and($data['policy_flags'])->toBe([])
        ->and($data['decisions'])->toBe([])
        ->and($data['editable'])->toBeTrue()
        ->and($data['notice_version'])->toBe('1.0');
});

it('works the task\'s capacity example through the endpoint', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));

    $data = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", [
        'other_income' => 5000,
        'household_expenses' => 20000,
        'outside_debt_payments' => 5000,
    ])->assertOk()->json('data');

    expect($data['capacity'])->toMatchArray([
        'monthly_income' => '40000.00',
        'existing_debt_payments' => '5000.00',
        'proposed_payment' => '5000.00',
        'disposable_income' => '15000.00',
        'suggested_maximum_payment' => '6000.00',
        'debt_service_ratio' => '25.00',
        'assessment' => 'manageable',
    ])->and($data['score']['capacity'])->toBe($data['capacity'])
        ->and($data['score']['context'])->toBe('assessment')
        ->and($data['score']['loan_id'])->toBe($loan->id)
        ->and($data['application_profile']['household_expenses'])->toBe('20000.00')
        ->and($data['application_profile']['business_income'])->toBeNull();

    $keys = array_keys($data['application_profile']);
    sort($keys);
    $expected = APPLICATION_PROFILE_KEYS;
    sort($expected);
    expect($keys)->toBe($expected);
});

it('takes an upon-maturity loan\'s monthly equivalent as its proposed payment', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $product = LoanProduct::factory()->create(['interest_rate' => 1, 'term' => 3, 'frequency' => 'upon_maturity']);
    $loan = $this->application($member, [
        'loan_product_id' => $product->id, 'interest_rate' => 1, 'term' => 3, 'term_unit' => 'months',
        'frequency' => 'upon_maturity', 'maturity_date' => '2027-02-10',
    ]);
    $total = collect(app(LoanService::class)->buildAmortizationPreview($loan))->sum(fn (array $row) => Centavos::of($row['total_due']));

    $capacity = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 10000])
        ->assertOk()->json('data.capacity');

    expect($capacity['proposed_payment'])->toBe(Centavos::toPesos(intdiv(2 * $total + 3, 6)))
        ->and($capacity['proposed_payment_basis'])->toContain('over 3 months');
});

it('shows the completed-loan factors as without data for a member on a first loan in progress', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $first = $this->releasedLoan($member);
    $this->paidMonthly($first, '2026-07-01', 4);
    $loan = $this->application($member);

    $score = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", [])->assertOk()->json('data.score');

    expect(collect($score['factors_without_data'])->pluck('label')->all())->toContain('Completed loans', 'Completed loans never more than 7 days late')
        ->and(collect($score['factors'])->pluck('label')->all())->not->toContain('Completed loans');
});

it('refuses to save an assessment once the loan is past approval', function (string $status) {
    $loan = $this->application($this->member(['monthly_income' => 35000]), ['status' => $status]);

    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])
        ->assertUnprocessable();

    expect(CreditApplicationProfile::query()->count())->toBe(0)
        ->and(CreditScore::query()->count())->toBe(0);
})->with(['approved', 'released', 'ongoing', 'completed']);

it('records the notice acknowledgement with the current version and time, clearing its flag', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));

    $first = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])->assertOk()->json('data');
    expect(collect($first['policy_flags'])->pluck('label')->all())->toContain('Credit assessment notice not recorded');

    $this->travel(1)->hours();
    $data = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['notice_acknowledged' => true])->assertOk()->json('data');

    $profile = CreditApplicationProfile::query()->sole();
    expect($profile->notice_version)->toBe('1.0')
        ->and($profile->notice_acknowledged_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($profile->household_expenses)->toBe('1000.00')
        ->and($profile->created_by)->toBe($this->officer->id)
        ->and($data['application_profile']['notice_acknowledged'])->toBeTrue()
        ->and(collect($data['policy_flags'])->pluck('label')->all())->not->toContain('Credit assessment notice not recorded');
});

it('validates the application details', function (array $body, string $field) {
    $loan = $this->application($this->member(['monthly_income' => 35000]));

    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", $body)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'negative income' => [['other_income' => -1], 'other_income'],
    'three decimals' => [['household_expenses' => 10.005], 'household_expenses'],
    'above ₱9,999,999,999.99' => [['business_income' => 10000000000], 'business_income'],
    'unknown verification' => [['income_verification' => 'maybe'], 'income_verification'],
    'future business' => [['business_start_date' => '2030-01-01'], 'business_start_date'],
    'fractional references' => [['verified_references_count' => 1.5], 'verified_references_count'],
]);

/**
 * The member's lock as a stand-in: it records how long the save offered to
 * wait and times out without running the save. A real wait can't be timed
 * here, because Lock::block() measures it with Carbon::now(), which the
 * pinned test clock never moves.
 */
function timingOutRecalculator(stdClass $seen): Recalculator
{
    return new class(app(Scorer::class), app(PolicyFlags::class), $seen) extends Recalculator
    {
        public function __construct(Scorer $scorer, PolicyFlags $flags, private readonly stdClass $seen)
        {
            parent::__construct($scorer, $flags);
        }

        public function assessLoanAfter(Loan $loan, string $reason, callable $write, int $waitSeconds): array
        {
            $this->seen->waitSeconds = $waitSeconds;

            throw new LockTimeoutException;
        }
    };
}

it('answers 409 when the member is still being scored after 3 seconds, saving nothing', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));
    $seen = new stdClass;
    app()->instance(Recalculator::class, timingOutRecalculator($seen));

    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])->assertConflict();

    expect($seen->waitSeconds)->toBe(AssessmentService::LOCK_WAIT_SECONDS)
        ->and(AssessmentService::LOCK_WAIT_SECONDS)->toBe(3)
        ->and(CreditApplicationProfile::query()->count())->toBe(0);
});

it('refuses the save while another holder has the member\'s lock, running nothing', function () {
    $member = $this->member(['monthly_income' => 35000]);
    $loan = $this->application($member);
    $held = Cache::lock("credit-scoring:member:{$member->id}", 60);
    expect($held->get())->toBeTrue();

    try {
        expect(fn () => app(Recalculator::class)->assessLoanAfter($loan, 'Assessment saved', fn () => throw new LogicException('The save ran without the lock.'), 0))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $held->release();
    }

    expect(CreditScore::query()->count())->toBe(0);
});

it('rolls the saved details back when the assessment fails', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));
    app()->instance(Recalculator::class, new class(app(Scorer::class), app(PolicyFlags::class)) extends Recalculator
    {
        protected function assessUnlocked(Loan $loan, string $reason, bool $dryRun, ?Carbon\Carbon $asOf): array
        {
            throw new RuntimeException('The assessment failed.');
        }
    });

    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000, 'notice_acknowledged' => true])
        ->assertServerError();

    $lock = Cache::lock("credit-scoring:member:{$loan->borrower_id}", 60);
    expect(CreditApplicationProfile::query()->count())->toBe(0)
        ->and(CreditScore::query()->count())->toBe(0)
        ->and($lock->get())->toBeTrue();
    $lock->release();
});

it('gives up after 3 real seconds on a member being scored, answers 409, and leaves the lock free', function () {
    $member = $this->member(['monthly_income' => 35000]);
    $loan = $this->application($member);
    // Lock::block() times its wait with Carbon::now(); let the clock run.
    $this->travelBack();
    $held = Cache::lock("credit-scoring:member:{$member->id}", 60);
    expect($held->get())->toBeTrue();
    $started = microtime(true);

    try {
        $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])->assertConflict();
    } finally {
        $held->release();
    }

    $after = Cache::lock("credit-scoring:member:{$member->id}", 60);
    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(2.5)->toBeLessThan(10.0)
        ->and(CreditApplicationProfile::query()->count())->toBe(0)
        ->and($after->get())->toBeTrue();
    $after->release();
});

it('refuses the save when an approval lands before the status is read again under the lock', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));
    app()->instance(Recalculator::class, new class(app(Scorer::class), app(PolicyFlags::class)) extends Recalculator
    {
        public function assessLoanAfter(Loan $loan, string $reason, callable $write, int $waitSeconds): array
        {
            DB::table('loans')->where('id', $loan->id)->update(['status' => 'approved']);

            return parent::assessLoanAfter($loan, $reason, $write, $waitSeconds);
        }
    });

    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])
        ->assertUnprocessable()->assertJsonValidationErrors('loan');

    expect(CreditApplicationProfile::query()->count())->toBe(0)
        ->and(CreditScore::query()->count())->toBe(0);
});

it('lists the employment statuses with their labels, and refuses any other', function () {
    $loan = $this->application($this->member(['monthly_income' => 35000]));

    $data = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['employment_status' => 'self_employed'])->assertOk()->json('data');

    expect($data['application_profile']['employment_status'])->toBe('self_employed')
        ->and(collect($data['employment_statuses'])->pluck('label', 'value')->all())->toBe(AssessmentService::EMPLOYMENT_STATUSES);
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['employment_status' => 'Freelancer'])
        ->assertUnprocessable()->assertJsonValidationErrors('employment_status');
});

it('lists the latest 50 decisions, with the full count', function () {
    $member = $this->member(['monthly_income' => 35000]);
    $loan = $this->application($member);
    $scoreId = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", [])->json('data.score.id');

    foreach (range(1, 52) as $i) {
        CreditDecision::query()->create([
            'borrower_id' => $member->id, 'loan_id' => $loan->id, 'credit_score_id' => $scoreId, 'decision' => 'hold', 'reason' => "Decision {$i}",
            'score_snapshot' => [], 'recommendation_snapshot' => 'Advice', 'revision_version' => '1.0', 'decided_by' => $this->officer->id, 'decided_at' => now()->addMinutes($i),
        ]);
    }

    $data = $this->actingAs($this->officer)->getJson("/api/credit-scoring/loans/{$loan->id}/assessment")->json('data');

    expect($data['decisions'])->toHaveCount(50)
        ->and($data['decisions_total'])->toBe(52)
        ->and($data['decisions'][0]['reason'])->toBe('Decision 52');
});

it('lists the loan\'s decisions and its own flags', function () {
    $member = $this->member(['monthly_income' => 35000]);
    $loan = $this->application($member);
    $data = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])->json('data');

    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $member->id, 'credit_score_id' => $data['score']['id'], 'loan_id' => $loan->id, 'decision' => 'approve', 'reason' => 'Fine',
    ])->assertCreated();

    $read = $this->actingAs($this->officer)->getJson("/api/credit-scoring/loans/{$loan->id}/assessment")->assertOk()->json('data');

    expect($read['decisions'])->toHaveCount(1)
        ->and($read['decisions'][0]['id'])->toBe(CreditDecision::query()->sole()->id)
        ->and(collect($read['policy_flags'])->pluck('loan_id')->filter()->unique()->values()->all())->toBe([$loan->id]);
});
