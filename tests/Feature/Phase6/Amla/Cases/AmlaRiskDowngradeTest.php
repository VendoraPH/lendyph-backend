<?php

namespace Tests\Feature\Phase6\Amla\Cases;

use App\Models\AmlaRiskAssessment;
use App\Models\AmlaRiskDowngrade;
use App\Models\Borrower;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The four-eyes rule for High-risk downgrades (AMLA-B4 review, decided for
 * AMLA-B6): overriding a subject's AML risk down from High clears enhanced
 * due diligence, so it waits for a second `amla:cdd` holder's approval. Any
 * other override applies at once, as before.
 */
class AmlaRiskDowngradeTest extends TestCase
{
    use CaseTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function highRisk(Borrower $member): void
    {
        $this->putJson("/api/amla/cdd/members/{$member->id}", ['occupation' => 'Trader'])->assertOk();
        $this->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments", ['override_level' => 'high', 'override_reason' => 'Cash-heavy business.'])->assertCreated();
    }

    public function test_a_downgrade_from_high_waits_for_a_second_cdd_holder(): void
    {
        $member = $this->member();
        $maker = $this->amlaUser(['amla:cdd']);
        $checker = $this->amlaUser(['amla:cdd']);
        $outsider = $this->amlaUser(['amla:alerts']);
        $this->actingAs($maker);
        $this->highRisk($member);
        $outside = $this->outsideAmlaCounts();

        $pending = $this->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments", ['override_level' => 'low', 'override_reason' => 'Business closed.'])
            ->assertStatus(202)->json('data');
        $this->assertSame(['pending', 'high', 'low'], [$pending['status'], $pending['from_level'], $pending['to_level']]);
        $this->assertSame('high', AmlaRiskAssessment::query()->latest('id')->firstOrFail()->level);
        $this->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments", ['override_level' => 'medium', 'override_reason' => 'Again.'])->assertConflict();

        $this->postJson("/api/amla/risk-downgrades/{$pending['id']}/approve")->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/amla/risk-downgrades/{$pending['id']}/approve")->assertForbidden();

        $this->actingAs($checker)->getJson('/api/amla/risk-downgrades')->assertOk()->assertJsonPath('data.0.id', $pending['id']);
        $this->postJson("/api/amla/risk-downgrades/{$pending['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/amla/risk-downgrades/{$pending['id']}/approve")->assertConflict();

        $latest = AmlaRiskAssessment::query()->latest('id')->firstOrFail();
        $this->assertSame(['low', true], [$latest->level, $latest->overridden]);
        $this->assertSame($maker->id, $latest->assessed_by);
        $this->assertSame($checker->id, AmlaRiskDowngrade::query()->findOrFail($pending['id'])->decided_by);
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_a_rejected_downgrade_leaves_the_risk_high_and_other_overrides_apply_at_once(): void
    {
        $member = $this->member();
        $maker = $this->amlaUser(['amla:cdd']);
        $this->actingAs($maker);
        $this->highRisk($member);
        $id = $this->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments", ['override_level' => 'medium', 'override_reason' => 'Less cash now.'])->assertStatus(202)->json('data.id');

        $this->actingAs($this->amlaUser(['amla:cdd']))->postJson("/api/amla/risk-downgrades/{$id}/reject")->assertUnprocessable();
        $this->postJson("/api/amla/risk-downgrades/{$id}/reject", ['reason' => 'Cash deposits continue.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('high', AmlaRiskAssessment::query()->latest('id')->firstOrFail()->level);

        $this->actingAs($maker);
        $this->postJson("/api/amla/cdd/members/{$member->id}/risk-assessments", ['override_level' => 'high', 'override_reason' => 'Still cash-heavy.'])
            ->assertCreated()->assertJsonPath('data.level', 'high');
    }
}
