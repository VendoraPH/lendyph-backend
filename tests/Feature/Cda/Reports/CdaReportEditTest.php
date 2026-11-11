<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportValue;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET and PUT /cda/reports/{id} (design §2–§4): sections and fields with
 * their source label, key and value, whether each is editable, the issues,
 * readiness and history. A save takes manual fields only (a sourced field
 * only when its template allows an override, with a reason; never a
 * calculation), answers 409 unless the report is a draft, and recomputes the
 * calculations, issues and readiness at once.
 */
class CdaReportEditTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);
        $this->member(['gender' => 'female']);
        $this->member(['gender' => 'female']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function field(array $data, string $section, string $key): array
    {
        $fields = collect($data['sections'])->firstWhere('key', $section)['fields'];

        return collect($fields)->firstWhere('key', $key);
    }

    public function test_the_report_shows_sources_calculations_and_what_can_be_edited(): void
    {
        $report = $this->caprWithTestTemplate();
        $user = $this->cdaStaff();

        $data = $this->actingAs($user)->getJson("/api/cda/reports/{$report->id}")->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.report_type', 'capr')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.template.version', '2026.9')
            ->json('data');

        $name = $this->field($data, 'general', 'cooperative_name');
        $this->assertSame('Binhi Multi-Purpose Cooperative', $name['value']);
        $this->assertSame('Settings › Branding', $name['source']);
        $this->assertSame('coop.name', $name['source_key']);
        $this->assertSame('general.cooperative_name', $name['field_key']);
        $this->assertFalse($name['editable']);

        $total = $this->field($data, 'membership', 'members_total');
        $this->assertSame(3, $total['value']);
        $this->assertSame('Members · as of Dec 31, 2026', $total['source']);
        $this->assertFalse($total['editable']);
        $this->assertSame(0, $this->field($data, 'membership', 'members_unspecified')['value']);
        $this->assertSame('Calculated', $this->field($data, 'membership', 'members_unspecified')['source']);
        $this->assertTrue($this->field($data, 'employment', 'employees_regular')['editable']);
        $this->assertTrue($this->field($data, 'general', 'registration_no')['overridable']);

        $this->assertSame(['missing_required_data'], collect($data['issues'])->where('severity', 'critical')->pluck('category')->unique()->values()->all());
        $this->assertSame('employment.employees_regular', collect($data['issues'])->firstWhere('severity', 'critical')['field_key']);

        $this->getJson('/api/cda/periods/2026/reports/capr')->assertOk()->assertJsonPath('data.id', $report->id);
    }

    public function test_saving_manual_fields_recomputes_calculations_issues_and_readiness(): void
    {
        $report = $this->caprWithTestTemplate();
        $before = $report->readiness;

        $data = $this->actingAs($this->cdaStaff())->putJson("/api/cda/reports/{$report->id}", ['fields' => [
            ['field_key' => 'employment.employees_regular', 'value' => 4],
            ['field_key' => 'employment.payroll', 'value' => '1000000.10', 'remarks' => 'From the payroll register'],
        ]])->assertOk()->json('data');

        $this->assertSame(4, $this->field($data, 'employment', 'employees_regular')['value']);
        $this->assertSame('1000000.10', $this->field($data, 'employment', 'payroll')['value']);
        $this->assertSame('From the payroll register', $this->field($data, 'employment', 'payroll')['remarks']);
        $this->assertSame('250000.03', $this->field($data, 'employment', 'payroll_per_employee')['value']);
        $this->assertGreaterThan($before, $data['readiness']);
        $this->assertSame(0, $data['critical_count']);

        $audit = AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $report->id)->where('action', 'cda_report_saved')->sole();
        $this->assertEqualsCanonicalizing(['employment.employees_regular' => null, 'employment.payroll' => null], $audit->old_values['values']);
        $this->assertSame(4, $audit->new_values['values']['employment.employees_regular']);
    }

    public function test_a_sourced_or_calculated_field_is_refused_and_an_override_needs_its_reason(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->actingAs($this->cdaStaff());

        $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'membership.members_total', 'value' => 99]]])
            ->assertStatus(422)->assertJsonValidationErrors(['fields.0.field_key' => 'Total members comes from Members · as of Dec 31, 2026 and can\'t be changed here. Fix it at its source.']);
        $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'membership.members_unspecified', 'value' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['fields.0.field_key']);
        $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'nowhere.field', 'value' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['fields.0.field_key']);
        $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.payroll', 'value' => '12.345']]])
            ->assertStatus(422)->assertJsonValidationErrors(['fields.0.value']);
        $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'general.registration_no', 'value' => '9520-1234']]])
            ->assertStatus(422)->assertJsonValidationErrors(['fields.0.override_reason']);

        $data = $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'general.registration_no', 'value' => '9520-1234', 'override_reason' => 'As printed on the certificate']]])
            ->assertOk()->json('data');
        $field = $this->field($data, 'general', 'registration_no');
        $this->assertSame('9520-1234', $field['value']);
        $this->assertTrue($field['overridden']);
        $this->assertSame('As printed on the certificate', $field['override_reason']);

        $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'general.registration_no', 'revert' => true]]])->assertOk();
        $this->assertFalse(CdaReportValue::query()->where('report_id', $report->id)->where('field_key', 'general.registration_no')->sole()->is_override);
    }

    public function test_only_a_draft_can_be_saved(): void
    {
        $report = $this->caprWithTestTemplate();
        $report->forceFill(['status' => CdaReport::STATUS_FOR_REVIEW])->save();

        $this->actingAs($this->cdaStaff())->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 1]]])
            ->assertStatus(409)->assertJsonPath('message', 'This report is for review, so it can\'t be changed. Return it to draft first.');
    }

    public function test_a_report_keeps_its_values_and_get_never_refreshes_them(): void
    {
        $report = $this->caprWithTestTemplate();
        $this->member();

        $this->actingAs($this->cdaStaff())->getJson("/api/cda/reports/{$report->id}")->assertOk()
            ->assertJsonPath('data.sections.1.fields.0.value', 3);

        $this->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'refresh'])->assertOk()
            ->assertJsonPath('data.sections.1.fields.0.value', 4);
        $this->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => 'validate'])->assertOk();
    }
}
