<?php

use App\Models\CreditDecision;
use App\Models\CreditPolicyFlag;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\Loan;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Every response, key for key, against the frontend's
 * src/types/credit-scoring.ts, plus the additions this slice makes (listed
 * in the PR for FC). Arrays are arrays, never null.
 */
uses(TestCase::class, CreditApiFixtures::class);

const CREDIT_SCORE_KEYS = [
    // types/credit-scoring.ts CreditScore
    'id', 'borrower_id', 'borrower_name', 'score_type', 'score', 'risk_level', 'confidence', 'model_version',
    'category_breakdown', 'factors', 'recommendation', 'calculated_at',
    // additions
    'borrower_code', 'status', 'limited_history', 'confidence_reason', 'validated', 'previous_score', 'context', 'loan_id',
    'factors_without_data', 'capacity', 'data_used',
];

const BORROWER_SCORE_ROW_KEYS = [
    'borrower_id', 'borrower_name', 'branch_id', 'branch_name', 'score', 'risk_level', 'score_trend', 'has_active_loan', 'past_due', 'calculated_at',
    'borrower_code', 'status',
];

const HISTORY_ENTRY_KEYS = [
    'id', 'borrower_id', 'borrower_name', 'score', 'risk_level', 'score_type', 'model_version', 'calculated_at', 'reason',
    'borrower_code', 'status', 'previous_score',
];

const POLICY_FLAG_KEYS = ['id', 'type', 'label', 'detail', 'triggered_at', 'loan_id'];

const DECISION_KEYS = ['id', 'borrower_id', 'credit_score_id', 'decision', 'reason', 'remarks', 'decided_by', 'decided_at', 'loan_id', 'revision_version'];

const RISK_ALERT_KEYS = ['id', 'borrower_id', 'borrower_name', 'message', 'previous_score', 'current_score', 'severity', 'created_at', 'borrower_code', 'kind'];

/**
 * @param  array<string, mixed>  $row
 * @param  list<string>  $keys
 */
function expectKeys(array $row, array $keys): void
{
    $actual = array_keys($row);
    sort($actual);
    sort($keys);

    expect($actual)->toBe($keys);
}

beforeEach(function () {
    $this->pinToday();
    $this->viewer = $this->headOffice();
    $this->member = $this->scoredMember();
});

it('shapes the credit profile', function () {
    $data = $this->actingAs($this->viewer)->getJson("/api/credit-scoring/borrowers/{$this->member->id}")
        ->assertOk()->assertJsonPath('success', true)->json('data');

    expectKeys($data, CREDIT_SCORE_KEYS);
    expectKeys($data['category_breakdown'][0], ['category', 'weight_percent', 'points_earned', 'points_possible', 'key', 'has_data']);
    expectKeys($data['factors'][0], ['id', 'label', 'detail', 'impact', 'category']);
    expectKeys($data['factors_without_data'][0], ['label', 'category']);

    $score = CreditScore::query()->where('borrower_id', $this->member->id)->sole();
    expect($data['id'])->toBe($score->id)
        ->and($data['model_version'])->toBe('1.0')
        ->and($data['status'])->toBe('scored')
        ->and($data['context'])->toBe('nightly')
        ->and($data['loan_id'])->toBeNull()
        ->and($data['validated'])->toBeFalse()
        ->and($data['borrower_name'])->toBe($this->member->full_name)
        ->and($data['category_breakdown'])->toHaveCount(6)
        ->and(collect($data['category_breakdown'])->pluck('category')->all())->toBe(['Repayment Behaviour', 'Payment Capacity', 'Credit Exposure', 'Stability', 'Lendy Relationship', 'Application Quality'])
        ->and(collect($data['factors'])->pluck('impact')->unique()->diff(['positive', 'risk'])->all())->toBe([])
        ->and(collect($data['factors'])->pluck('id')->all())->toBe($score->factors->where('has_data', true)->pluck('id')->values()->all())
        ->and($data['capacity'])->toBe($score->capacity)
        ->and($data['calculated_at'])->toBe($score->calculated_at->toIso8601String())
        ->and($data['calculated_at'])->toEndWith('+08:00');
});

it('answers not_scored, with empty arrays, for a member never scored yet', function () {
    $fresh = $this->member();

    $data = $this->actingAs($this->viewer)->getJson("/api/credit-scoring/borrowers/{$fresh->id}")->assertOk()->json('data');

    expectKeys($data, CREDIT_SCORE_KEYS);
    expect($data['status'])->toBe('not_scored')
        ->and($data['id'])->toBeNull()
        ->and($data['score'])->toBeNull()
        ->and($data['risk_level'])->toBeNull()
        ->and($data['category_breakdown'])->toBe([])
        ->and($data['factors'])->toBe([])
        ->and($data['factors_without_data'])->toBe([]);
});

it('answers 404 for a pending or rejected registration, like a missing member', function (string $status) {
    $registration = $this->member(['status' => $status, 'approved_at' => null]);

    $this->actingAs($this->viewer)->getJson("/api/credit-scoring/borrowers/{$registration->id}")->assertNotFound();
    $this->actingAs($this->viewer)->getJson('/api/credit-scoring/borrowers/999999')->assertNotFound();
})->with(['pending', 'rejected']);

it('shapes the scored member list as a paginator', function () {
    $response = $this->actingAs($this->viewer)->getJson('/api/credit-scoring/borrowers')->assertOk();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.total'))->toBe(1)
        ->and($response->json('data.per_page'))->toBe(25);
    expectKeys($response->json('data.data.0'), BORROWER_SCORE_ROW_KEYS);
    expect($response->json('data.data.0.branch_name'))->toBe($this->mainBranch()->name);
});

it('clamps per_page between 1 and 100', function (int $sent, int $used) {
    $this->actingAs($this->viewer)->getJson("/api/credit-scoring/borrowers?per_page={$sent}")
        ->assertOk()->assertJsonPath('data.per_page', $used);
    $this->actingAs($this->viewer)->getJson("/api/credit-scoring/score-history?per_page={$sent}")
        ->assertOk()->assertJsonPath('data.per_page', $used);
})->with([[0, 1], [500, 100], [40, 40]]);

it('shapes the score history, newest first, with the member\'s name', function () {
    $this->period(Loan::query()->where('borrower_id', $this->member->id)->firstOrFail(), 4, '2026-09-01');
    $this->travel(1)->days();
    $this->scoreNow($this->member);

    $rows = $this->actingAs($this->viewer)->getJson('/api/credit-scoring/score-history')->assertOk()->json('data.data');
    $own = $this->actingAs($this->viewer)->getJson("/api/credit-scoring/borrowers/{$this->member->id}/history")->assertOk()->json('data.data');

    expect($rows)->toHaveCount(2)
        ->and($own)->toBe($rows)
        ->and($rows[0]['calculated_at'] > $rows[1]['calculated_at'])->toBeTrue()
        ->and($rows[0]['previous_score'])->toBe($rows[1]['score'])
        ->and($rows[0]['reason'])->toBe('Nightly review')
        ->and($rows[0]['borrower_name'])->toBe($this->member->full_name);
    expectKeys($rows[0], HISTORY_ENTRY_KEYS);
});

it('filters the score history by inclusive Manila dates', function () {
    $this->travelTo('2026-11-10 23:59:00');
    $this->period(Loan::query()->where('borrower_id', $this->member->id)->firstOrFail(), 4, '2026-09-01');
    $this->scoreNow($this->member);

    $count = fn (string $query) => count($this->actingAs($this->viewer)->getJson("/api/credit-scoring/score-history?{$query}")->assertOk()->json('data.data'));

    expect($count('from=2026-11-10&to=2026-11-10'))->toBe(1)
        ->and($count('from=2026-11-08&to=2026-11-08'))->toBe(1)
        ->and($count('from=2026-11-08'))->toBe(2)
        ->and($count('to=2026-11-09'))->toBe(1)
        ->and($count('from=2026-11-11'))->toBe(0);

    $this->actingAs($this->viewer)->getJson('/api/credit-scoring/score-history?from=2026-11-10&to=2026-11-01')->assertUnprocessable();
    $this->actingAs($this->viewer)->getJson('/api/credit-scoring/score-history?from=10/11/2026')->assertUnprocessable();
});

it('shapes the dashboard with all six risk levels once each, zeros included', function () {
    $insufficient = $this->member();
    $this->scoreNow($insufficient);

    $data = $this->actingAs($this->viewer)->getJson('/api/credit-scoring/dashboard')->assertOk()->json('data');

    expectKeys($data, ['total_scored_borrowers', 'average_score', 'risk_distribution', 'recent_score_changes', 'insufficient_data_count']);
    $score = CreditScore::query()->where('borrower_id', $this->member->id)->sole();
    expect(collect($data['risk_distribution'])->pluck('risk_level')->all())->toBe(['very_low', 'low', 'moderate', 'elevated', 'high', 'very_high'])
        ->and(collect($data['risk_distribution'])->sum('count'))->toBe(1)
        ->and(collect($data['risk_distribution'])->firstWhere('risk_level', $score->risk_level)['count'])->toBe(1)
        ->and($data['total_scored_borrowers'])->toBe(1)
        ->and($data['insufficient_data_count'])->toBe(1)
        ->and($data['average_score'])->toEqual($score->score)
        ->and($data['recent_score_changes'])->toHaveCount(2);
    expectKeys($data['recent_score_changes'][0], HISTORY_ENTRY_KEYS);
});

it('averages scores half up to one decimal, and sends null with nobody scored', function () {
    $second = $this->scoredMember();
    $scores = CreditScore::query()->whereIn('borrower_id', [$this->member->id, $second->id])->pluck('score');
    $sum = $scores->sum();
    $expected = intdiv(20 * $sum + 2, 4) / 10;

    expect($this->actingAs($this->viewer)->getJson('/api/credit-scoring/dashboard')->json('data.average_score'))->toEqual($expected);

    $empty = $this->actingAs($this->caller(['credit_scoring:view'], $this->farBranch()))->getJson('/api/credit-scoring/dashboard')->assertOk()->json('data');
    expect($empty['average_score'])->toBeNull()
        ->and($empty['recent_score_changes'])->toBe([])
        ->and(collect($empty['risk_distribution'])->pluck('count')->all())->toBe([0, 0, 0, 0, 0, 0]);
});

it('shapes policy flags: active only, triggered when first seen', function () {
    $open = CreditPolicyFlag::query()->create([
        'borrower_id' => $this->member->id, 'rule_key' => 'past_due', 'type' => 'soft_flag', 'label' => 'Loan past due',
        'detail' => 'A loan is 4 days past due.', 'first_triggered_at' => now()->subDays(3), 'last_seen_at' => now(),
    ]);
    CreditPolicyFlag::query()->create([
        'borrower_id' => $this->member->id, 'rule_key' => 'existing_default', 'type' => 'hard_flag', 'label' => 'Defaulted loan',
        'detail' => 'Cleared.', 'first_triggered_at' => now()->subDays(9), 'last_seen_at' => now()->subDays(2), 'cleared_at' => now()->subDay(),
    ]);

    $flags = $this->actingAs($this->viewer)->getJson("/api/credit-scoring/borrowers/{$this->member->id}/policy-flags")->assertOk()->json('data');

    expect($flags)->toHaveCount(1)
        ->and($flags[0]['id'])->toBe($open->id)
        ->and($flags[0]['triggered_at'])->toBe($open->first_triggered_at->toIso8601String());
    expectKeys($flags[0], POLICY_FLAG_KEYS);

    $none = $this->member();
    expect($this->actingAs($this->viewer)->getJson("/api/credit-scoring/borrowers/{$none->id}/policy-flags")->assertOk()->json('data'))->toBe([]);
});

it('shapes risk monitoring', function () {
    $score = CreditScore::query()->where('borrower_id', $this->member->id)->sole();
    CreditRiskAlert::query()->create([
        'borrower_id' => $this->member->id, 'credit_score_id' => $score->id, 'kind' => 'score_decline', 'previous_score' => 90,
        'current_score' => 70, 'previous_risk_level' => 'very_low', 'current_risk_level' => 'moderate', 'severity' => 'critical',
        'message' => 'A message.',
    ]);

    $data = $this->actingAs($this->viewer)->getJson('/api/credit-scoring/risk-monitoring')->assertOk()->json('data');

    expectKeys($data, ['summary', 'affected_borrowers', 'alerts', 'affected_total']);
    expectKeys($data['summary'], ['total_borrowers', 'high_risk_count', 'score_declines_30d', 'new_hard_flags_30d']);
    expectKeys($data['alerts'][0], RISK_ALERT_KEYS);
    expect($data['affected_borrowers'])->toBe([])
        ->and($data['affected_total'])->toBe(0)
        ->and($data['summary']['total_borrowers'])->toBe(1);
});

it('shapes a decision', function () {
    $score = CreditScore::query()->where('borrower_id', $this->member->id)->sole();

    $data = $this->actingAs($this->viewer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->member->id,
        'credit_score_id' => $score->id,
        'decision' => 'refer',
        'reason' => '  Needs income documents  ',
    ])->assertCreated()->assertJsonPath('success', true)->json('data');

    expectKeys($data, DECISION_KEYS);
    expect($data['reason'])->toBe('Needs income documents')
        ->and($data['remarks'])->toBeNull()
        ->and($data['decided_by'])->toBe($this->viewer->full_name)
        ->and($data['revision_version'])->toBe('1.0')
        ->and(CreditDecision::query()->sole()->score_snapshot['score'])->toBe($score->score);
});

it('shapes the scorecard configuration and the settings', function () {
    $config = $this->actingAs($this->viewer)->getJson('/api/credit-scoring/scorecard-config')->assertOk()->json('data');

    expectKeys($config, ['categories', 'factor_rules', 'policy_rules', 'risk_bands', 'version', 'validated']);
    expectKeys($config['categories'][0], ['key', 'label', 'weight_percent', 'description']);
    expectKeys($config['factor_rules'][0], ['id', 'category_key', 'label', 'points', 'is_active']);
    expectKeys($config['policy_rules'][0], ['id', 'label', 'description', 'is_active']);
    expectKeys($config['risk_bands'][0], ['key', 'label', 'min_score']);
    expect(array_sum(array_column($config['categories'], 'weight_percent')))->toBe(100)
        ->and($config['factor_rules'])->toHaveCount(18)
        ->and($config['policy_rules'])->toHaveCount(9);

    $settings = $this->actingAs($this->viewer)->getJson('/api/credit-scoring/settings')->assertOk()->json('data');

    expectKeys($settings, [
        'privacy_notice', 'score_model_version', 'confidence_definitions', 'hard_flag_definitions',
        'notice_version', 'dsr_manageable_max', 'dsr_caution_max', 'capacity_share', 'max_active_loans', 'hard_past_due_days', 'decline_alert_points',
    ]);
    expect($settings['score_model_version'])->toBe('1.0')
        ->and(collect($settings['confidence_definitions'])->pluck('level')->all())->toBe(['high', 'medium', 'low'])
        ->and(collect($settings['hard_flag_definitions'])->pluck('type')->all())->toBe(['hard_flag', 'soft_flag'])
        ->and($settings['dsr_manageable_max'])->toEqual(30)
        ->and($settings['max_active_loans'])->toBe(2);
    expectKeys($settings['confidence_definitions'][0], ['level', 'label', 'description']);
    expectKeys($settings['hard_flag_definitions'][0], ['type', 'label', 'description']);
});
