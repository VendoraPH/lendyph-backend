<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\BrandingSetting;
use App\Models\CdaOfficer;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaSetting;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The regulatory profile and the CDA settings (CDA design §1, §3). The
 * profile stores only CDA's own fields; the name, address and contact come
 * from Branding, the branch count from branches and the officers from the
 * officers register (`pulled`, read-only, with their sources). A CTE change
 * updates whether ATIR and ABR apply to reports still in draft. Both are
 * audited.
 */
class CdaProfileAndSettingsTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_profile_shows_what_it_stores_and_what_it_pulls_from_elsewhere(): void
    {
        BrandingSetting::current()->update(['organization_name' => 'BINHS Multi-Purpose Cooperative', 'organization_address' => 'Butuan City', 'organization_contact' => '0917 000 0000']);
        Branch::factory()->create(['is_active' => true]);
        Branch::factory()->create(['is_active' => false]);
        $chair = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Rosa', 'last_name' => 'Lim', 'middle_name' => null, 'suffix' => null]);
        CdaOfficer::factory()->create(['borrower_id' => $chair->id, 'name' => null, 'sex' => null, 'position' => 'chairperson', 'term_start' => '2025-03-01', 'term_end' => '2027-03-01']);
        CdaOfficer::factory()->create(['borrower_id' => null, 'name' => 'Pedro Cruz', 'sex' => 'male', 'position' => 'general_manager', 'term_start' => '2024-01-01', 'term_end' => null]);
        CdaOfficer::factory()->create(['borrower_id' => null, 'name' => 'Old Treasurer', 'position' => 'treasurer', 'term_start' => '2020-01-01', 'term_end' => '2023-12-31']);
        $activeBranches = Branch::query()->where('is_active', true)->count();

        $data = $this->getJson('/api/cda/regulatory-profile')->assertOk()->json('data');

        $this->assertSame('none', $data['stored']['cte_status']);
        $this->assertArrayHasKey('cda_registration_no', $data['stored']);
        $this->assertSame(['value' => 'BINHS Multi-Purpose Cooperative', 'source' => 'Settings › Branding'], $data['pulled']['name']);
        $this->assertSame('Butuan City', $data['pulled']['address']['value']);
        $this->assertSame($activeBranches, $data['pulled']['branch_count']['value']);
        $this->assertSame('Rosa Lim', $data['pulled']['chairperson']['value']);
        $this->assertSame('Pedro Cruz', $data['pulled']['general_manager']['value']);
        $this->assertNull($data['pulled']['treasurer']['value'], 'An officer whose term ended is not pulled.');
        $this->assertNull($data['pulled']['secretary']['value']);
    }

    public function test_saving_the_profile_validates_and_is_audited(): void
    {
        $this->putJson('/api/cda/regulatory-profile', ['cte_status' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors('cte_status');
        $this->putJson('/api/cda/regulatory-profile', ['cooperative_category' => 'micro'])->assertUnprocessable()->assertJsonValidationErrors('cooperative_category');
        $this->putJson('/api/cda/regulatory-profile', ['cte_expires_on' => '2026-01-01', 'cte_issued_on' => '2026-06-01'])->assertUnprocessable()->assertJsonValidationErrors('cte_expires_on');

        $before = AuditLog::query()->max('id');
        $this->putJson('/api/cda/regulatory-profile', [
            'cda_registration_no' => '9520-16000123', 'cooperative_type' => 'credit', 'cooperative_category' => 'primary',
            'asset_class' => 'small', 'tin' => '123-456-789-000', 'cte_status' => 'valid', 'cte_expires_on' => '2028-12-31',
        ])->assertOk()
            ->assertJsonPath('data.stored.cda_registration_no', '9520-16000123')
            ->assertJsonPath('data.stored.asset_class', 'small')
            ->assertJsonPath('data.stored.asset_class_source', 'manual')
            ->assertJsonPath('data.stored.cte_expires_on', '2028-12-31')
            ->assertJsonPath('data.updated_by.id', $this->admin->id);

        $this->assertTrue(AuditLog::query()->where('id', '>', $before)->where('auditable_type', CdaRegulatoryProfile::class)->exists());

        $this->putJson('/api/cda/regulatory-profile', ['asset_class' => null])->assertOk()
            ->assertJsonPath('data.stored.asset_class_source', 'computed');
    }

    public function test_a_cte_change_updates_whether_atir_and_abr_apply_to_draft_reports(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $atir = CdaReport::query()->where('report_type', 'atir')->firstOrFail();
        $this->assertFalse($atir->applicable);

        $this->putJson('/api/cda/regulatory-profile', ['cte_status' => 'valid', 'cte_expires_on' => '2027-06-30'])->assertOk();
        $this->assertTrue($atir->fresh()->applicable);
        $this->assertNull($atir->fresh()->not_applicable_reason);

        $atir->forceFill(['status' => 'finalized'])->save();
        $this->putJson('/api/cda/regulatory-profile', ['cte_status' => 'expired'])->assertOk();
        $this->assertTrue($atir->fresh()->applicable, 'A report past draft keeps what it was.');
        $this->assertFalse(CdaReport::query()->where('report_type', 'abr')->firstOrFail()->applicable);
    }

    public function test_the_settings_are_read_validated_and_audited(): void
    {
        $this->getJson('/api/cda/settings')->assertOk()
            ->assertJsonPath('data.annual_deadline_days', 120)
            ->assertJsonPath('data.mediation_deadline_days', 30)
            ->assertJsonPath('data.reminder_days', [30, 15, 7, 3]);

        $this->putJson('/api/cda/settings', ['annual_deadline_days' => 0])->assertUnprocessable()->assertJsonValidationErrors('annual_deadline_days');
        $this->putJson('/api/cda/settings', ['reminder_days' => [30, 30]])->assertUnprocessable()->assertJsonValidationErrors('reminder_days.1');
        $this->putJson('/api/cda/settings', ['required_trainings' => ['chairperson' => 'governance']])->assertUnprocessable()->assertJsonValidationErrors('required_trainings.chairperson');
        $this->putJson('/api/cda/settings', ['asset_class_thresholds' => ['micro' => '5000000.00', 'small' => '3000000.00', 'medium' => '100000000.00']])
            ->assertUnprocessable()->assertJsonValidationErrors('asset_class_thresholds');

        $before = AuditLog::query()->max('id');
        $this->putJson('/api/cda/settings', [
            'annual_deadline_days' => 120, 'mediation_deadline_days' => 45, 'reminder_days' => [30, 14, 7],
            'required_trainings' => ['chairperson' => ['fundamentals_of_cooperatives', 'governance_and_management']],
            'asset_class_thresholds' => ['micro' => '3000000.00', 'small' => '15000000.00', 'medium' => '100000000.00'],
        ])->assertOk()
            ->assertJsonPath('data.mediation_deadline_days', 45)
            ->assertJsonPath('data.reminder_days', [30, 14, 7])
            ->assertJsonPath('data.required_trainings.chairperson.1', 'governance_and_management')
            ->assertJsonPath('data.asset_class_thresholds.small', '15000000.00');

        $this->assertSame(45, CdaSetting::current()->mediation_deadline_days);
        $this->assertTrue(AuditLog::query()->where('id', '>', $before)->where('auditable_type', CdaSetting::class)->exists());
    }
}
