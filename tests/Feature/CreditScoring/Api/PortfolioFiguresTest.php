<?php

use App\Models\Borrower;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScoreMember;
use App\Models\Loan;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * The dashboard's Portfolio Risk by Branch, High Risk Outstanding and Scores
 * Due for Review, and Risk Monitoring's Past Due Members and High Risk
 * Exposure (task 86d4ayrmt sections 12 and 22). Each covers the members and
 * loans the caller may see; the ₱ figures and the past due count are loan
 * facts and read null without `loans:view`.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();

    // Sets a member's current score row directly, so each test states the
    // level, score and previous score it counts.
    $this->level = function (Borrower $member, string $level, int $score, ?int $previous = null): void {
        CreditScoreMember::query()->where('borrower_id', $member->id)->update([
            'risk_level' => $level, 'score' => $score, 'previous_score' => $previous,
        ]);
    };

    // The member's ongoing loan from scoredMember().
    $this->loanOf = fn (Borrower $member): Loan => Loan::query()->where('borrower_id', $member->id)->where('status', 'ongoing')->firstOrFail();

    $this->hardFlag = fn (Borrower $member) => CreditPolicyFlag::query()->create([
        'borrower_id' => $member->id, 'rule_key' => 'existing_default', 'type' => CreditPolicyFlag::HARD, 'label' => 'Defaulted loan',
        'detail' => 'The member has a defaulted loan.', 'first_triggered_at' => now()->subDays(40), 'last_seen_at' => now(),
    ]);
});

it('lists portfolio risk by the member\'s branch, within the caller\'s branches', function () {
    $a = $this->scoredMember();
    $b = $this->scoredMember();
    $far = $this->scoredMember($this->farBranch());
    ($this->level)($a, 'low', 84);
    ($this->level)($b, 'high', 45);
    ($this->level)($far, 'moderate', 72);
    $unscored = $this->member(['branch_id' => $this->mainBranch()->id]);
    $this->scoreNow($unscored);

    $headOffice = $this->actingAs($this->headOffice())->getJson('/api/credit-scoring/dashboard')->assertOk()->json('data.branch_risk');

    expect($headOffice)->toHaveCount(2);
    $main = collect($headOffice)->firstWhere('branch_id', $this->mainBranch()->id);
    expect(array_keys($main))->toBe(['branch_id', 'branch_name', 'members_scored', 'average_score', 'risk_distribution'])
        ->and($main['branch_name'])->toBe($this->mainBranch()->name)
        ->and($main['members_scored'])->toBe(2)
        ->and($main['average_score'])->toEqual(64.5)
        ->and(collect($main['risk_distribution'])->pluck('count', 'risk_level')->all())
        ->toBe(['very_low' => 0, 'low' => 1, 'moderate' => 0, 'elevated' => 0, 'high' => 1, 'very_high' => 0])
        ->and(collect($headOffice)->firstWhere('branch_id', $this->farBranch()->id)['members_scored'])->toBe(1);

    $branch = $this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/dashboard')->assertOk()->json('data.branch_risk');
    expect(collect($branch)->pluck('branch_id')->all())->toBe([$this->mainBranch()->id]);

    $farOnly = $this->actingAs($this->caller(['credit_scoring:view'], $this->farBranch()))->getJson('/api/credit-scoring/dashboard')->json('data.branch_risk');
    expect(collect($farOnly)->pluck('branch_id')->all())->toBe([$this->farBranch()->id]);
});

it('sums what visible collectible loans of high and very high risk members still owe', function () {
    $high = $this->scoredMember();
    $veryHigh = $this->scoredMember();
    $low = $this->scoredMember();
    $far = $this->scoredMember($this->farBranch());
    ($this->level)($high, 'high', 45);
    ($this->level)($veryHigh, 'very_high', 30);
    ($this->level)($low, 'low', 84);
    ($this->level)($far, 'high', 41);

    $this->period(($this->loanOf)($high), 4, '2026-12-01');
    $this->period(($this->loanOf)($veryHigh), 4, '2026-12-01', ['principal_due' => 500, 'interest_due' => 25.50, 'total_due' => 525.50]);
    ($this->loanOf)($veryHigh)->update(['insurance_remaining_balance' => 120]);
    $this->period(($this->loanOf)($low), 4, '2026-12-01');
    $this->period(($this->loanOf)($far), 4, '2026-12-01');
    // A draft owes nothing yet, and a closed loan nothing any more.
    $this->period($this->application($high), 1, '2026-12-10');
    $closed = $this->releasedLoan($high, ['status' => 'completed']);
    $this->period($closed, 1, '2026-12-10');

    $branch = $this->branchAnalyst();
    $dashboard = $this->actingAs($branch)->getJson('/api/credit-scoring/dashboard')->assertOk()->json('data');
    $monitoring = $this->actingAs($branch)->getJson('/api/credit-scoring/risk-monitoring')->assertOk()->json('data.summary');

    // ₱1,100.00 + ₱525.50 + ₱120.00 premium owed.
    expect($dashboard['high_risk_outstanding'])->toBe('1745.50')
        ->and($monitoring['high_risk_exposure'])->toBe('1745.50');

    expect($this->actingAs($this->headOffice())->getJson('/api/credit-scoring/dashboard')->json('data.high_risk_outstanding'))->toBe('2845.50');

    $noLoans = $this->caller(['credit_scoring:view', 'borrowers:view']);
    expect($this->actingAs($noLoans)->getJson('/api/credit-scoring/dashboard')->json('data.high_risk_outstanding'))->toBeNull()
        ->and($this->actingAs($noLoans)->getJson('/api/credit-scoring/risk-monitoring')->json('data.summary.high_risk_exposure'))->toBeNull();
});

it('reads ₱0.00, never null, when high risk members owe nothing', function () {
    expect($this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/dashboard')->json('data.high_risk_outstanding'))->toBe('0.00');
});

it('counts members due for review: an open hard flag, or a fall of at least one band from the previous score', function () {
    $flagged = $this->scoredMember();
    $dropped = $this->scoredMember();
    $sameBand = $this->scoredMember();
    $improved = $this->scoredMember();
    $cleared = $this->scoredMember();
    ($this->level)($flagged, 'low', 84, 84);
    ($this->level)($dropped, 'elevated', 65, 85);
    ($this->level)($sameBand, 'low', 81, 88);
    ($this->level)($improved, 'moderate', 75, 62);
    ($this->level)($cleared, 'low', 82, 82);
    ($this->hardFlag)($flagged);
    ($this->hardFlag)($cleared)->update(['cleared_at' => now()]);
    // Flagged and dropped: counted once.
    ($this->hardFlag)($dropped);

    $far = $this->scoredMember($this->farBranch());
    ($this->level)($far, 'high', 45, 90);

    expect($this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/dashboard')->json('data.scores_due_for_review'))->toBe(2)
        ->and($this->actingAs($this->headOffice())->getJson('/api/credit-scoring/dashboard')->json('data.scores_due_for_review'))->toBe(3);

    // Flags are member details (round fz): without borrowers:view only the band drops count.
    expect($this->actingAs($this->caller(['credit_scoring:view', 'loans:view']))->getJson('/api/credit-scoring/dashboard')->json('data.scores_due_for_review'))->toBe(1);
});

it('reads the previous score through the current revision\'s bands', function () {
    $member = $this->scoredMember();
    // 79 was moderate under 1.0 (low starts at 80), the same as now.
    ($this->level)($member, 'moderate', 72, 79);
    $admin = $this->branchAnalyst();

    expect($this->actingAs($admin)->getJson('/api/credit-scoring/dashboard')->json('data.scores_due_for_review'))->toBe(0);

    $config = $this->actingAs($admin)->getJson('/api/credit-scoring/scorecard-config')->json('data');
    $config['risk_bands'] = array_map(fn (array $b) => $b['key'] === 'low' ? ['min_score' => 78] + $b : $b, $config['risk_bands']);
    $this->actingAs($admin)->putJson('/api/credit-scoring/scorecard-config', $config)->assertOk()->assertJsonPath('data.version', '1.1');

    // Under 1.1, 79 is low: the stored moderate is one band worse.
    expect($this->actingAs($admin)->getJson('/api/credit-scoring/dashboard')->json('data.scores_due_for_review'))->toBe(1);
});

it('counts nobody after a band edit while scores stay the same', function () {
    // Stored at the last run under 1.0: 79 moderate (low started at 80), and
    // 72 moderate with a previous 79.
    $steady = $this->scoredMember();
    $fell = $this->scoredMember();
    ($this->level)($steady, 'moderate', 79, 79);
    ($this->level)($fell, 'moderate', 72, 79);
    $admin = $this->branchAnalyst();

    $config = $this->actingAs($admin)->getJson('/api/credit-scoring/scorecard-config')->json('data');
    $config['risk_bands'] = array_map(fn (array $b) => $b['key'] === 'low' ? ['min_score' => 75] + $b : $b, $config['risk_bands']);
    $this->actingAs($admin)->putJson('/api/credit-scoring/scorecard-config', $config)->assertOk()->assertJsonPath('data.version', '1.1');

    // Under 1.1 both scores 79 read low; 72 still reads moderate, a real fall from 79.
    // The stored levels are not read: an unchanged score is never a drop.
    expect($this->actingAs($admin)->getJson('/api/credit-scoring/dashboard')->json('data.scores_due_for_review'))->toBe(1);

    ($this->level)($fell, 'moderate', 79, 79);
    expect($this->actingAs($admin)->getJson('/api/credit-scoring/dashboard')->json('data.scores_due_for_review'))->toBe(0);
});

it('counts past due members once each, by the grace rule, within the caller\'s branches', function () {
    $late = $this->scoredMember();
    $inGrace = $this->scoredMember();
    $far = $this->scoredMember($this->farBranch());
    $this->period(($this->loanOf)($late), 4, '2026-09-01');
    $this->period($this->releasedLoan($late), 1, '2026-10-01');
    // Due 6 Nov with 3 days' grace: not late until 10 Nov.
    $this->period(($this->loanOf)($inGrace), 4, '2026-11-06');
    $this->period(($this->loanOf)($far), 4, '2026-09-01');
    $unscored = $this->member(['branch_id' => $this->mainBranch()->id]);
    $this->period($this->releasedLoan($unscored), 1, '2026-09-01');

    expect($this->actingAs($this->branchAnalyst())->getJson('/api/credit-scoring/risk-monitoring')->json('data.summary.past_due_borrowers'))->toBe(2)
        ->and($this->actingAs($this->headOffice())->getJson('/api/credit-scoring/risk-monitoring')->json('data.summary.past_due_borrowers'))->toBe(3)
        ->and($this->actingAs($this->caller(['credit_scoring:view', 'borrowers:view']))->getJson('/api/credit-scoring/risk-monitoring')->json('data.summary.past_due_borrowers'))->toBeNull();
});
