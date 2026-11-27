<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaOfficer;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Exports\ExportContentBuilder;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\Reports\Readiness;
use App\Services\Cda\Reports\ReportEngine;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The CDA browser recheck 2:
 * - officers' sex reads in words ("Female", "Male") on the CAPR screen and in
 *   its PDF, and member counts by sex are labelled "Male members" and
 *   "Female members"; the stored values stay keys;
 * - a finalized or later report's readiness counts its filing documents
 *   live, like its issues: the document checks, the documents breakdown and
 *   the percent follow an upload, while the snapshot stays as it was.
 */
class CdaRecheckTwoTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    private User $preparer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->preparer = $this->cdaStaff();
        $this->approver = $this->cdaStaff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_capr_officers_sex_reads_in_words_on_screen_and_in_the_pdf_and_the_stored_value_stays_the_key(): void
    {
        $member = $this->member(['first_name' => 'Rosa', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Lim', 'gender' => 'female']);
        CdaOfficer::factory()->create(['borrower_id' => $member->id, 'name' => null, 'sex' => null, 'position' => 'chairperson', 'term_start' => '2025-03-01']);
        $pedro = CdaOfficer::factory()->create(['name' => 'Pedro Cruz', 'sex' => 'male', 'position' => 'general_manager', 'term_start' => '2026-06-01']);
        $report = $this->report($this->openYear(), 'capr');
        app(ReportEngine::class)->refresh($report);

        $view = $this->actingAs($this->preparer)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data');
        $officers = collect($view['sections'])->firstWhere('key', 'officers');
        $this->assertSame(['Female', 'Male'], array_column($officers['rows'], 'sex'));
        $this->assertSame(['female', 'male'], collect($report->values()->where('field_key', 'officers')->firstOrFail()->source_value['value'])->pluck('sex_key')->all(), 'The source keeps the key beside the words.');
        $this->assertSame('male', $pedro->fresh()->sex, 'The register keeps the key.');
        $registerRow = collect($this->actingAs($this->preparer)->getJson('/api/cda/officers')->assertOk()->json('data'))->firstWhere('id', $pedro->id);
        $this->assertSame('male', $registerRow['sex'], 'The register form still reads the key.');

        $html = app(PdfRenderer::class)->html(app(ExportContentBuilder::class)->build($report->fresh(), $this->admin));
        $this->assertStringContainsString('<td>Female</td>', $html);
        $this->assertStringContainsString('<td>Male</td>', $html);
        $this->assertStringNotContainsString('<td>female</td>', $html);
        $this->assertStringNotContainsString('<td>male</td>', $html);
    }

    public function test_member_counts_by_sex_are_labelled_male_members_and_female_members(): void
    {
        $this->member(['gender' => 'male']);
        $this->member(['gender' => 'female']);
        $report = $this->report($this->openYear(), 'capr');
        app(ReportEngine::class)->refresh($report);

        $fields = collect($this->actingAs($this->preparer)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data.sections'))
            ->flatMap(fn (array $section) => $section['fields'] ?? [])->keyBy('field_key');

        $this->assertStringStartsWith('Male members · as of ', $fields['membership.members_male']['source']);
        $this->assertStringStartsWith('Female members · as of ', $fields['membership.members_female']['source']);
        $this->assertStringNotContainsString('(male)', (string) $fields['membership.members_male']['source']);
    }

    public function test_a_locked_reports_readiness_counts_its_filing_documents_live_and_the_snapshot_stays_frozen(): void
    {
        $report = $this->finalizedSwornStatement();
        $snapshot = CdaReportSnapshot::query()->findOrFail($report->current_snapshot_id);
        $view = fn () => $this->actingAs($this->preparer)->getJson("/api/cda/reports/{$report->id}")->assertOk()->json('data');

        $before = $view();
        $this->assertSame(88, $before['readiness']);
        $this->assertSame([7, 8], [$before['readiness_breakdown']['passed'], $before['readiness_breakdown']['total']]);
        $this->assertEquals(['passed' => 0, 'total' => 1, 'percent' => 0], $before['readiness_breakdown']['by_component']['documents']);
        $this->assertSame(['rule:signed_statement'], $before['readiness_breakdown']['failed']);
        $this->assertSame(1, $before['critical_count']);

        $document = CdaDocument::factory()->create(['period_id' => $report->period_id, 'category' => 'sworn_statement', 'report_id' => $report->id]);
        CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id, 'final_key' => (string) $document->id]);

        $after = $view();
        $this->assertSame(0, $after['critical_count'], 'The issues list has no missing document.');
        $this->assertSame(100, $after['readiness']);
        $this->assertSame([8, 8], [$after['readiness_breakdown']['passed'], $after['readiness_breakdown']['total']]);
        $this->assertEquals(['passed' => 1, 'total' => 1, 'percent' => 100], $after['readiness_breakdown']['by_component']['documents']);
        $this->assertSame([], $after['readiness_breakdown']['failed']);
        $this->assertEquals($before['readiness_breakdown']['by_component']['fields'], $after['readiness_breakdown']['by_component']['fields'], 'The other checks stay frozen.');
        $this->assertEquals($before['readiness_breakdown']['by_section'], $after['readiness_breakdown']['by_section']);
        $this->assertSame($snapshot->checksum, $after['snapshot_checksum']);

        $full = $this->userWith([BranchAccess::VIEW_ALL, CdaAccess::VIEW]);
        $dashboard = $this->actingAs($full)->getJson('/api/cda/periods/2026/dashboard')->assertOk()->json('data');
        $this->assertSame(100, collect($dashboard['reports'])->firstWhere('id', $report->id)['readiness']);
        $calendar = $this->actingAs($full)->getJson('/api/cda/periods/2026/calendar')->assertOk()->json('data');
        $this->assertSame(100, collect($calendar)->firstWhere('id', $report->id)['readiness']);
        $applicable = collect($dashboard['reports'])->where('applicable', true);
        $this->assertSame(Readiness::mean($applicable->pluck('readiness')->all()), $dashboard['readiness'], 'The period figure uses the live readiness.');

        $stored = CdaReport::query()->findOrFail($report->id);
        $this->assertSame(88, $stored->readiness, 'The stored readiness is never rewritten.');
        $this->assertSame(['rule:signed_statement'], $stored->readiness_breakdown['failed']);
        $frozen = CdaReportSnapshot::query()->findOrFail($snapshot->id);
        $this->assertSame($snapshot->checksum, $frozen->checksum);
        $this->assertSame($snapshot->payload, $frozen->payload);
        $this->assertSame(88, json_decode($frozen->payload, true)['readiness']);
    }

    public function test_a_check_counted_before_one_issue_per_document_still_follows_its_upload(): void
    {
        $frozen = Readiness::of([
            ['id' => 'required:a.b', 'component' => 'fields', 'section' => 'a', 'passed' => true],
            ['id' => 'rule:signed_capr_uploaded', 'component' => 'documents', 'section' => null, 'passed' => false],
            ['id' => 'document:signed_capr', 'component' => 'documents', 'section' => null, 'passed' => false],
            ['id' => 'approval', 'component' => 'approvals', 'section' => null, 'passed' => true],
        ]);
        $this->assertSame(50, $frozen['readiness']);

        $live = Readiness::withLiveChecks($frozen['readiness'], $frozen['breakdown'], [
            ['id' => 'rule:signed_capr_uploaded', 'component' => 'documents', 'section' => null, 'passed' => true, 'covered' => false],
            ['id' => 'document:signed_capr', 'component' => 'documents', 'section' => null, 'passed' => true, 'covered' => true],
            ['id' => 'document:not_counted', 'component' => 'documents', 'section' => null, 'passed' => false, 'covered' => true],
        ]);

        $this->assertSame(100, $live['readiness'], 'A listed document counted before the rule took it over still clears with the upload.');
        $this->assertSame([4, 4], [$live['breakdown']['passed'], $live['breakdown']['total']]);
        $this->assertSame(['passed' => 2, 'total' => 2, 'percent' => 100], $live['breakdown']['by_component']['documents']);
        $this->assertSame([], $live['breakdown']['failed']);

        $untouched = Readiness::withLiveChecks(70, [], [['id' => 'rule:x', 'component' => 'documents', 'section' => null, 'passed' => true, 'covered' => false]]);
        $this->assertSame(['readiness' => 70, 'breakdown' => []], $untouched, 'A breakdown without its check list is shown as stored.');
    }

    /** The seeded Sworn Statement, filled in, approved and finalized without its signed copy. */
    private function finalizedSwornStatement(): CdaReport
    {
        $report = $this->report($this->openYear(), 'sworn_statement');
        app(ReportEngine::class)->refresh($report);
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$report->id}", ['fields' => [
            ['field_key' => 'statement.signatory_name', 'value' => 'Rosa Lim'],
            ['field_key' => 'statement.signatory_position', 'value' => 'Chairperson'],
            ['field_key' => 'statement.statement_date', 'value' => '2027-01-10'],
            ['field_key' => 'statement.notarized', 'value' => true],
        ]])->assertOk();

        foreach ([['submit_for_review', $this->preparer], ['approve', $this->approver], ['finalize', $this->preparer]] as [$action, $user]) {
            $this->actingAs($user)->postJson("/api/cda/reports/{$report->id}/workflow", ['action' => $action, 'confirm_warnings' => true])->assertOk();
        }

        $report->refresh();
        $this->assertSame(CdaReport::STATUS_FINALIZED, $report->status);

        return $report;
    }
}
