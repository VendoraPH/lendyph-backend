<?php

use App\Models\CreditScorecardRevision;
use App\Models\CreditScoringSetting;
use App\Services\CreditScoring\FactorTable;
use App\Services\CreditScoring\ScorecardV1;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

/**
 * Score = round(100 x earned / possible) over the factors with data: the
 * formula before category weights counted.
 *
 * @param  array<string, mixed>  $result
 */
function unweightedScore(array $result): ?int
{
    $factors = array_filter($result['factors'], fn (array $f) => $f['has_data']);
    $earned = array_sum(array_column($factors, 'points_earned'));
    $possible = array_sum(array_column($factors, 'points_possible'));

    return $possible === 0 ? null : intdiv(200 * $earned + $possible, 2 * $possible);
}

/**
 * A copy of revision 1.0 with other category weights, put in use.
 *
 * @param  array<string, int|float>  $weights  category key => weight
 */
function useRevisionWithWeights(array $weights, string $version = '1.1'): CreditScorecardRevision
{
    $current = CreditScoringSetting::current()->currentRevision;
    $revision = CreditScorecardRevision::query()->create([
        'version' => $version,
        'engine_version' => $current->engine_version,
        'categories' => array_map(fn (array $c) => ['weight_percent' => $weights[$c['key']]] + $c, $current->categories),
        'factors' => $current->factors,
        'risk_bands' => $current->risk_bands,
        'policy_rules' => $current->policy_rules,
        'validated' => false,
    ]);
    CreditScoringSetting::current()->update(['current_revision_id' => $revision->id]);

    return $revision;
}

it('weighs every v1 category by exactly 1: its factor points equal its weight', function () {
    $table = FactorTable::fromRevision(CreditScoringSetting::current()->currentRevision);

    foreach (ScorecardV1::categories() as $category) {
        expect($table->categoryFactorPoints($category['key']) * 100)->toBe($table->weightHundredths($category['key']), $category['key']);
    }
});

it('scores v1 members exactly as the unweighted formula does', function () {
    $thin = $this->member(['monthly_income' => 40000]);

    $history = $this->member(['monthly_income' => 30000, 'date_hired' => '2020-01-01']);
    $loan = $this->releasedLoan($history);
    $this->paidMonthly($loan, '2026-03-01', 5, 2);
    $this->paidMonthly($loan, '2026-08-01', 2, 12, 6);
    $this->period($loan, 8, '2026-10-01');

    $late = $this->member();
    $lateLoan = $this->releasedLoan($late, ['status' => 'defaulted']);
    $this->period($lateLoan, 1, '2026-06-01');
    $this->paidPeriod($lateLoan, 2, '2026-07-01', '2026-08-20');

    foreach ([$thin, $history, $late] as $member) {
        $result = $this->scoreOf($member);

        expect($result['score'])->not->toBeNull()
            ->and($result['score'])->toBe(unweightedScore($result));
    }
});

it('moves a member\'s score by the saved weights, to the figure worked out by hand', function () {
    $member = $this->member(['monthly_income' => 40000]);

    $v1 = $this->scoreOf($member);
    $categories = collect($v1['categories'])->keyBy('key');

    // Capacity 15 of 15, exposure 15 of 15, relationship 1 of 4 and
    // application 1 of 3: round(100 x 32 / 37) = 86.
    expect($categories->map(fn ($c) => [$c['has_data'], $c['points_earned'], $c['points_possible']])->all())->toBe([
        'repayment' => [false, 0, 0],
        'capacity' => [true, 15, 15],
        'exposure' => [true, 15, 15],
        'stability' => [false, 0, 0],
        'relationship' => [true, 1, 4],
        'application' => [true, 1, 3],
    ])->and($v1['score'])->toBe(86);

    useRevisionWithWeights(['repayment' => 30, 'capacity' => 25, 'exposure' => 15, 'stability' => 10, 'relationship' => 19.5, 'application' => 0.5]);

    // Relationship x 19.5/10 and application x 0.5/5:
    // earned 15 + 15 + 1.95 + 0.1 = 32.05, possible 15 + 15 + 7.8 + 0.3 = 38.1,
    // 100 x 32.05 / 38.1 = 84.12, so 84.
    $weighted = $this->scoreOf($member);

    expect($weighted['score'])->toBe(84)
        ->and($weighted['revision_version'])->toBe('1.1')
        ->and(collect($weighted['categories'])->firstWhere('key', 'relationship')['weight_percent'])->toBe(19.5);
});

it('rounds a weighted score half up, in exact arithmetic', function () {
    $member = $this->member(['monthly_income' => 40000]);

    // Capacity x 1 and exposure x 35/15, with relationship and application
    // weighing nothing: 50 of 50 is 100.
    useRevisionWithWeights(['repayment' => 30, 'capacity' => 25, 'exposure' => 35, 'stability' => 10, 'relationship' => 0, 'application' => 0]);
    expect($this->scoreOf($member)['score'])->toBe(100);

    // Relationship back at x 1: 51 of 54 is 94.44, so 94.
    useRevisionWithWeights(['repayment' => 20, 'capacity' => 25, 'exposure' => 35, 'stability' => 10, 'relationship' => 10, 'application' => 0], '1.2');
    expect($this->scoreOf($member)['score'])->toBe(94);

    // Exposure x 2/15 and relationship x 5/10 alone: 2.5 of 4 is exactly
    // 62.5, which rounds half up to 63.
    useRevisionWithWeights(['repayment' => 50, 'capacity' => 0, 'exposure' => 2, 'stability' => 43, 'relationship' => 5, 'application' => 0], '1.3');
    expect($this->scoreOf($member)['score'])->toBe(63);
});

it('reads a weight in hundredths without rounding it', function () {
    expect(FactorTable::hundredths('16.67'))->toBe(1667)
        ->and(FactorTable::hundredths(16.67))->toBe(1667)
        ->and(FactorTable::hundredths(35))->toBe(3500)
        ->and(FactorTable::hundredths('0.5'))->toBe(50)
        ->and(FactorTable::hundredths(0.1))->toBe(10);
});
