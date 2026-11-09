<?php

use App\Models\CreditDecision;
use App\Models\CreditScore;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Support\Facades\Route;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * POST /credit-scoring/decisions: a person's decision beside the advisory
 * score. Append-only, tied to the member's own score, and it never touches
 * a loan.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->officer = $this->branchAnalyst();
    $this->subject = $this->scoredMember();
    $this->score = CreditScore::query()->where('borrower_id', $this->subject->id)->sole();
});

it('refuses another member\'s score', function () {
    $other = $this->scoredMember();
    $otherScore = CreditScore::query()->where('borrower_id', $other->id)->sole();

    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->subject->id, 'credit_score_id' => $otherScore->id, 'decision' => 'approve', 'reason' => 'Fine',
    ])->assertUnprocessable()->assertJsonValidationErrors('credit_score_id');

    expect(CreditDecision::query()->count())->toBe(0);
});

it('ties a decision on an assessment to that assessment\'s loan', function () {
    $loan = $this->application($this->subject);
    $other = $this->application($this->subject);
    $assessment = CreditScore::query()->findOrFail(app(Recalculator::class)->assessLoan($loan, 'Assessment saved')['credit_score_id']);
    $body = ['borrower_id' => $this->subject->id, 'credit_score_id' => $assessment->id, 'decision' => 'approve', 'reason' => 'Capacity is fine'];

    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', $body)
        ->assertUnprocessable()->assertJsonValidationErrors('loan_id');
    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', $body + ['loan_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('loan_id');

    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', $body + ['loan_id' => $loan->id])
        ->assertCreated()->assertJsonPath('data.loan_id', $loan->id);
});

it('refuses a loan of another member, or one in another branch, on a member score', function () {
    $stranger = $this->scoredMember();
    $farLoan = $this->application($this->subject, ['branch_id' => $this->farBranch()->id]);
    $body = ['borrower_id' => $this->subject->id, 'credit_score_id' => $this->score->id, 'decision' => 'hold', 'reason' => 'Waiting'];

    foreach ([$this->application($stranger)->id, $farLoan->id] as $loanId) {
        $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', $body + ['loan_id' => $loanId])
            ->assertUnprocessable()->assertJsonValidationErrors('loan_id');
    }
});

it('requires a reason, trimmed, of at most 255 characters, and one of the four decisions', function (array $change, string $field) {
    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', array_merge([
        'borrower_id' => $this->subject->id, 'credit_score_id' => $this->score->id, 'decision' => 'hold', 'reason' => 'Waiting',
    ], $change))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'blank reason' => [['reason' => '   '], 'reason'],
    'long reason' => [['reason' => str_repeat('a', 256)], 'reason'],
    'past tense' => [['decision' => 'approved'], 'decision'],
    'no score' => [['credit_score_id' => null], 'credit_score_id'],
]);

it('stores the snapshots and the name of the person who decided', function () {
    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->subject->id, 'credit_score_id' => $this->score->id, 'decision' => 'decline', 'reason' => 'Too much debt', 'remarks' => 'Spoke to the member.',
    ])->assertCreated()->assertJsonPath('data.decided_by', $this->officer->full_name)->assertJsonPath('data.remarks', 'Spoke to the member.');

    $decision = CreditDecision::query()->sole();
    expect($decision->decided_by)->toBe($this->officer->id)
        ->and($decision->recommendation_snapshot)->toBe($this->score->recommendation)
        ->and($decision->revision_version)->toBe('1.0')
        ->and($decision->score_snapshot)->toMatchArray(['score' => $this->score->score, 'risk_level' => $this->score->risk_level, 'confidence' => $this->score->confidence]);
});

it('has no update or delete route', function () {
    $decisionRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'credit-scoring/decisions'))
        ->flatMap(fn ($route) => array_diff($route->methods(), ['HEAD']))
        ->values()
        ->all();

    expect($decisionRoutes)->toBe(['POST']);
});

it('leaves loans, schedules, approvals, payments and members identical through every write endpoint', function () {
    $loan = $this->application($this->subject);
    $settingsUser = $this->caller(['credit_scoring:settings']);
    $config = $this->actingAs($settingsUser)->getJson('/api/credit-scoring/scorecard-config')->json('data');
    $config['categories'][0]['weight_percent'] = 30;
    $config['categories'][3]['weight_percent'] = 15;
    $before = $this->lendingSnapshot();

    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->subject->id, 'credit_score_id' => $this->score->id, 'decision' => 'approve', 'reason' => 'Fine',
    ])->assertCreated();
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", [
        'household_expenses' => 15000, 'notice_acknowledged' => true,
    ])->assertOk();
    $this->actingAs($settingsUser)->putJson('/api/credit-scoring/scorecard-config', $config)->assertOk();
    $this->actingAs($settingsUser)->putJson('/api/credit-scoring/settings', ['privacy_notice' => 'Changed notice.'])->assertOk();

    expect($this->lendingSnapshot())->toBe($before);
});
