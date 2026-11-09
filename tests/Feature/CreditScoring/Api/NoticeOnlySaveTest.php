<?php

use App\Models\CreditApplicationProfile;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScoreMember;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * A save that records only the notice, or nothing, never hides a member's
 * earlier application details: scoring reads the newest profile that holds
 * at least one detail, and the notice is read from the application's own
 * profile.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->officer = $this->branchAnalyst();
    $this->subject = $this->scoredMember();
    $this->earlier = $this->application($this->subject);
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$this->earlier->id}/assessment", [
        'household_expenses' => 15000, 'outside_debt_payments' => 2000,
    ])->assertOk();
    $this->earlierProfile = CreditApplicationProfile::query()->where('loan_id', $this->earlier->id)->sole();
    $this->later = $this->application($this->subject);
});

it('writes no profile for an empty first save', function () {
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$this->later->id}/assessment", [])->assertOk();

    expect(CreditApplicationProfile::query()->where('loan_id', $this->later->id)->exists())->toBeFalse();
});

it('keeps scoring the member on their earlier details after a notice-only save on a newer application', function () {
    $assessment = $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$this->later->id}/assessment", ['notice_acknowledged' => true])
        ->assertOk()->json('data');

    expect(CreditApplicationProfile::query()->where('loan_id', $this->later->id)->sole()->notice_acknowledged_at)->not->toBeNull()
        ->and($assessment['score']['data_used']['application_profile_id'])->toBe($this->earlierProfile->id)
        ->and($assessment['capacity']['household_expenses'])->toBe('15000.00')
        ->and(CreditPolicyFlag::query()->open()->where('loan_id', $this->later->id)->where('rule_key', 'notice_not_recorded')->exists())->toBeFalse();

    // The member's own score reads the same details, and its flags record them.
    $this->scoreNow($this->subject);
    $member = $this->scoreOf($this->subject);

    expect($member['data_used']['application_profile_id'])->toBe($this->earlierProfile->id)
        ->and($member['capacity']['household_expenses'])->toBe('15000.00')
        ->and(CreditScoreMember::query()->where('borrower_id', $this->subject->id)->value('flags_profile_id'))->toBe($this->earlierProfile->id);
});

it('still raises the notice flag on an application whose own profile has details but no acknowledgement', function () {
    $this->actingAs($this->officer)->putJson("/api/credit-scoring/loans/{$this->later->id}/assessment", ['household_expenses' => 9000])->assertOk();

    expect(CreditPolicyFlag::query()->open()->where('loan_id', $this->later->id)->where('rule_key', 'notice_not_recorded')->exists())->toBeTrue();
});
