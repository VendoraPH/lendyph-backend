<?php

use App\Models\AuditLog;
use App\Models\CreditScore;
use App\Models\CreditScorecardRevision;
use App\Models\CreditScoreFactor;
use App\Services\CreditScoring\Recalculator;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Factor ranges (task 86d4ayrmt section 27): each factor's bands, `otherwise`
 * points and (on the defaults factor) default points can be changed from
 * Scorecard Configuration. The factor's id, rule and own points stay fixed.
 * A change writes a new immutable revision, audited in words.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->admin = $this->caller(['credit_scoring:settings']);
    $this->config = $this->actingAs($this->admin)->getJson('/api/credit-scoring/scorecard-config')->assertOk()->json('data');

    // The current config with one factor's fields replaced, by factor id.
    $this->withFactor = function (int $id, array $changes): array {
        $config = $this->config;
        $config['factor_rules'] = array_map(fn (array $f) => $f['id'] === $id ? array_merge($f, $changes) : $f, $config['factor_rules']);

        return $config;
    };

    $this->put = fn (array $body) => $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $body);
});

it('reads each factor\'s rule, bands, otherwise points and unit', function () {
    $onTime = collect($this->config['factor_rules'])->firstWhere('id', 1);
    $defaults = collect($this->config['factor_rules'])->firstWhere('id', 4);

    expect($onTime)->toMatchArray([
        'label' => 'On-time payment rate',
        'points' => 15,
        'rule' => 'at_least',
        'bands' => [['threshold' => 95, 'points' => 15], ['threshold' => 90, 'points' => 12], ['threshold' => 80, 'points' => 8], ['threshold' => 70, 'points' => 4]],
        'otherwise' => 0,
        'unit' => '%',
    ])
        ->and($onTime)->not->toHaveKey('default_points')
        ->and($defaults['rule'])->toBe('at_most')
        ->and($defaults['default_points'])->toBe(0)
        ->and(collect($this->config['factor_rules'])->firstWhere('id', 6)['bands'][2]['threshold'])->toEqual(1.5);
});

it('saves changed bands as revision 1.1, and a recalculation scores with them', function () {
    $member = $this->scoredMember();
    $before = CreditScore::query()->where('borrower_id', $member->id)->sole();
    $onTimeBefore = CreditScoreFactor::query()->where('credit_score_id', $before->id)->where('factor_key', 'on_time_rate')->sole();
    expect($onTimeBefore->points_earned)->toBe(15);

    $saved = ($this->put)(($this->withFactor)(1, [
        'bands' => [['threshold' => '100.5', 'points' => 15], ['threshold' => 98, 'points' => 10], ['threshold' => 90, 'points' => 6]],
        'otherwise' => 1,
    ]))->assertOk()->assertJsonPath('data.version', '1.1')->json('data');

    $revision = CreditScorecardRevision::query()->where('version', '1.1')->sole();
    $stored = collect($revision->factors)->firstWhere('id', 1);
    expect($stored['bands'])->toEqual([['threshold' => 100.5, 'points' => 15], ['threshold' => 98, 'points' => 10], ['threshold' => 90, 'points' => 6]])
        ->and($stored['otherwise'])->toBe(1)
        ->and($stored['rule'])->toBe('at_least')
        ->and($stored['points'])->toBe(15)
        ->and(collect($saved['factor_rules'])->firstWhere('id', 1)['bands'][0]['threshold'])->toEqual(100.5)
        ->and(collect($revision->factors)->except(0)->values()->all())->toBe(collect(CreditScorecardRevision::query()->where('version', '1.0')->sole()->factors)->except(0)->values()->all());

    app(Recalculator::class)->scoreMember($member->id, 'Nightly review');
    $after = CreditScore::query()->where('borrower_id', $member->id)->latest('id')->first();
    $onTimeAfter = CreditScoreFactor::query()->where('credit_score_id', $after->id)->where('factor_key', 'on_time_rate')->sole();

    expect($after->revision_version)->toBe('1.1')
        ->and($onTimeAfter->points_earned)->toBe(10)
        ->and($after->score)->toBeLessThan($before->score);
});

it('audits a factor range change in words', function () {
    ($this->put)(($this->withFactor)(2, [
        'bands' => [['threshold' => 0, 'points' => 8], ['threshold' => 10, 'points' => 5], ['threshold' => 45, 'points' => 2]],
    ]))->assertOk();
    $this->config = $this->actingAs($this->admin)->getJson('/api/credit-scoring/scorecard-config')->json('data');
    ($this->put)(($this->withFactor)(1, ['bands' => [['threshold' => 97.5, 'points' => 15], ['threshold' => 90, 'points' => 12]]]))
        ->assertOk()->assertJsonPath('data.version', '1.2');

    $first = CreditScorecardRevision::query()->where('version', '1.1')->sole();
    $row = AuditLog::query()->where('auditable_type', CreditScorecardRevision::class)->where('auditable_id', $first->id)->where('action', 'created')->sole();

    expect($row->description)->toBe(
        'Scorecard 1.1 saved from 1.0. Factor ranges: Worst days late in the last 24 months: 0 days or less: 8 points; 10 days or less: 5 points; 45 days or less: 2 points; otherwise: 0 points.'
    )
        ->and($row->new_values['version'])->toBe('1.1')
        ->and($row->user_id)->toBe($this->admin->id);

    $second = AuditLog::query()->where('auditable_type', CreditScorecardRevision::class)->where('action', 'created')->latest('id')->first();
    expect($second->description)->toContain('On-time payment rate: 97.5% or more: 15 points; 90% or more: 12 points; otherwise: 0 points.')
        ->and($second->description)->not->toContain('Worst days late');
});

it('audits weights and risk bands in words too', function () {
    $config = $this->config;
    $config['categories'] = array_map(fn (array $c) => match ($c['key']) {
        'repayment' => ['weight_percent' => 30] + $c,
        'stability' => ['weight_percent' => 15] + $c,
        default => $c,
    }, $config['categories']);
    $config['risk_bands'] = array_map(fn (array $b) => $b['key'] === 'elevated' ? ['min_score' => 55] + $b : $b, $config['risk_bands']);
    $defaults = collect($config['factor_rules'])->firstWhere('id', 4);
    $config['factor_rules'] = array_map(fn (array $f) => $f['id'] === 4 ? ['default_points' => 1] + $f : $f, $config['factor_rules']);
    expect($defaults['default_points'])->toBe(0);

    ($this->put)($config)->assertOk();

    expect(AuditLog::query()->where('auditable_type', CreditScorecardRevision::class)->where('action', 'created')->latest('id')->first()->description)->toBe(
        'Scorecard 1.1 saved from 1.0. Weights: Repayment Behaviour 35.00% to 30.00%; Stability 10.00% to 15.00%. Risk bands: Elevated Risk from 60 to 55. '
        .'Factor ranges: Defaults and restructures: a defaulted or written-off loan: 1 point; 0 restructured loans or less: 5 points; 1 restructured loan or less: 3 points; otherwise: 1 point.'
    );
});

it('refuses thresholds out of order for the factor\'s rule', function (int $id, array $bands) {
    ($this->put)(($this->withFactor)($id, ['bands' => $bands]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['factor_rules.'.($id - 1).'.bands']);

    expect(CreditScorecardRevision::query()->count())->toBe(1);
})->with([
    'at least, rising' => [1, [['threshold' => 80, 'points' => 8], ['threshold' => 95, 'points' => 15]]],
    'at least, equal' => [1, [['threshold' => 90, 'points' => 15], ['threshold' => 90, 'points' => 12]]],
    'at most, falling' => [2, [['threshold' => 30, 'points' => 3], ['threshold' => 0, 'points' => 8]]],
]);

it('refuses points outside 0 to the factor\'s own points', function (array $changes, string $field) {
    ($this->put)(($this->withFactor)(4, $changes))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'band above' => [['bands' => [['threshold' => 0, 'points' => 6]]], 'factor_rules.3.bands.0.points'],
    'band below' => [['bands' => [['threshold' => 0, 'points' => -1]]], 'factor_rules.3.bands.0.points'],
    'otherwise above' => [['otherwise' => 6], 'factor_rules.3.otherwise'],
    'default above' => [['default_points' => 9], 'factor_rules.3.default_points'],
    'decimal points' => [['otherwise' => 1.5], 'factor_rules.3.otherwise'],
]);

it('refuses a bad threshold, an empty band list, and default points on a factor without them', function (int $id, array $changes, string $field) {
    ($this->put)(($this->withFactor)($id, $changes))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'three decimals' => [1, ['bands' => [['threshold' => 95.125, 'points' => 15]]], 'factor_rules.0.bands.0.threshold'],
    'negative' => [2, ['bands' => [['threshold' => -1, 'points' => 8]]], 'factor_rules.1.bands.0.threshold'],
    'not a number' => [1, ['bands' => [['threshold' => 'high', 'points' => 15]]], 'factor_rules.0.bands.0.threshold'],
    'no bands' => [1, ['bands' => []], 'factor_rules.0.bands'],
    'default points added' => [1, ['default_points' => 0], 'factor_rules.0.default_points'],
]);

it('refuses a changed id, rule, own points or label, and a missing or repeated factor', function (callable $change) {
    $config = $this->config;
    $config['factor_rules'] = $change($config['factor_rules']);

    ($this->put)($config)->assertUnprocessable()->assertJsonValidationErrors('factor_rules');
    expect(CreditScorecardRevision::query()->count())->toBe(1);
})->with([
    'id' => fn () => fn (array $f) => [['id' => 99] + $f[0], ...array_slice($f, 1)],
    'rule' => fn () => fn (array $f) => [['rule' => 'at_most'] + $f[0], ...array_slice($f, 1)],
    'points' => fn () => fn (array $f) => [['points' => 20] + $f[0], ...array_slice($f, 1)],
    'label' => fn () => fn (array $f) => [['label' => 'Other'] + $f[0], ...array_slice($f, 1)],
    'missing' => fn () => fn (array $f) => array_slice($f, 1),
    'repeated' => fn () => fn (array $f) => [$f[0], ...array_slice($f, 0, 17)],
]);

it('keeps a factor\'s bands when they are left out, and writes nothing when the ranges come back unchanged', function () {
    $bare = $this->config;
    $bare['factor_rules'] = array_map(fn (array $f) => array_diff_key($f, array_flip(['bands', 'otherwise', 'default_points', 'unit'])), $bare['factor_rules']);
    $audits = AuditLog::query()->count();

    ($this->put)($bare)->assertOk()->assertJsonPath('data.version', '1.0');
    ($this->put)($this->config)->assertOk()->assertJsonPath('data.version', '1.0');
    // Same thresholds, sent as strings.
    ($this->put)(($this->withFactor)(6, ['bands' => [['threshold' => '3', 'points' => 10], ['threshold' => '2.00', 'points' => '7'], ['threshold' => '1.5', 'points' => 5], ['threshold' => 1, 'points' => 2]]]))
        ->assertOk()->assertJsonPath('data.version', '1.0');

    expect(CreditScorecardRevision::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audits);
});
