<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\AuditLog;
use App\Models\BrandingSetting;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\Cda\Exports\ExportContentBuilder;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Reports\ReportDefinition;
use App\Services\Cda\Reports\ReportStages;
use App\Services\Cda\Reports\SnapshotWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;
use ZipArchive;

/**
 * C4 against C2 and C3 (integration): exports read C2's real snapshot
 * payload, the current document stage (not one frozen at finalize), mark the
 * sworn statement generated on its first export, and download through C3's
 * signed links under the report's view permissions.
 */
class ExportIntegrationTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $this->periodId = app(PeriodOpener::class)->ensure(2026)->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function report(string $type): CdaReport
    {
        return CdaReport::query()->where('period_id', $this->periodId)->where('report_type', $type)->firstOrFail();
    }

    private function exporter(array $extra = []): User
    {
        return $this->userWith(['cda:view', 'cda:export', 'branches:view_all', ...$extra]);
    }

    private function value(CdaReport $report, string $key, mixed $value): void
    {
        CdaReportValue::query()->updateOrCreate(['report_id' => $report->id, 'field_key' => $key], ['value' => $value]);
    }

    /** Finalizes the report through C2's own SnapshotWriter. */
    private function finalize(CdaReport $report): CdaReportSnapshot
    {
        $report->load(['period', 'template']);
        $snapshot = app(SnapshotWriter::class)->write($report, new ReportDefinition($report->template->definition), $this->admin, false);
        $report->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id, 'finalized_by' => $this->admin->id, 'finalized_at' => now()])->save();

        return $snapshot;
    }

    private function html(CdaReport $report): string
    {
        return app(PdfRenderer::class)->html(app(ExportContentBuilder::class)->build($report->fresh(), $this->admin));
    }

    public function test_an_export_reads_c2s_real_snapshot_payload(): void
    {
        $capr = $this->report('capr');
        BrandingSetting::current()->update(['organization_name' => 'Bayanihan Coop', 'organization_address' => 'Old Street']);
        $this->value($capr, 'membership.members_total', 120);
        $this->value($capr, 'loans.loans_outstanding', '-400.00');
        $snapshot = $this->finalize($capr);

        BrandingSetting::current()->update(['organization_name' => 'Renamed Coop 2027']);
        $this->value($capr, 'membership.members_total', 999);

        $html = $this->html($capr);
        $this->assertStringContainsString('Bayanihan Coop', $html);
        $this->assertStringContainsString('Old Street', $html);
        $this->assertStringNotContainsString('Renamed Coop', $html);
        $this->assertStringContainsString('(₱400.00)', $html);
        $this->assertStringContainsString('Template '.$capr->template->version, $html);
        $this->assertStringContainsString($snapshot->checksum, $html);

        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$capr->id}/exports", ['format' => 'xlsx'])->assertCreated()->json('data');
        $version = CdaDocumentVersion::query()->with('file')->findOrFail($data['id']);
        $path = tempnam(sys_get_temp_dir(), 'cda-int');
        file_put_contents($path, Storage::disk('private')->get($version->file->file_path));
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('<v>120</v>', $sheet);
        $this->assertStringNotContainsString('999', $sheet);
        $this->assertSame($snapshot->id, $version->snapshot_id);
    }

    public function test_financial_statements_print_the_current_stage_not_the_snapshots(): void
    {
        $statements = $this->report('financial_statements');
        $this->finalize($statements);

        $draft = $this->html($statements);
        $this->assertStringContainsString('Draft Financial Statements', $draft);
        $this->assertStringContainsString('Stage: Draft financial statements', $draft);

        $this->value($statements, ReportStages::KEY, 'audited');

        $audited = $this->html($statements);
        $this->assertStringNotContainsString('Draft Financial Statements', $audited);
        $this->assertStringContainsString('<h1>Audited Financial Statements</h1>', $audited);
        $this->assertStringContainsString('Stage: Audited', $audited);
    }

    public function test_the_sworn_statements_first_export_marks_it_generated(): void
    {
        $sworn = $this->report('sworn_statement');
        $this->assertNull(ReportStages::current($sworn));

        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$sworn->id}/exports", ['format' => 'pdf'])->assertCreated();

        $this->assertSame('generated', ReportStages::current($sworn));
        $stageRows = fn () => AuditLog::query()->where('auditable_type', CdaReport::class)->where('auditable_id', $sworn->id)->where('action', 'cda_workflow')->count();
        $this->assertSame(1, $stageRows());

        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$sworn->id}/exports", ['format' => 'xlsx'])->assertCreated();
        $this->assertSame('generated', ReportStages::current($sworn));
        $this->assertSame(1, $stageRows(), 'Only the first export sets the stage.');
        $this->assertStringContainsString('Stage: Generated', $this->html($sworn));

        $capr = $this->report('capr');
        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$capr->id}/exports", ['format' => 'pdf'])->assertCreated();
        $this->assertNull(ReportStages::current($capr), 'Other reports have no stage.');
    }

    public function test_an_export_downloads_with_its_signed_link_only_for_those_who_may_see_the_report(): void
    {
        $statements = $this->report('financial_statements');
        $owner = $this->exporter(['cda:view_audit']);
        $data = $this->actingAs($owner)->postJson("/api/cda/reports/{$statements->id}/exports", ['format' => 'pdf'])->assertCreated()->json('data');
        $this->assertSame('report_export', CdaDocument::query()->whereKey($data['document_id'])->value('category'));

        $this->actingAs($owner)->get($data['url'])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'downloaded')->count(), 'Each download is audited (C3).');

        $this->actingAs($this->exporter())->get($data['url'])->assertNotFound();
        $this->actingAs($this->userWith(['branches:view_all', 'cda:view_audit']))->get($data['url'])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->get($data['url'])->assertOk();
        $owner->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $this->get($data['url'])->assertNotFound();
    }
}
