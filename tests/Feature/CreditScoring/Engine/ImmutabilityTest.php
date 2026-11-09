<?php

use App\Models\CreditDecision;
use App\Models\CreditScore;
use App\Models\CreditScorecardRevision;
use App\Models\User;
use App\Services\CreditScoring\Recalculator;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

it('refuses to update a scorecard revision', function () {
    $revision = CreditScorecardRevision::query()->where('version', '1.0')->firstOrFail();

    $revision->update(['notes' => 'changed']);
})->throws(LogicException::class, 'immutable');

it('refuses to delete a scorecard revision', function () {
    CreditScorecardRevision::query()->where('version', '1.0')->firstOrFail()->delete();
})->throws(LogicException::class, 'immutable');

it('keeps the stored revision exactly as written after a refused update', function () {
    $revision = CreditScorecardRevision::query()->where('version', '1.0')->firstOrFail();
    $before = $revision->getRawOriginal();

    try {
        $revision->update(['validated' => true]);
    } catch (LogicException) {
    }

    expect(CreditScorecardRevision::query()->findOrFail($revision->id)->getRawOriginal())->toBe($before);
});

it('refuses to update or delete a credit score, and a decision', function () {
    $member = $this->member(['monthly_income' => 20000]);
    $outcome = app(Recalculator::class)->scoreMember($member->id, 'Nightly review');
    $score = CreditScore::query()->findOrFail($outcome['credit_score_id']);

    expect(fn () => $score->update(['score' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $score->delete())->toThrow(LogicException::class);

    $decision = CreditDecision::query()->create([
        'borrower_id' => $member->id,
        'credit_score_id' => $score->id,
        'decision' => 'refer',
        'reason' => 'Needs income documents',
        'score_snapshot' => ['score' => $score->score],
        'recommendation_snapshot' => $score->recommendation,
        'revision_version' => $score->revision_version,
        'decided_by' => User::query()->value('id'),
        'decided_at' => now(),
    ]);

    expect(fn () => $decision->update(['decision' => 'approve']))->toThrow(LogicException::class)
        ->and(fn () => $decision->delete())->toThrow(LogicException::class)
        ->and(CreditScore::query()->findOrFail($score->id)->score)->toBe($outcome['score']);
});
