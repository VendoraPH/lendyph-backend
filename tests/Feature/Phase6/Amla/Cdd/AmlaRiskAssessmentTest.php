<?php

namespace Tests\Feature\Phase6\Amla\Cdd;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaLogEntry;
use App\Models\AmlaRiskAssessment;
use App\Models\AmlaRiskRuleVersion;
use App\Services\Amla\Cdd\RiskRules;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AML risk assessment (AMLA design §4.9; task §9): separate from credit
 * scoring, scored with documented, versioned points marked "to verify", as
 * Low, Medium or High. People may override a result with a reason; every
 * assessment is kept as written. It never reads gender, civil status, age,
 * spouse fields or any address detail below the country. A change of
 * circumstances reassesses; High risk and PEPs need enhanced due diligence.
 */
class AmlaRiskAssessmentTest extends TestCase
{
    use CddTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_version_one_rules_are_documented_starting_points_marked_to_verify(): void
    {
        $version = app(RiskRules::class)->current();

        $this->assertSame(1, $version->version);
        $this->assertTrue($version->to_verify);
        $this->assertSame(['medium' => 20, 'high' => 40], $version->definition['thresholds']);
        $this->assertSame(30, $version->definition['factors']['pep']['pep']);
        $this->assertSame(['KP', 'IR', 'MM'], $version->definition['high_risk_countries']);
    }

    public function test_saving_a_profile_assesses_it_and_explains_every_factor(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();

        $risk = $this->actingAs($officer)->putJson($this->cddUrl($member), [
            ...$this->completeProfile(), 'source_of_funds' => 'business',
        ])->assertOk()->json('data.risk');

        $factors = collect($risk['factors'])->keyBy('key');
        $this->assertSame(15, $factors['id_verification']['points'], 'no verified ID yet');
        $this->assertSame(5, $factors['source_of_funds']['points']);
        $this->assertSame(0, $factors['pep']['points']);
        $this->assertSame('Data not available yet', $factors['watchlist']['value_words']);
        $this->assertSame(20, $risk['score']);
        $this->assertSame('medium', $risk['computed_level'], 'a score of 20 reaches the medium threshold');
        $this->assertSame('medium', $risk['level']);
        $this->assertSame('Medium', $risk['level_words']);
    }

    public function test_it_never_reads_protected_characteristics(): void
    {
        $officer = $this->headOfficeCddOfficer();
        $one = $this->member(['gender' => 'male', 'civil_status' => 'single', 'birthdate' => '1990-01-01', 'address' => 'Purok 1', 'city' => 'Cebu City', 'spouse_first_name' => null]);
        $two = $this->member(['gender' => 'female', 'civil_status' => 'married', 'birthdate' => '1950-06-30', 'address' => 'Sitio 9', 'city' => 'Davao City', 'spouse_first_name' => 'Ana'], $this->otherBranch());

        $first = $this->actingAs($officer)->putJson($this->cddUrl($one), $this->completeProfile())->json('data.risk');
        $second = $this->actingAs($officer)->putJson($this->cddUrl($two), $this->completeProfile())->json('data.risk');

        $this->assertSame($first['score'], $second['score']);
        $this->assertSame(array_column($first['factors'], 'key'), array_column($second['factors'], 'key'));
        $this->assertEmpty(array_intersect(['gender', 'civil_status', 'birthdate', 'age', 'address', 'city', 'spouse'], array_column($first['factors'], 'key')));
    }

    public function test_a_pep_or_high_risk_customer_needs_enhanced_due_diligence_until_it_is_recorded(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();

        $data = $this->actingAs($officer)->putJson($this->cddUrl($member), [
            ...$this->completeProfile(), 'pep_status' => 'pep', 'nationality' => 'IR', 'adverse_info' => true, 'adverse_info_note' => 'News report to check.',
        ])->assertOk()->json('data');

        $this->assertSame('high', $data['risk']['level']);
        $this->assertTrue($data['profile']['edd_required']);
        $this->assertSame('edd_required', $data['cdd_status']);

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/reviews'), [
            'kind' => 'edd', 'outcome' => 'no_change', 'notes' => 'Source of wealth documents seen.',
        ])->assertCreated();

        $after = $this->actingAs($officer)->getJson($this->cddUrl($member))->json('data');
        $this->assertTrue($after['profile']['edd_completed']);
        $this->assertNotSame('edd_required', $after['cdd_status']);
    }

    public function test_an_override_needs_a_reason_and_stands_until_the_computed_level_changes(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();
        $before = $this->outsideAmlaCounts();

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/risk-assessments'), ['override_level' => 'high'])
            ->assertUnprocessable()->assertJsonValidationErrors(['override_reason']);

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/risk-assessments'), [
            'override_level' => 'high', 'override_reason' => 'Branch manager knows of cash-heavy side business.',
        ])->assertCreated()->assertJsonPath('data.level', 'high')->assertJsonPath('data.overridden', true);

        $carried = $this->actingAs($officer)->putJson($this->cddUrl($member), ['occupation' => 'Head teacher'])->json('data.risk');
        $this->assertSame('high', $carried['level'], 'the computed level did not change, so the override stands');
        $this->assertTrue($carried['override_carried']);

        $moved = $this->actingAs($officer)->putJson($this->cddUrl($member), ['pep_status' => 'pep'])->json('data.risk');
        $this->assertSame($moved['computed_level'], $moved['level'], 'a new computed level ends the override');
        $this->assertFalse($moved['overridden']);

        $this->assertSame(4, AmlaRiskAssessment::query()->count(), 'history is kept: every assessment is its own row');
        $this->assertNothingWrittenOutsideAmla($before);

        foreach (AmlaLogEntry::query()->where('action', 'cdd_risk_assessed')->get() as $logged) {
            $this->assertEqualsCanonicalizing(['profile_id', 'assessment_id', 'rule_version'], array_keys($logged->details), 'ids only: no level, override or trigger in the log');
        }
    }

    public function test_an_assessment_is_kept_as_written(): void
    {
        $member = $this->member();
        $this->actingAs($this->cddOfficer())->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();
        $assessment = AmlaRiskAssessment::query()->sole();
        $this->assertSame('low', $assessment->level);

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $assessment->forceFill(['level' => 'high'])->save();
    }

    public function test_risk_rules_change_only_through_an_approved_change_and_add_a_version(): void
    {
        $maker = $this->amlaUser(['amla:settings', 'branches:view_all']);
        $checker = $this->amlaUser(['amla:settings', 'branches:view_all']);
        $definition = app(RiskRules::class)->current()->definition;
        $definition['thresholds'] = ['medium' => 25, 'high' => 50];

        $id = $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'risk_rules', 'reason' => 'Counsel review.', 'definition' => $definition,
        ])->assertCreated()->assertJsonPath('data.summary', 'AMLA risk rules: thresholds')->json('data.id');

        $this->assertSame(1, app(RiskRules::class)->current()->version);
        $this->actingAs($checker)->postJson("/api/amla/change-requests/{$id}/approve")->assertOk();

        $current = app(RiskRules::class)->current();
        $this->assertSame(2, $current->version);
        $this->assertSame(['medium' => 25, 'high' => 50], $current->definition['thresholds']);
        $this->assertTrue($current->to_verify);

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        AmlaRiskRuleVersion::query()->where('version', 1)->firstOrFail()->delete();
    }

    public function test_a_super_admin_who_asked_for_a_rules_change_cannot_approve_it(): void
    {
        $definition = app(RiskRules::class)->current()->definition;
        $definition['thresholds'] = ['medium' => 25, 'high' => 50];

        $id = $this->actingAs($this->admin)->postJson('/api/amla/change-requests', [
            'type' => 'risk_rules', 'reason' => 'Counsel review.', 'definition' => $definition,
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/amla/change-requests/{$id}/approve")->assertForbidden()
            ->assertJsonPath('message', 'You asked for this change, so another AMLA settings holder must approve it.');
        $this->assertSame(1, app(RiskRules::class)->current()->version);
    }

    public function test_a_risk_rules_change_must_keep_every_factor_and_rising_thresholds(): void
    {
        $definition = app(RiskRules::class)->current()->definition;
        unset($definition['factors']['pep']);
        $definition['thresholds'] = ['medium' => 50, 'high' => 40];

        $this->actingAs($this->amlaUser(['amla:settings', 'branches:view_all']))->postJson('/api/amla/change-requests', [
            'type' => 'risk_rules', 'reason' => 'x', 'definition' => $definition,
        ])->assertUnprocessable()->assertJsonValidationErrors(['definition.factors', 'definition.thresholds']);
    }

    public function test_the_rules_are_read_with_head_office_access(): void
    {
        $this->actingAs($this->cddOfficer())->getJson('/api/amla/risk-rules')->assertForbidden();

        $data = $this->actingAs($this->headOfficeCddOfficer())->getJson('/api/amla/risk-rules')->assertOk()->json('data');
        $this->assertSame(1, $data['current']['version']);
        $this->assertTrue($data['current']['to_verify']);
        $this->assertCount(1, $data['versions']);
    }
}
