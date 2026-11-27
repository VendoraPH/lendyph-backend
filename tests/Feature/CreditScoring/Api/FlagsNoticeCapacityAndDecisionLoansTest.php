<?php

use App\Models\CreditPolicyFlag;
use App\Models\CreditScore;
use App\Models\CreditScoringSetting;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Support\Carbon;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Follow-up round 2, credit scoring:
 * - policy flags are kept per rule and loan, so two open flags sharing a rule
 *   key are never dropped, and a deleted draft's flags are closed, never
 *   turned into member flags (Lead's call 11);
 * - the notice flag honours the notice version;
 * - an assessment gets a new row when its capacity or the data it used
 *   changed, not only its score;
 * - a decision that names a loan carries the loan's number, behind the
 *   existing `loans:view` and branch gates (Lead's call 10).
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->officer = $this->branchAnalyst();
    $this->subject = $this->scoredMember();
});

function openPolicyFlags(int $borrowerId, string $rule, ?int $loanId = null)
{
    return CreditPolicyFlag::query()->open()->where('borrower_id', $borrowerId)->where('rule_key', $rule)
        ->when($loanId === null, fn ($q) => $q->whereNull('loan_id'), fn ($q) => $q->where('loan_id', $loanId));
}

function strayPolicyFlag(int $borrowerId, ?int $loanId, string $rule, string $at): CreditPolicyFlag
{
    return CreditPolicyFlag::query()->create([
        'borrower_id' => $borrowerId,
        'loan_id' => $loanId,
        'rule_key' => $rule,
        'type' => CreditPolicyFlag::SOFT,
        'label' => 'Stray copy',
        'detail' => 'A copy left open.',
        'first_triggered_at' => $at,
        'last_seen_at' => $at,
    ]);
}

it('clears every open flag sharing a rule key once the condition is gone', function () {
    strayPolicyFlag($this->subject->id, null, 'past_due', '2026-11-01 09:00:00');
    strayPolicyFlag($this->subject->id, null, 'past_due', '2026-11-02 09:00:00');

    $this->scoreNow($this->subject);

    expect(openPolicyFlags($this->subject->id, 'past_due')->count())->toBe(0);
});

it('keeps one open flag per rule and loan while the condition holds, the earliest', function () {
    $loan = $this->application($this->subject);
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 9000])->assertOk();
    $first = openPolicyFlags($this->subject->id, 'notice_not_recorded', $loan->id)->sole();
    $copy = strayPolicyFlag($this->subject->id, $loan->id, 'notice_not_recorded', '2026-11-08 09:30:00');

    app(Recalculator::class)->assessLoan($loan->fresh(), 'Nightly review');

    expect(openPolicyFlags($this->subject->id, 'notice_not_recorded', $loan->id)->pluck('id')->all())->toBe([$first->id])
        ->and($copy->fresh()->cleared_at)->not->toBeNull();
});

it('closes a deleted draft\'s flags and never turns them into member flags', function () {
    $loan = $this->application($this->subject);
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 9000])->assertOk();
    $flag = openPolicyFlags($this->subject->id, 'notice_not_recorded', $loan->id)->sole();

    Carbon::setTestNow('2026-11-08 10:00:00');
    $this->actingAs($this->caller(['loans:view', 'loans:void']))->deleteJson("/api/loans/{$loan->id}")->assertOk();

    expect($flag->fresh()->cleared_at?->toDateTimeString())->toBe('2026-11-08 10:00:00')
        ->and(CreditPolicyFlag::query()->open()->where('borrower_id', $this->subject->id)->where('rule_key', 'notice_not_recorded')->exists())->toBeFalse();

    $listed = $this->actingAs($this->officer)->getJson("/api/credit-scoring/borrowers/{$this->subject->id}/policy-flags")->assertOk()->json('data');
    expect(collect($listed)->pluck('id')->all())->not->toContain($flag->id);

    $this->scoreNow($this->subject);
    expect($flag->fresh()->cleared_at?->toDateTimeString())->toBe('2026-11-08 10:00:00');
});

it('raises the notice flag again when the member acknowledged an earlier notice', function () {
    $loan = $this->application($this->subject);
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", [
        'household_expenses' => 9000, 'notice_acknowledged' => true,
    ])->assertOk();
    expect(openPolicyFlags($this->subject->id, 'notice_not_recorded', $loan->id)->exists())->toBeFalse();

    CreditScoringSetting::current()->update(['privacy_notice' => 'A revised credit assessment notice.', 'notice_version' => '1.1']);
    app(Recalculator::class)->assessLoan($loan->fresh(), 'Nightly review');

    $flag = openPolicyFlags($this->subject->id, 'notice_not_recorded', $loan->id)->sole();
    expect($flag->detail)->toContain('earlier version');

    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['notice_acknowledged' => true])->assertOk();
    expect(openPolicyFlags($this->subject->id, 'notice_not_recorded', $loan->id)->exists())->toBeFalse();
});

it('writes a new assessment row when only the capacity changed', function () {
    $loan = $this->application($this->subject);
    $first = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 15000])
        ->assertOk()->json('data');
    $second = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 15001])
        ->assertOk()->json('data');

    expect($second['score']['score'])->toBe($first['score']['score'])
        ->and($second['score']['id'])->not->toBe($first['score']['id'])
        ->and($second['capacity']['household_expenses'])->toBe('15001.00')
        ->and($second['score']['data_used']['capacity']['household_expenses'])->toBe('15001.00');
});

it('writes no new assessment row the next day when nothing it used changed', function () {
    $loan = $this->application($this->subject);
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 15000])->assertOk();
    $rows = CreditScore::query()->where('loan_id', $loan->id)->count();

    $this->pinToday('2026-11-09 09:00:00');
    $outcome = app(Recalculator::class)->assessLoan($loan->fresh(), 'Nightly review');

    expect($outcome['changed'])->toBeFalse()
        ->and(CreditScore::query()->where('loan_id', $loan->id)->count())->toBe($rows);
});

it('names a decision\'s loan by its number, in the save response, the list and the assessment', function () {
    $draft = $this->application($this->subject);
    $assessment = app(Recalculator::class)->assessLoan($draft, 'Assessment saved')['credit_score_id'];

    $saved = $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->subject->id, 'credit_score_id' => $assessment, 'loan_id' => $draft->id,
        'decision' => 'approve', 'reason' => 'Capacity is fine',
    ])->assertCreated()->json('data');

    expect($draft->loan_account_number)->toBeNull()
        ->and($saved['loan_account_number'])->toBe($draft->application_number);

    $released = $this->releasedLoan($this->subject, ['loan_account_number' => 'LN-000777']);
    $memberScore = CreditScore::query()->where('borrower_id', $this->subject->id)->where('context', CreditScore::CONTEXT_NIGHTLY)->latest('id')->value('id');
    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->subject->id, 'credit_score_id' => $memberScore, 'loan_id' => $released->id,
        'decision' => 'hold', 'reason' => 'Waiting',
    ])->assertCreated()->assertJsonPath('data.loan_account_number', 'LN-000777');
    $this->actingAs($this->officer)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->subject->id, 'credit_score_id' => $memberScore, 'decision' => 'hold', 'reason' => 'No loan',
    ])->assertCreated()->assertJsonPath('data.loan_account_number', null);

    $listed = collect($this->actingAs($this->officer)->getJson("/api/credit-scoring/borrowers/{$this->subject->id}/decisions")->assertOk()->json('data.data'))
        ->pluck('loan_account_number', 'reason')->all();
    expect($listed)->toBe(['No loan' => null, 'Waiting' => 'LN-000777', 'Capacity is fine' => $draft->application_number]);

    $this->actingAs($this->officer)->getJson("/api/credit-scoring/loans/{$draft->id}/assessment")->assertOk()
        ->assertJsonPath('data.decisions.0.loan_account_number', $draft->application_number);
});

it('leaves a decision\'s loan number out for a caller without loans:view', function () {
    $draft = $this->application($this->subject);
    $memberScore = CreditScore::query()->where('borrower_id', $this->subject->id)->latest('id')->value('id');
    $noLoans = $this->caller(['credit_scoring:view', 'credit_scoring:override', 'borrowers:view']);

    $saved = $this->actingAs($noLoans)->postJson('/api/credit-scoring/decisions', [
        'borrower_id' => $this->subject->id, 'credit_score_id' => $memberScore, 'loan_id' => $draft->id,
        'decision' => 'hold', 'reason' => 'Waiting',
    ])->assertCreated()->json('data');

    expect($saved)->toHaveKey('loan_account_number')
        ->and($saved['loan_id'])->toBe($draft->id)
        ->and($saved['loan_account_number'])->toBeNull();
});
