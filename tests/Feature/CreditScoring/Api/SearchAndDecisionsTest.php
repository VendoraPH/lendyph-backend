<?php

use App\Models\CreditDecision;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScore;
use App\Models\Loan;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Support\Facades\DB;
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

it('keeps a deleted draft\'s decisions out of another branch\'s member list: only nightly-score decisions are member-level', function () {
    $member = $this->scoredMember();
    $farDraft = $this->application($member, ['branch_id' => $this->farBranch()->id]);
    $assessment = CreditScore::query()->findOrFail(app(Recalculator::class)->assessLoan($farDraft, 'Assessment saved')['credit_score_id']);
    CreditDecision::query()->create([
        'borrower_id' => $member->id, 'loan_id' => $farDraft->id, 'credit_score_id' => $assessment->id, 'decision' => 'decline',
        'reason' => 'Far application', 'score_snapshot' => [], 'recommendation_snapshot' => 'Advice', 'revision_version' => '1.0',
        'decided_by' => $this->analyst->id, 'decided_at' => now(),
    ]);
    // A draft removed outside the API (the foreign key nulls the decision's loan).
    DB::table('loans')->where('id', $farDraft->id)->delete();
    expect(CreditDecision::query()->where('reason', 'Far application')->value('loan_id'))->toBeNull();

    $reasons = collect($this->actingAs($this->analyst)->getJson("/api/credit-scoring/borrowers/{$member->id}/decisions")->json('data.data'))->pluck('reason');

    expect($reasons)->not->toContain('Far application');
});

it('refuses to delete a draft loan that has credit decisions, in words', function () {
    $member = $this->scoredMember();
    $draft = $this->application($member);
    $assessment = CreditScore::query()->findOrFail(app(Recalculator::class)->assessLoan($draft, 'Assessment saved')['credit_score_id']);
    CreditDecision::query()->create([
        'borrower_id' => $member->id, 'loan_id' => $draft->id, 'credit_score_id' => $assessment->id, 'decision' => 'hold',
        'reason' => 'Waiting', 'score_snapshot' => [], 'recommendation_snapshot' => 'Advice', 'revision_version' => '1.0',
        'decided_by' => $this->analyst->id, 'decided_at' => now(),
    ]);

    $this->actingAs($this->caller(['loans:view', 'loans:void']))->deleteJson("/api/loans/{$draft->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This draft has credit decisions recorded against it, which are kept as a record, so it can\'t be deleted. Reject or void it instead.');

    expect(Loan::query()->whereKey($draft->id)->exists())->toBeTrue()
        ->and(CreditDecision::query()->where('loan_id', $draft->id)->count())->toBe(1);

    $clean = $this->application($member);
    $this->actingAs($this->caller(['loans:view', 'loans:void']))->deleteJson("/api/loans/{$clean->id}")->assertOk();
});

it('lists loan-linked decisions and loan flags only to a caller with loans:view', function () {
    $member = $this->scoredMember();
    $draft = $this->application($member);
    $assessment = CreditScore::query()->findOrFail(app(Recalculator::class)->assessLoan($draft, 'Assessment saved')['credit_score_id']);
    $nightly = CreditScore::query()->where('borrower_id', $member->id)->where('context', 'nightly')->sole();
    foreach ([[$draft->id, $assessment->id, 'On the application'], [null, $nightly->id, 'On the member']] as [$loanId, $scoreId, $reason]) {
        CreditDecision::query()->create([
            'borrower_id' => $member->id, 'loan_id' => $loanId, 'credit_score_id' => $scoreId, 'decision' => 'hold', 'reason' => $reason,
            'score_snapshot' => [], 'recommendation_snapshot' => 'Advice', 'revision_version' => '1.0',
            'decided_by' => $this->analyst->id, 'decided_at' => now(),
        ]);
    }
    expect(CreditPolicyFlag::query()->open()->where('loan_id', $draft->id)->exists())->toBeTrue();
    $noLoans = $this->caller(['credit_scoring:view', 'borrowers:view']);

    $reasons = fn ($caller) => collect($this->actingAs($caller)->getJson("/api/credit-scoring/borrowers/{$member->id}/decisions")->json('data.data'))->pluck('reason')->sort()->values()->all();
    $flagLoans = fn ($caller) => collect($this->actingAs($caller)->getJson("/api/credit-scoring/borrowers/{$member->id}/policy-flags")->json('data.data'))->pluck('loan_id')->filter()->values()->all();

    expect($reasons($noLoans))->toBe(['On the member'])
        ->and($reasons($this->analyst))->toBe(['On the application', 'On the member'])
        ->and($flagLoans($noLoans))->toBe([])
        ->and($flagLoans($this->analyst))->toContain($draft->id);
});
