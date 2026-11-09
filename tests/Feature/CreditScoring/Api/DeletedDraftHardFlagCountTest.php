<?php

use App\Models\CreditPolicyFlag;
use Illuminate\Support\Carbon;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Follow-up round 3, Lead's call 8: "New hard flags in 30 days" leaves out the
 * flags closed because their draft loan was deleted. Deleting the draft nulls
 * their loan, so without the mark they would count as the member's own.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->subject = $this->scoredMember();
    $this->viewer = $this->branchAnalyst();
});

function hardFlagFor(int $borrowerId, ?int $loanId, string $rule): CreditPolicyFlag
{
    return CreditPolicyFlag::query()->create([
        'borrower_id' => $borrowerId,
        'loan_id' => $loanId,
        'rule_key' => $rule,
        'type' => CreditPolicyFlag::HARD,
        'label' => 'Hard flag',
        'detail' => 'A hard flag.',
        'first_triggered_at' => now()->subDay(),
        'last_seen_at' => now()->subDay(),
    ]);
}

function newHardFlags30d(object $test): ?int
{
    return $test->getJson('/api/credit-scoring/risk-monitoring')->assertOk()->json('data.summary.new_hard_flags_30d');
}

it('leaves a deleted draft\'s hard flag out of the new hard flags count', function () {
    $draft = $this->application($this->subject);
    $draftFlag = hardFlagFor($this->subject->id, $draft->id, 'debt_service_above_caution');
    $memberFlag = hardFlagFor($this->subject->id, null, 'existing_default');

    $this->actingAs($this->viewer);
    expect(newHardFlags30d($this))->toBe(2);

    Carbon::setTestNow(now()->addHour());
    $this->actingAs($this->caller(['loans:view', 'loans:void']))->deleteJson("/api/loans/{$draft->id}")->assertOk();

    $draftFlag->refresh();
    expect($draftFlag->loan_id)->toBeNull()
        ->and($draftFlag->cleared_at)->not->toBeNull()
        ->and($draftFlag->cleared_reason)->toBe(CreditPolicyFlag::CLEARED_LOAN_DELETED);

    $this->actingAs($this->viewer);
    expect(newHardFlags30d($this))->toBe(1);

    // A member flag that simply stopped holding still counts: it was new.
    $memberFlag->update(['cleared_at' => now()]);
    expect(newHardFlags30d($this))->toBe(1);
});
