<?php

use App\Models\AuditLog;
use App\Models\CreditScore;
use App\Models\CreditScorecardRevision;
use App\Models\CreditScoringSetting;
use App\Services\CreditScoring\Recalculator;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * PUT /credit-scoring/scorecard-config: the whole ScorecardConfig. A save
 * that changes something writes a new immutable revision and puts it in use;
 * nothing is recalculated in the request.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->admin = $this->caller(['credit_scoring:settings']);
    $this->config = $this->actingAs($this->admin)->getJson('/api/credit-scoring/scorecard-config')->assertOk()->json('data');
});

/**
 * The current config with other weights, by category key.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, int|float|string>  $weights
 * @return array<string, mixed>
 */
function withWeights(array $config, array $weights): array
{
    $config['categories'] = array_map(fn (array $c) => array_key_exists($c['key'], $weights) ? ['weight_percent' => $weights[$c['key']]] + $c : $c, $config['categories']);

    return $config;
}

it('refuses weights that total 99.99', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/credit-scoring/scorecard-config', withWeights($this->config, ['application' => 4.99]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('categories');

    expect(CreditScorecardRevision::query()->count())->toBe(1);
});

it('refuses a weight with more than two decimals, never rounding it', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/credit-scoring/scorecard-config', withWeights($this->config, ['repayment' => 34.995, 'application' => 5.005]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['categories.0.weight_percent']);
});

it('refuses a missing, extra or renamed category key', function (callable $change) {
    $config = $this->config;
    $config['categories'] = $change($config['categories']);

    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $config)
        ->assertUnprocessable()->assertJsonValidationErrors('categories');
})->with([
    'missing' => fn () => fn (array $c) => array_slice($c, 0, 5),
    'renamed' => fn () => fn (array $c) => [['key' => 'other'] + $c[0], ...array_slice($c, 1)],
    'twice' => fn () => fn (array $c) => [...array_slice($c, 0, 5), $c[0]],
]);

it('refuses changed factor or policy rules, and accepts them unchanged or absent', function () {
    $factors = $this->config;
    $factors['factor_rules'][0]['points'] = 20;
    $policies = $this->config;
    $policies['policy_rules'][1]['is_active'] = false;
    $bare = withWeights($this->config, ['repayment' => 30, 'stability' => 15]);
    unset($bare['factor_rules'], $bare['policy_rules']);

    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $factors)->assertUnprocessable()->assertJsonValidationErrors('factor_rules');
    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $policies)->assertUnprocessable()->assertJsonValidationErrors('policy_rules');
    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $bare)->assertOk()->assertJsonPath('data.version', '1.1');
});

it('saves decimals that total 100.00 as a new revision, leaving the old one untouched', function () {
    $old = CreditScorecardRevision::query()->sole();
    $before = $old->getRawOriginal();
    $member = $this->scoredMember();
    $oldScore = CreditScore::query()->where('borrower_id', $member->id)->sole();

    $saved = $this->actingAs($this->admin)
        ->putJson('/api/credit-scoring/scorecard-config', withWeights($this->config, ['repayment' => 33.33, 'capacity' => 26.67, 'stability' => 9.5, 'relationship' => 10.5]))
        ->assertOk()
        ->json('data');

    $new = CreditScorecardRevision::query()->where('version', '1.1')->sole();
    expect($saved['version'])->toBe('1.1')
        ->and($saved['validated'])->toBeFalse()
        ->and(collect($saved['categories'])->pluck('weight_percent', 'key')->all())->toEqual(['repayment' => 33.33, 'capacity' => 26.67, 'exposure' => 15, 'stability' => 9.5, 'relationship' => 10.5, 'application' => 5])
        ->and($new->validated)->toBeFalse()
        ->and($new->created_by)->toBe($this->admin->id)
        ->and($new->factors)->toBe($old->factors)
        ->and($new->policy_rules)->toBe($old->policy_rules)
        ->and(CreditScoringSetting::current()->current_revision_id)->toBe($new->id)
        ->and(CreditScorecardRevision::query()->findOrFail($old->id)->getRawOriginal())->toBe($before)
        ->and(AuditLog::query()->where('auditable_type', CreditScorecardRevision::class)->where('auditable_id', $new->id)->where('action', 'created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', CreditScoringSetting::class)->where('action', 'updated')->exists())->toBeTrue();

    // Nothing is recalculated in the request; the old score keeps its stamp.
    expect(CreditScore::query()->where('borrower_id', $member->id)->count())->toBe(1)
        ->and(CreditScore::query()->findOrFail($oldScore->id)->revision_version)->toBe('1.0');

    app(Recalculator::class)->scoreMember($member->id, 'Nightly review');
    expect(CreditScore::query()->where('borrower_id', $member->id)->latest('id')->first()->revision_version)->toBe('1.1');
});

it('writes nothing for an unchanged save', function () {
    $audits = AuditLog::query()->count();

    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $this->config)
        ->assertOk()->assertJsonPath('data.version', '1.0');

    expect(CreditScorecardRevision::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('saves changed risk bands, and refuses bands that are out of order or don\'t start at 0', function () {
    $bands = $this->config;
    $bands['risk_bands'] = array_map(fn (array $b) => $b['key'] === 'elevated' ? ['min_score' => 55] + $b : $b, $bands['risk_bands']);

    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $bands)
        ->assertOk()->assertJsonPath('data.risk_bands.3.min_score', 55);
    expect(collect(CreditScorecardRevision::query()->where('version', '1.1')->sole()->risk_bands)->firstWhere('key', 'elevated')['recommendation'])
        ->toBe('Additional assessment needed. Manual review recommended.');

    $unordered = $this->config;
    $unordered['risk_bands'][1]['min_score'] = 95;
    $floor = $this->config;
    $floor['risk_bands'][5]['min_score'] = 5;
    $missing = $this->config;
    $missing['risk_bands'] = array_slice($missing['risk_bands'], 0, 5);

    foreach ([$unordered, $floor, $missing] as $body) {
        $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', $body)
            ->assertUnprocessable()->assertJsonValidationErrors('risk_bands');
    }
});

it('takes the next minor version each save', function () {
    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', withWeights($this->config, ['repayment' => 30, 'stability' => 15]))->assertOk();
    $next = $this->actingAs($this->admin)->getJson('/api/credit-scoring/scorecard-config')->json('data');
    $this->actingAs($this->admin)->putJson('/api/credit-scoring/scorecard-config', withWeights($next, ['repayment' => 35, 'stability' => 10]))
        ->assertOk()->assertJsonPath('data.version', '1.2');

    expect(CreditScorecardRevision::query()->orderBy('id')->pluck('version')->all())->toBe(['1.0', '1.1', '1.2']);
});
