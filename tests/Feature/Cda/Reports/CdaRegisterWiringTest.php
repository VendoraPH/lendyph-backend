<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReportingPeriod;
use App\Models\CdaSetting;
use App\Models\CdaSubmission;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\SubmissionProofs;
use App\Services\Cda\Reports\NamedChecks;
use App\Services\Cda\Reports\ReportDefinition;
use App\Services\Cda\Sources\SourceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * C2 wired to C3's registers and C1's final templates: the officer checks
 * read the officers register (TrainingCompliance, `officers.current`); each
 * filing carries C3's proof, its signed `url` only for a caller who can open
 * it; restricted reports' filings never reach a caller without their view;
 * and the seeded sworn statement takes a date signed in the filing year.
 */
class CdaRegisterWiringTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private ?CdaReportingPeriod $period = null;

    private function check(string $name): ?string
    {
        $period = $this->period ??= $this->openYear();

        return app(NamedChecks::class)->run($name, [], new SourceContext($period), new ReportDefinition(['sections' => []]));
    }

    public function test_the_training_check_reads_the_officers_register(): void
    {
        CdaSetting::current()->update(['required_trainings' => ['chairperson' => ['fundamentals']]]);
        $chair = CdaOfficer::factory()->create(['position' => 'chairperson', 'term_start' => '2025-01-01']);

        $this->assertSame('1 officer hasn\'t completed the required trainings.', $this->check('officers.training_incomplete'));

        CdaOfficerTraining::factory()->create(['officer_id' => $chair->id, 'requirement_key' => 'fundamentals', 'completed_on' => '2026-03-01']);
        $this->assertNull($this->check('officers.training_incomplete'));
    }

    public function test_the_positions_check_reads_the_officers_register(): void
    {
        foreach (['chairperson', 'secretary', 'treasurer'] as $position) {
            CdaOfficer::factory()->create(['position' => $position, 'term_start' => '2025-01-01']);
        }

        $this->assertSame('No general manager is recorded as serving on Dec 31, 2026.', $this->check('officers.required_positions'));
    }

    public function test_restricted_filings_reach_only_holders_of_their_view(): void
    {
        $period = $this->openYear();
        CdaSubmission::factory()->create(['report_id' => $this->report($period, 'capr')->id, 'method' => 'cais', 'submitted_on' => '2027-01-20']);
        CdaSubmission::factory()->create(['report_id' => $this->report($period, 'mediation', 'h1')->id, 'method' => 'email', 'submitted_on' => '2026-07-20']);

        $viewer = $this->userWith(['branches:view_all', CdaAccess::VIEW]);
        $this->assertSame(['capr'], collect($this->actingAs($viewer)->getJson('/api/cda/periods/2026/submissions')->assertOk()->json('data'))->pluck('report_type')->all());

        $holder = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::VIEW_MEDIATION]);
        $this->assertEqualsCanonicalizing(['capr', 'mediation'], collect($this->actingAs($holder)->getJson('/api/cda/periods/2026/submissions')->assertOk()->json('data'))->pluck('report_type')->all());
    }

    public function test_a_filing_carries_its_proof_with_a_signed_url(): void
    {
        Storage::fake('private');
        $period = $this->openYear();
        $submission = CdaSubmission::factory()->create(['report_id' => $this->report($period, 'capr')->id, 'method' => 'cais', 'submitted_on' => '2027-01-20']);
        app(SubmissionProofs::class)->upload($submission, UploadedFile::fake()->create('acknowledgment.pdf', 12, 'application/pdf'), $this->admin, 'CAIS receipt');

        $proof = $this->actingAs($this->userWith(['branches:view_all', CdaAccess::VIEW]))->getJson('/api/cda/periods/2026/submissions')->assertOk()->json('data.0.proof');

        $this->assertNotNull($proof);
        $this->assertNotEmpty($proof['url']);
    }

    public function test_the_seeded_sworn_statement_takes_a_date_signed_in_the_filing_year(): void
    {
        $report = $this->report($this->openYear(), 'sworn_statement');
        $staff = $this->cdaStaff();

        $issues = $this->actingAs($staff)->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'statement.statement_date', 'value' => '2027-01-20']]])->assertOk()->json('data.issues');
        $this->assertNull(collect($issues)->firstWhere('rule_id', 'statement_date_in_range'));

        $issues = $this->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'statement.statement_date', 'value' => '2025-12-31']]])->assertOk()->json('data.issues');
        $this->assertSame('warning', collect($issues)->firstWhere('rule_id', 'statement_date_in_range')['severity']);
    }
}
