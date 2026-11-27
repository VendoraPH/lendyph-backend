<?php

use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Services\CreditScoring\Recalculator;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

it('writes a history row only when the score, band, confidence or revision changes', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member);
    $this->paidMonthly($loan, '2026-06-01', 4);
    $open = $this->period($loan, 5, '2026-12-01');
    $recalculator = app(Recalculator::class);

    $first = $recalculator->scoreMember($member->id, 'Nightly review');
    $this->travel(1)->hours();
    $second = $recalculator->scoreMember($member->id, 'Nightly review');

    expect($first['changed'])->toBeTrue()
        ->and($second['changed'])->toBeFalse()
        ->and(CreditScore::query()->where('borrower_id', $member->id)->count())->toBe(1);

    $memberRow = CreditScoreMember::query()->where('borrower_id', $member->id)->firstOrFail();
    expect($memberRow->checked_at->gt($memberRow->calculated_at))->toBeTrue();

    $open->update(['due_date' => '2026-09-20']);
    $third = $recalculator->scoreMember($member->id, 'Schedule changed');

    $rows = CreditScore::query()->where('borrower_id', $member->id)->orderBy('id')->get();
    $memberRow->refresh();

    expect($third['changed'])->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and($rows[1]->previous_score)->toBe($rows[0]->score)
        ->and($rows[1]->recalculation_reason)->toBe('Schedule changed')
        ->and($rows[1]->revision_version)->toBe('1.0')
        ->and($memberRow->credit_score_id)->toBe($rows[1]->id)
        ->and($memberRow->previous_credit_score_id)->toBe($rows[0]->id)
        ->and($memberRow->trend)->toBe('down')
        ->and($memberRow->past_due)->toBeTrue();
});

it('writes an alert for a drop of 10 points or more', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member);
    $this->paidMonthly($loan, '2026-04-01', 6);
    $open = $this->period($loan, 7, '2026-12-01');
    $recalculator = app(Recalculator::class);

    $before = $recalculator->scoreMember($member->id, 'Nightly review');
    $open->update(['due_date' => '2026-09-20']);
    $after = $recalculator->scoreMember($member->id, 'Schedule changed');

    $alert = CreditRiskAlert::query()->where('borrower_id', $member->id)->sole();

    expect($before['score'] - $after['score'])->toBeGreaterThanOrEqual(10)
        ->and($alert->kind)->toBe('score_decline')
        ->and($alert->previous_score)->toBe($before['score'])
        ->and($alert->current_score)->toBe($after['score'])
        ->and($alert->message)->toContain("decreased from {$before['score']} to {$after['score']}");
});

it('writes no alert for a small move', function () {
    $member = $this->member(['monthly_income' => 40000]);
    $loan = $this->releasedLoan($member);
    $this->paidMonthly($loan, '2026-04-01', 6);
    $recalculator = app(Recalculator::class);

    $recalculator->scoreMember($member->id, 'Nightly review');
    $member->update(['contact_number' => 'not a number']);
    $after = $recalculator->scoreMember($member->id, 'Member details changed');

    expect($after['changed'])->toBeTrue()
        ->and(CreditRiskAlert::query()->where('borrower_id', $member->id)->count())->toBe(0);
});
