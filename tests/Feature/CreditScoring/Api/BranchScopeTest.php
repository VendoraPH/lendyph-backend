<?php

use App\Models\Borrower;
use App\Models\CreditPolicyFlag;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Branch scope and least privilege (rounds m6 and fz): a member or loan in
 * a branch the caller can't see answers 404, a list leaves them out, and a
 * score's data drops the loan and branch ids the caller can't see.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->near = $this->scoredMember();
    $this->far = $this->scoredMember($this->farBranch());
    $this->analyst = $this->branchAnalyst();
});

it('answers 404 for a member in another branch, on the profile, history, flags and a decision', function () {
    $this->actingAs($this->analyst);
    $farScore = CreditScore::query()->where('borrower_id', $this->far->id)->sole();

    $this->getJson("/api/credit-scoring/borrowers/{$this->far->id}")->assertNotFound();
    $this->getJson("/api/credit-scoring/borrowers/{$this->far->id}/history")->assertNotFound();
    $this->getJson("/api/credit-scoring/borrowers/{$this->far->id}/policy-flags")->assertNotFound();
    $this->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->far->id, 'credit_score_id' => $farScore->id, 'decision' => 'hold', 'reason' => 'Check',
    ])->assertNotFound();

    $this->getJson("/api/credit-scoring/borrowers/{$this->near->id}")->assertOk();
});

it('answers 404 for a loan in another branch, on the assessment', function () {
    $farLoan = $this->application($this->far);

    $this->actingAs($this->analyst)->getJson("/api/credit-scoring/loans/{$farLoan->id}/assessment")->assertNotFound();
    $this->actingAs($this->analyst)->putJson("/api/credit-scoring/loans/{$farLoan->id}/assessment", [])->assertNotFound();
});

it('answers 403 to a branch filter outside the caller\'s branches', function (string $uri) {
    $this->actingAs($this->analyst)->getJson("{$uri}?branch_id={$this->farBranch()->id}")->assertForbidden();
    $this->actingAs($this->analyst)->getJson("{$uri}?branch_id={$this->mainBranch()->id}")->assertOk();
})->with(['/api/credit-scoring/borrowers', '/api/credit-scoring/score-history']);

it('refuses a score-history member filter outside the caller\'s branches', function () {
    $this->actingAs($this->analyst)->getJson("/api/credit-scoring/score-history?borrower_id={$this->far->id}")->assertUnprocessable();
    $this->actingAs($this->analyst)->getJson("/api/credit-scoring/score-history?borrower_id={$this->near->id}")->assertOk();
});

it('leaves other branches out of the lists, counts, risk monitoring and alerts', function () {
    foreach ([$this->near, $this->far] as $member) {
        $score = CreditScore::query()->where('borrower_id', $member->id)->sole();
        CreditRiskAlert::query()->create([
            'borrower_id' => $member->id, 'credit_score_id' => $score->id, 'kind' => 'high_risk', 'previous_score' => null,
            'current_score' => 30, 'previous_risk_level' => null, 'current_risk_level' => 'very_high', 'severity' => 'critical',
            'message' => "{$member->full_name}'s credit score, 30, is in a high risk band.",
        ]);
        CreditPolicyFlag::query()->create([
            'borrower_id' => $member->id, 'rule_key' => 'existing_default', 'type' => 'hard_flag', 'label' => 'Defaulted loan',
            'detail' => 'The member has 1 defaulted loan.', 'first_triggered_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);
    }

    $this->actingAs($this->analyst);
    $ids = fn (string $uri, string $path) => collect($this->getJson($uri)->assertOk()->json($path))->pluck('borrower_id')->unique()->values()->all();

    expect($ids('/api/credit-scoring/borrowers', 'data.data'))->toBe([$this->near->id])
        ->and($ids('/api/credit-scoring/score-history', 'data.data'))->toBe([$this->near->id])
        ->and($ids('/api/credit-scoring/dashboard', 'data.recent_score_changes'))->toBe([$this->near->id])
        ->and($ids('/api/credit-scoring/risk-monitoring', 'data.alerts'))->toBe([$this->near->id])
        ->and($ids('/api/credit-scoring/risk-monitoring', 'data.affected_borrowers'))->toBe([$this->near->id]);

    $dashboard = $this->getJson('/api/credit-scoring/dashboard')->json('data');
    $risk = $this->getJson('/api/credit-scoring/risk-monitoring')->json('data');

    expect($dashboard['total_scored_borrowers'])->toBe(1)
        ->and(collect($dashboard['risk_distribution'])->sum('count'))->toBe(1)
        ->and($risk['summary']['total_borrowers'])->toBe(1)
        ->and($risk['summary']['new_hard_flags_30d'])->toBe(1)
        ->and($risk['affected_total'])->toBe(1);

    $everything = $this->actingAs($this->headOffice())->getJson('/api/credit-scoring/risk-monitoring')->json('data');
    expect($everything['summary']['total_borrowers'])->toBe(2)
        ->and($everything['affected_total'])->toBe(2)
        ->and($everything['alerts'])->toHaveCount(2);
});

it('reads the member\'s current branch, not the one stored with the score', function () {
    Borrower::query()->whereKey($this->far->id)->update(['branch_id' => $this->mainBranch()->id]);

    $ids = collect($this->actingAs($this->analyst)->getJson('/api/credit-scoring/borrowers')->json('data.data'))->pluck('borrower_id')->sort()->values()->all();

    expect($ids)->toBe(collect([$this->near->id, $this->far->id])->sort()->values()->all());
});

it('drops the loan and branch ids the caller can\'t see from the score\'s data, and counts them', function () {
    $farLoan = $this->releasedLoan($this->near, ['branch_id' => $this->farBranch()->id]);
    $this->paidMonthly($farLoan, '2026-07-01', 2, 0, 10);
    $this->near->update(['monthly_income' => 39000]);
    $this->scoreNow($this->near);
    $stored = CreditScore::query()->where('borrower_id', $this->near->id)->latest('id')->first()->data_used;
    expect($stored['loan_ids'])->toContain($farLoan->id)
        ->and($stored['branch_ids'])->toContain($this->farBranch()->id);

    $scoped = $this->actingAs($this->analyst)->getJson("/api/credit-scoring/borrowers/{$this->near->id}")->assertOk()->json('data.data_used');

    expect($scoped['loan_ids'])->not->toContain($farLoan->id)
        ->and($scoped['loan_ids'])->toHaveCount(count($stored['loan_ids']) - 1)
        ->and($scoped['branch_ids'])->toBe([$this->mainBranch()->id])
        ->and($scoped['other_branch_loans'])->toBe(1)
        ->and($scoped['active_loans'])->toBe($stored['active_loans'])
        ->and($scoped['outstanding'])->toBe($stored['outstanding']);

    $whole = $this->actingAs($this->headOffice())->getJson("/api/credit-scoring/borrowers/{$this->near->id}")->json('data.data_used');
    expect($whole['loan_ids'])->toBe($stored['loan_ids'])
        ->and($whole['other_branch_loans'])->toBe(0);
});

it('reads a possible duplicate generically without branches:view_all', function () {
    $twin = $this->member(['branch_id' => $this->farBranch()->id, 'first_name' => $this->near->first_name, 'middle_name' => $this->near->middle_name, 'last_name' => $this->near->last_name]);
    $this->near->update(['monthly_income' => 38000]);
    $this->scoreNow($this->near);
    $flag = CreditPolicyFlag::query()->where('borrower_id', $this->near->id)->where('rule_key', 'possible_duplicate')->sole();
    expect($flag->detail)->toContain($twin->borrower_code);

    $branchView = collect($this->actingAs($this->analyst)->getJson("/api/credit-scoring/borrowers/{$this->near->id}/policy-flags")->json('data'))->firstWhere('id', $flag->id);
    $headView = collect($this->actingAs($this->headOffice())->getJson("/api/credit-scoring/borrowers/{$this->near->id}/policy-flags")->json('data'))->firstWhere('id', $flag->id);

    expect($branchView['detail'])->toBe('Another member record has the same name.')
        ->and($branchView['detail'])->not->toContain($twin->borrower_code)
        ->and($headView['detail'])->toContain($twin->borrower_code);
});

it('names members by id, name and code only', function () {
    $this->actingAs($this->analyst);
    $payloads = json_encode([
        $this->getJson('/api/credit-scoring/borrowers')->json(),
        $this->getJson('/api/credit-scoring/score-history')->json(),
        $this->getJson("/api/credit-scoring/borrowers/{$this->near->id}")->json(),
        $this->getJson('/api/credit-scoring/risk-monitoring')->json(),
    ]);

    expect($payloads)->not->toContain($this->near->contact_number)
        ->and($payloads)->not->toContain((string) $this->near->email);
});
