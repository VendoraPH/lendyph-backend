<?php

use App\Models\CreditDecision;
use App\Models\CreditScore;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Member Scores search (name and code, literal %, _ and \, branch-scoped)
 * and a member's credit decisions, paged, newest first.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->analyst = $this->branchAnalyst();
});

it('searches member scores by name and code, case-insensitive, with %, _ and \\ matched literally, in the caller\'s branches', function () {
    $underscore = $this->scoredMember(null, ['first_name' => 'Maria', 'last_name' => 'Dela_Cruz', 'middle_name' => null]);
    $plain = $this->scoredMember(null, ['first_name' => 'Mario', 'last_name' => 'DelaXCruz', 'middle_name' => null]);
    $percent = $this->scoredMember(null, ['first_name' => 'Ana', 'last_name' => 'Santos%Reyes', 'middle_name' => null]);
    $far = $this->scoredMember($this->farBranch(), ['first_name' => 'Maria', 'last_name' => 'Dela_Cruz', 'middle_name' => null]);
    $ids = fn (string $term) => collect($this->actingAs($this->analyst)->getJson('/api/credit-scoring/borrowers?search='.urlencode($term))->assertOk()->json('data.data'))
        ->pluck('borrower_id')->sort()->values()->all();

    expect($ids('dela_cruz'))->toBe([$underscore->id])
        ->and($ids('DELA'))->toBe(collect([$underscore->id, $plain->id])->sort()->values()->all())
        ->and($ids('maria dela_cruz'))->toBe([$underscore->id])
        ->and($ids('s%r'))->toBe([$percent->id])
        ->and($ids('%'))->toBe([$percent->id])
        ->and($ids('\\'))->toBe([])
        ->and($ids(strtolower($plain->borrower_code)))->toBe([$plain->id])
        ->and($ids('Dela_Cruz'))->not->toContain($far->id);

    $this->actingAs($this->analyst)->getJson('/api/credit-scoring/borrowers?search='.str_repeat('a', 101))->assertUnprocessable();
});

it('lists a member\'s decisions, newest first and paged, leaving out decisions on loans the caller can\'t see', function () {
    $member = $this->scoredMember();
    $score = CreditScore::query()->where('borrower_id', $member->id)->sole();
    $farLoan = $this->application($member, ['branch_id' => $this->farBranch()->id]);
    $decide = fn (string $reason, ?int $loanId, int $minutes) => CreditDecision::query()->create([
        'borrower_id' => $member->id, 'loan_id' => $loanId, 'credit_score_id' => $score->id, 'decision' => 'hold', 'reason' => $reason,
        'remarks' => "Remarks for {$reason}", 'score_snapshot' => [], 'recommendation_snapshot' => 'Advice', 'revision_version' => '1.0',
        'decided_by' => $this->analyst->id, 'decided_at' => now()->addMinutes($minutes),
    ]);
    $decide('First', null, 1);
    $decide('Second', null, 2);
    $decide('On the far loan', $farLoan->id, 3);
    $decide('Third', null, 4);

    $page = $this->actingAs($this->analyst)->getJson("/api/credit-scoring/borrowers/{$member->id}/decisions?per_page=2")->assertOk()->json('data');

    expect(collect($page['data'])->pluck('reason')->all())->toBe(['Third', 'Second'])
        ->and($page['total'])->toBe(3)
        ->and($page['data'][0]['remarks'])->toBe('Remarks for Third')
        ->and($page['data'][0]['decided_by'])->toBe($this->analyst->full_name)
        ->and($page['details_hidden'])->toBeFalse();

    $all = $this->actingAs($this->headOffice())->getJson("/api/credit-scoring/borrowers/{$member->id}/decisions")->json('data');
    expect($all['total'])->toBe(4);
});

it('redacts a member\'s decisions for a summary caller, and answers 404 and 403 like the other member routes', function () {
    $member = $this->scoredMember();
    $score = CreditScore::query()->where('borrower_id', $member->id)->sole();
    CreditDecision::query()->create([
        'borrower_id' => $member->id, 'credit_score_id' => $score->id, 'decision' => 'decline', 'reason' => 'Income could not be verified',
        'remarks' => 'Payslips missing.', 'score_snapshot' => [], 'recommendation_snapshot' => 'Advice', 'revision_version' => '1.0',
        'decided_by' => $this->analyst->id, 'decided_at' => now(),
    ]);

    $summary = $this->actingAs($this->caller(['credit_scoring:view']))->getJson("/api/credit-scoring/borrowers/{$member->id}/decisions")->assertOk()->json('data');

    expect($summary['details_hidden'])->toBeTrue()
        ->and($summary['data'][0])->not->toHaveKeys(['reason', 'remarks'])
        ->and($summary['data'][0]['decision'])->toBe('decline')
        ->and($summary['data'][0])->toHaveKeys(['decided_by', 'decided_at']);

    $far = $this->scoredMember($this->farBranch());
    $this->actingAs($this->analyst)->getJson("/api/credit-scoring/borrowers/{$far->id}/decisions")->assertNotFound();
    $this->actingAs($this->caller(['loans:view']))->getJson("/api/credit-scoring/borrowers/{$member->id}/decisions")->assertForbidden();
});
