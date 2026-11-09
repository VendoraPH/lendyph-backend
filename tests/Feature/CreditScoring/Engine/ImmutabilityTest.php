<?php

use App\Models\Borrower;
use App\Models\CreditDecision;
use App\Models\CreditScore;
use App\Models\CreditScorecardRevision;
use App\Models\CreditScoreMember;
use App\Models\User;
use App\Services\BorrowerPurgeService;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Database\QueryException;
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

it('refuses to delete a member who has a credit decision, rolling the whole purge back', function () {
    $member = $this->member(['monthly_income' => 20000]);
    $outcome = app(Recalculator::class)->scoreMember($member->id, 'Nightly review');
    CreditDecision::query()->create([
        'borrower_id' => $member->id,
        'credit_score_id' => $outcome['credit_score_id'],
        'decision' => 'hold',
        'reason' => 'Waiting for documents',
        'score_snapshot' => ['score' => $outcome['score']],
        'recommendation_snapshot' => 'Advice',
        'revision_version' => '1.0',
        'decided_by' => User::query()->value('id'),
        'decided_at' => now(),
    ]);

    expect(fn () => app(BorrowerPurgeService::class)->purge($member))->toThrow(QueryException::class)
        ->and(Borrower::query()->whereKey($member->id)->exists())->toBeTrue()
        ->and(CreditScore::query()->whereKey($outcome['credit_score_id'])->exists())->toBeTrue()
        ->and(CreditDecision::query()->where('borrower_id', $member->id)->count())->toBe(1);
});

it('deletes a member with scores but no decision, scores and all', function () {
    $member = $this->member(['monthly_income' => 20000]);
    app(Recalculator::class)->scoreMember($member->id, 'Nightly review');

    app(BorrowerPurgeService::class)->purge($member);

    expect(CreditScore::query()->where('borrower_id', $member->id)->exists())->toBeFalse()
        ->and(CreditScoreMember::query()->where('borrower_id', $member->id)->exists())->toBeFalse();
});

it('never prunes an abandoned registration that carries a credit decision', function () {
    $kept = $this->member(['status' => 'pending', 'approved_at' => null]);
    $pruned = $this->member(['status' => 'pending', 'approved_at' => null]);
    $outcome = app(Recalculator::class)->scoreMember($kept->id, 'Nightly review');
    CreditDecision::query()->create([
        'borrower_id' => $kept->id,
        'credit_score_id' => $outcome['credit_score_id'],
        'decision' => 'hold',
        'reason' => 'Waiting for documents',
        'score_snapshot' => ['score' => $outcome['score']],
        'recommendation_snapshot' => 'Advice',
        'revision_version' => '1.0',
        'decided_by' => User::query()->value('id'),
        'decided_at' => now(),
    ]);
    Borrower::query()->whereKey([$kept->id, $pruned->id])->toBase()->update(['updated_at' => now()->subDays(30)]);

    $this->artisan('registrations:prune')->assertSuccessful();

    expect(Borrower::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Borrower::query()->whereKey($pruned->id)->exists())->toBeFalse()
        ->and(CreditDecision::query()->where('borrower_id', $kept->id)->count())->toBe(1);
});
