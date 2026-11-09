<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditPolicyFlag;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Models\Loan;
use App\Services\CreditScoring\Api\Presenter;
use App\Services\CreditScoring\ScorecardV1;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Least privilege, closed as a class (Arvin, 2026-11-09): a caller who can't
 * read the member's details (no borrowers:view, or not the member's branch)
 * gets a summary only, so nothing can be inferred from factors, flags,
 * confidence, category points or the data behind the score.
 */
uses(TestCase::class, CreditApiFixtures::class);

/** Keys a summary leaves out. */
const SUMMARY_ABSENT_KEYS = ['confidence', 'confidence_reason', 'capacity', 'data_used'];

beforeEach(function () {
    $this->pinToday();

    // A main-branch member with history, a failed income verification, a
    // hard flag and an alert; and a far-branch member with an application
    // in the main branch.
    $this->near = $this->scoredMember(null, ['date_hired' => '2019-03-01']);
    $this->nearLoan = $this->application($this->near);
    $this->actingAs($this->headOffice())->putJson("/api/credit-scoring/loans/{$this->nearLoan->id}/assessment", [
        'household_expenses' => 20000, 'years_at_residence' => 6, 'verified_references_count' => 1, 'income_verification' => 'failed',
    ])->assertOk();
    $this->period(Loan::query()->where('borrower_id', $this->near->id)->where('status', 'ongoing')->firstOrFail(), 4, '2026-09-01');
    $this->scoreNow($this->near);
    CreditPolicyFlag::query()->create([
        'borrower_id' => $this->near->id, 'rule_key' => 'existing_default', 'type' => 'hard_flag', 'label' => 'Defaulted loan',
        'detail' => 'The member has 1 defaulted loan.', 'first_triggered_at' => now()->subDay(), 'last_seen_at' => now(),
    ]);
    CreditRiskAlert::query()->create([
        'borrower_id' => $this->near->id, 'credit_score_id' => CreditScore::query()->where('borrower_id', $this->near->id)->latest('id')->value('id'),
        'kind' => 'score_decline', 'previous_score' => 90, 'current_score' => 70, 'previous_risk_level' => 'very_low',
        'current_risk_level' => 'moderate', 'severity' => 'critical', 'message' => 'A message.',
    ]);

    $this->far = $this->member(['branch_id' => $this->farBranch()->id, 'monthly_income' => 35000]);
    $this->farLoan = $this->application($this->far, ['branch_id' => $this->mainBranch()->id]);
    $this->actingAs($this->headOffice())->putJson("/api/credit-scoring/loans/{$this->farLoan->id}/assessment", [
        'household_expenses' => 20000, 'income_verification' => 'failed', 'notice_acknowledged' => true,
    ])->assertOk();
});

/**
 * @param  array<string, mixed>  $score
 */
function expectSummaryScore(array $score): void
{
    expect($score['details_hidden'])->toBeTrue()
        ->and($score)->not->toHaveKeys(SUMMARY_ABSENT_KEYS)
        ->and($score['factors'])->toBe([])
        ->and($score['factors_without_data'])->toBe([])
        ->and($score['category_breakdown'])->toBe([])
        ->and($score)->toHaveKeys(['score', 'risk_level', 'model_version', 'calculated_at', 'previous_score', 'status', 'recommendation']);
}

/**
 * The whole answer never names a factor, a flag rule, a verification, the
 * ID on file or the confidence.
 */
function expectNothingInferable(string $json): void
{
    $factorKeys = array_column(ScorecardV1::factors(), 'key');
    $ruleKeys = array_diff(array_column(ScorecardV1::policyRules(), 'key'), ['past_due']);

    foreach ([...$factorKeys, ...$ruleKeys] as $key) {
        expect($json)->not->toContain("\"{$key}\"");
    }

    expect($json)->not->toContain('Verified')
        ->and($json)->not->toContain('verif')
        ->and($json)->not->toContain('has_valid_id')
        ->and($json)->not->toContain('"confidence')
        ->and($json)->not->toContain('Defaulted loan');
}

it('answers a caller without borrowers:view with summaries everywhere', function () {
    $caller = $this->caller(['credit_scoring:view', 'loans:view']);
    $this->actingAs($caller);

    $profile = $this->getJson("/api/credit-scoring/borrowers/{$this->near->id}")->assertOk()->json('data');
    $flags = $this->getJson("/api/credit-scoring/borrowers/{$this->near->id}/policy-flags")->assertOk()->json('data');
    $assessment = $this->getJson("/api/credit-scoring/loans/{$this->nearLoan->id}/assessment")->assertOk()->json('data');
    $risk = $this->getJson('/api/credit-scoring/risk-monitoring')->assertOk()->json('data');

    expectSummaryScore($profile);
    expectSummaryScore($assessment['score']);
    expect($profile['recommendation'])->toBe(Presenter::MANUAL_REVIEW)
        ->and($flags)->toBe(['data' => [], 'details_hidden' => true])
        ->and($assessment['details_hidden'])->toBeTrue()
        ->and($assessment['policy_flags'])->toBe([])
        ->and($assessment)->not->toHaveKey('capacity')
        ->and(array_keys($assessment['application_profile']))->toBe(['notice_version', 'notice_acknowledged_at', 'notice_acknowledged'])
        ->and($risk['summary']['new_hard_flags_30d'])->toBeNull()
        ->and(count($risk['alerts']))->toBeGreaterThanOrEqual(1);

    expectNothingInferable(json_encode([
        $profile,
        $flags,
        $assessment,
        $risk,
        $this->getJson("/api/credit-scoring/borrowers/{$this->near->id}/history")->assertOk()->json(),
        $this->getJson('/api/credit-scoring/dashboard')->assertOk()->json(),
        $this->getJson('/api/credit-scoring/score-history')->assertOk()->json(),
    ]));
});

it('answers a caller outside the member\'s branch with summaries on the assessment', function () {
    $data = $this->actingAs($this->branchAnalyst())->getJson("/api/credit-scoring/loans/{$this->farLoan->id}/assessment")->assertOk()->json('data');

    expectSummaryScore($data['score']);
    expect($data['details_hidden'])->toBeTrue()
        ->and($data['application_figures_hidden'])->toBeTrue()
        ->and($data['policy_flags'])->toBe([])
        ->and($data)->not->toHaveKey('capacity')
        ->and($data['application_profile'])->toBe([
            'notice_version' => '1.0',
            'notice_acknowledged_at' => CreditApplicationProfile::query()->where('loan_id', $this->farLoan->id)->sole()->notice_acknowledged_at->toIso8601String(),
            'notice_acknowledged' => true,
        ])
        ->and($data['score']['recommendation'])->toBe(Presenter::MANUAL_REVIEW);
    expectNothingInferable(json_encode($data));
});

it('leaves a flag alone out of risk monitoring for a caller without borrowers:view', function () {
    $clean = $this->scoredMember();
    $current = CreditScoreMember::query()->where('borrower_id', $clean->id)->sole();
    expect($current->past_due)->toBeFalse()
        ->and($current->risk_level)->not->toBeIn(['high', 'very_high']);
    CreditPolicyFlag::query()->create([
        'borrower_id' => $clean->id, 'rule_key' => 'previous_write_off', 'type' => 'hard_flag', 'label' => 'Previous write-off',
        'detail' => 'Part of 1 previous loan was written off.', 'first_triggered_at' => now()->subDay(), 'last_seen_at' => now(),
    ]);

    $narrow = $this->actingAs($this->caller(['credit_scoring:view']))->getJson('/api/credit-scoring/risk-monitoring')->json('data');
    $full = $this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/risk-monitoring')->json('data');

    expect(collect($full['affected_borrowers'])->pluck('borrower_id'))->toContain($clean->id)
        ->and($full['summary']['new_hard_flags_30d'])->toBeGreaterThanOrEqual(1)
        ->and(collect($narrow['affected_borrowers'])->pluck('borrower_id'))->not->toContain($clean->id)
        ->and($narrow['affected_total'])->toBe($full['affected_total'] - 1);
});

it('gives a summary a band\'s own advice when there is no hard flag', function () {
    CreditPolicyFlag::query()->where('borrower_id', $this->near->id)->update(['cleared_at' => now()]);
    $score = CreditScore::query()->where('borrower_id', $this->near->id)->latest('id')->first();
    $advice = collect(ScorecardV1::riskBands())->firstWhere('key', $score->risk_level)['recommendation'];

    $profile = $this->actingAs($this->caller(['credit_scoring:view']))->getJson("/api/credit-scoring/borrowers/{$this->near->id}")->json('data');

    expect($profile['recommendation'])->toBe($advice);
});

it('still gives a full-access caller everything', function () {
    $this->actingAs($this->headOffice());

    $profile = $this->getJson("/api/credit-scoring/borrowers/{$this->near->id}")->assertOk()->json('data');
    $flags = $this->getJson("/api/credit-scoring/borrowers/{$this->near->id}/policy-flags")->assertOk()->json('data');
    $assessment = $this->getJson("/api/credit-scoring/loans/{$this->farLoan->id}/assessment")->assertOk()->json('data');

    expect($profile['details_hidden'])->toBeFalse()
        ->and($profile)->toHaveKeys(SUMMARY_ABSENT_KEYS)
        ->and($profile['factors'])->not->toBe([])
        ->and($profile['category_breakdown'])->toHaveCount(6)
        ->and($flags['details_hidden'])->toBeFalse()
        ->and(collect($flags['data'])->pluck('label'))->toContain('Defaulted loan')
        ->and($assessment['details_hidden'])->toBeFalse()
        ->and($assessment)->toHaveKey('capacity')
        ->and($assessment['application_profile']['income_verification'])->toBe('failed')
        ->and(collect($assessment['policy_flags'])->pluck('label'))->toContain('Income verification failed')
        ->and($assessment['score']['recommendation'])->toContain('Manual review required: ');
});

it('refuses a summary caller\'s save of application details with 403, changing nothing', function (string $who) {
    [$caller, $loan] = $who === 'outside the member\'s branch'
        ? [$this->branchAnalyst(), $this->farLoan]
        : [$this->caller(['credit_scoring:view', 'loans:view', 'loans:update']), $this->nearLoan];
    $profile = CreditApplicationProfile::query()->where('loan_id', $loan->id)->sole()->getAttributes();
    $scores = CreditScore::query()->count();
    $openFlags = CreditPolicyFlag::query()->open()->where('loan_id', $loan->id)->pluck('id')->all();

    $this->actingAs($caller)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['income_verification' => null, 'household_expenses' => 1])
        ->assertForbidden()
        ->assertJsonPath('message', 'Saving application details needs permission to view the member\'s details in their branch.');

    expect(CreditApplicationProfile::query()->where('loan_id', $loan->id)->sole()->getAttributes())->toBe($profile)
        ->and(CreditScore::query()->count())->toBe($scores)
        ->and($openFlags)->not->toBe([])
        ->and(CreditPolicyFlag::query()->open()->where('loan_id', $loan->id)->pluck('id')->all())->toBe($openFlags);

    // Recording the notice alone stays allowed.
    $this->actingAs($caller)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['notice_acknowledged' => true])->assertOk();
})->with(['outside the member\'s branch', 'without borrowers:view']);

it('hides past due and active loans, and refuses those filters, without loans:view', function () {
    $noLoans = $this->caller(['credit_scoring:view', 'borrowers:view']);
    $this->actingAs($noLoans);

    $rows = $this->getJson('/api/credit-scoring/borrowers')->assertOk()->json('data.data');
    expect(collect($rows)->pluck('past_due')->unique()->all())->toBe([null])
        ->and(collect($rows)->pluck('has_active_loan')->unique()->all())->toBe([null]);

    foreach (['past_due', 'has_active_loan'] as $filter) {
        $this->getJson("/api/credit-scoring/borrowers?{$filter}=1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$filter => 'Filtering by past due or active loans needs permission to view loans.']);
    }

    $affected = collect($this->getJson('/api/credit-scoring/risk-monitoring')->json('data.affected_borrowers'))->pluck('borrower_id');
    $nearRow = CreditScoreMember::query()->where('borrower_id', $this->near->id)->sole();
    expect($nearRow->past_due)->toBeTrue();
    if (! in_array($nearRow->risk_level, ['high', 'very_high'], true)) {
        expect($affected)->not->toContain($this->near->id);
    }
    expect(collect($this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/risk-monitoring')->json('data.affected_borrowers'))->pluck('borrower_id'))->toContain($this->near->id);
});

it('shows a summary caller a decision without its reason or remarks, and history as "Recalculated"', function () {
    $score = CreditScore::query()->where('loan_id', $this->nearLoan->id)->latest('id')->firstOrFail();
    $this->actingAs($this->headOffice())->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->near->id, 'credit_score_id' => $score->id, 'loan_id' => $this->nearLoan->id,
        'decision' => 'decline', 'reason' => 'Income could not be verified', 'remarks' => 'Payslips missing.',
    ])->assertCreated();
    $summary = $this->caller(['credit_scoring:view', 'loans:view']);

    $decision = $this->actingAs($summary)->getJson("/api/credit-scoring/loans/{$this->nearLoan->id}/assessment")->json('data.decisions.0');
    $history = $this->actingAs($summary)->getJson("/api/credit-scoring/borrowers/{$this->near->id}/history")->json('data.data');
    $portfolio = $this->actingAs($summary)->getJson('/api/credit-scoring/score-history')->json('data.data');
    $recent = $this->actingAs($summary)->getJson('/api/credit-scoring/dashboard')->json('data.recent_score_changes');

    expect($decision)->not->toHaveKeys(['reason', 'remarks'])
        ->and($decision['decision'])->toBe('decline')
        ->and($decision)->toHaveKeys(['decided_at', 'decided_by'])
        ->and(collect([...$history, ...$portfolio, ...$recent])->pluck('reason')->unique()->values()->all())->toBe(['Recalculated']);

    $full = $this->actingAs($this->headOffice())->getJson("/api/credit-scoring/loans/{$this->nearLoan->id}/assessment")->json('data.decisions.0');
    expect($full['reason'])->toBe('Income could not be verified')
        ->and($full['remarks'])->toBe('Payslips missing.')
        ->and(collect($this->actingAs($this->headOffice())->getJson("/api/credit-scoring/borrowers/{$this->near->id}/history")->json('data.data'))->pluck('reason'))->not->toContain('Recalculated');
});
