<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaSubmission;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every C2 endpoint (design §3) answers 403 without its own permission and
 * without `branches:view_all`, with roles holding exact permissions (never
 * super_admin). Restricted reports (mediation, audit, tax) answer 403 with
 * only `cda:view`, on the report, its alias, its snapshots, its workflow and
 * its submission.
 */
class CdaReportPermissionsTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    private CdaReportingPeriod $period;

    private CdaReport $capr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->period = $this->openYear();
        $this->capr = $this->report($this->period, 'capr');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string}> method, url, body, permission */
    private function endpoints(int $reportId, int $submissionId): array
    {
        return [
            ['get', "/api/cda/reports/{$reportId}", [], CdaAccess::VIEW],
            ['get', '/api/cda/periods/2026/reports/capr', [], CdaAccess::VIEW],
            ['put', "/api/cda/reports/{$reportId}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 1]]], CdaAccess::PREPARE],
            ['post', "/api/cda/reports/{$reportId}/workflow", ['action' => 'refresh'], CdaAccess::PREPARE],
            ['get', "/api/cda/reports/{$reportId}/snapshots", [], CdaAccess::VIEW],
            ['get', '/api/cda/periods/2026/dashboard', [], CdaAccess::VIEW],
            ['get', '/api/cda/periods/2026/calendar', [], CdaAccess::VIEW],
            ['get', '/api/cda/periods/2026/submissions', [], CdaAccess::VIEW],
            ['put', "/api/cda/submissions/{$submissionId}", ['remarks' => 'Noted'], CdaAccess::SUBMIT],
        ];
    }

    public function test_every_endpoint_needs_its_permission_and_view_all(): void
    {
        $submission = CdaSubmission::factory()->create(['report_id' => $this->capr->id]);

        foreach ($this->endpoints($this->capr->id, $submission->id) as [$method, $url, $body, $permission]) {
            $others = array_values(array_diff([CdaAccess::VIEW, CdaAccess::PREPARE, CdaAccess::SUBMIT, CdaAccess::APPROVE], [$permission]));

            $this->actingAs($this->userWith(['branches:view_all', ...$others]))->json($method, $url, $body)
                ->assertForbidden();
            $this->actingAs($this->userWith([$permission, CdaAccess::VIEW]))->json($method, $url, $body)
                ->assertForbidden()
                ->assertJsonPath('message', 'CDA reports cover the whole cooperative, so they need access to every branch (branches:view_all).');
            $allowed = $this->actingAs($this->userWith(['branches:view_all', CdaAccess::VIEW, $permission]))->json($method, $url, $body);
            $this->assertNotSame(403, $allowed->status(), "{$method} {$url} refused a holder of {$permission}: ".$allowed->getContent());
        }
    }

    public function test_restricted_reports_need_their_view_permission(): void
    {
        $fs = $this->report($this->period, 'financial_statements');
        $mediation = $this->report($this->period, 'mediation', 'h1');
        $submission = CdaSubmission::factory()->create(['report_id' => $fs->id]);
        $staff = $this->cdaStaff();

        $this->actingAs($staff);
        $this->getJson("/api/cda/reports/{$fs->id}")->assertForbidden()
            ->assertJsonPath('message', 'Audited Financial Statements is restricted: it needs the cda:view_audit permission.');
        $this->getJson('/api/cda/periods/2026/reports/financial_statements')->assertForbidden();
        $this->getJson("/api/cda/reports/{$mediation->id}")->assertForbidden();
        $this->getJson('/api/cda/periods/2026/reports/mediation?part=h2')->assertForbidden();
        $this->postJson("/api/cda/reports/{$fs->id}/workflow", ['action' => 'refresh'])->assertForbidden();
        $this->putJson("/api/cda/reports/{$fs->id}", ['fields' => [['field_key' => 'auditor.auditor_name', 'value' => 'A']]])->assertForbidden();
        $this->getJson("/api/cda/reports/{$fs->id}/snapshots")->assertForbidden();
        $this->putJson("/api/cda/submissions/{$submission->id}", ['remarks' => 'x'])->assertForbidden();
        $this->assertSame([], collect($this->getJson('/api/cda/periods/2026/submissions')->json('data'))->pluck('report_type')->all());

        $this->actingAs($this->cdaStaff([CdaAccess::VIEW_AUDIT]))->getJson("/api/cda/reports/{$fs->id}")->assertOk();
        $this->actingAs($this->cdaStaff([CdaAccess::VIEW_MEDIATION]))->getJson('/api/cda/periods/2026/reports/mediation?part=h2')->assertOk()
            ->assertJsonPath('data.part', 'h2');
    }

    public function test_an_unknown_report_or_period_answers_404(): void
    {
        $this->actingAs($this->cdaStaff());
        $this->getJson('/api/cda/reports/999999')->assertNotFound();
        $this->getJson('/api/cda/periods/2030/reports/capr')->assertNotFound();
        $this->getJson('/api/cda/periods/2026/reports/nonsense')->assertNotFound();
    }
}
