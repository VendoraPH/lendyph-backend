<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaDocumentVersion;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Models\CdaReportValue;
use App\Services\Cda\Exports\ExportContent;
use App\Services\Cda\Exports\ExportContentBuilder;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\PeriodOpener;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;
use ZipArchive;

/**
 * The CDA browser check's export items: a finalized export of an unverified
 * template (PESOS, and every v1 template) prints the "to verify" note; the
 * official workbook's profile cells read in words; and the PDF footer's
 * facts keep their own column, clear of "Page X of Y".
 */
class ExportBrowserCheckFixesTest extends TestCase
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

    private function finalizeWithSnapshot(CdaReport $report, array $profile = []): void
    {
        $snapshot = CdaReportSnapshot::query()->create([
            'report_id' => $report->id,
            'version' => 1,
            'template_id' => $report->template_id,
            'payload' => json_encode(['profile' => ['stored' => $profile, 'pulled' => []], 'values' => []]),
            'checksum' => str_repeat('c', 64),
            'finalized_by' => $this->admin->id,
            'finalized_at' => now(),
        ]);
        $report->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id])->save();
    }

    private function html(CdaReport $report): string
    {
        return app(PdfRenderer::class)->html(app(ExportContentBuilder::class)->build($report->fresh(), $this->admin));
    }

    public function test_a_finalized_export_of_an_unverified_template_carries_the_note(): void
    {
        $pesos = $this->report('pesos');
        $this->assertFalse($pesos->template->isVerified());
        $this->finalizeWithSnapshot($pesos);

        $html = $this->html($pesos);
        $this->assertStringContainsString(ExportContent::UNVERIFIED_NOTE, $html);
        $this->assertSame('Figures from an unverified template; to be checked against the official CDA form.', ExportContent::UNVERIFIED_NOTE);

        $data = $this->actingAs($this->userWith(['cda:view', 'cda:export', 'branches:view_all']))
            ->postJson("/api/cda/reports/{$pesos->id}/exports", ['format' => 'xlsx'])->assertCreated()->json('data');
        $version = CdaDocumentVersion::query()->with('file')->findOrFail($data['id']);
        $this->assertStringContainsString(ExportContent::UNVERIFIED_NOTE, $this->sheet(Storage::disk('private')->get($version->file->file_path)));
    }

    public function test_a_verified_template_prints_no_note(): void
    {
        $capr = $this->report('capr');
        $definition = $capr->template->definition;
        $definition['meta']['verified'] = true;
        CdaReportTemplate::query()->whereKey($capr->template_id)->update(['definition' => json_encode($definition)]);
        $this->finalizeWithSnapshot($capr);

        $this->assertStringNotContainsString(ExportContent::UNVERIFIED_NOTE, $this->html($capr));
    }

    public function test_profile_cells_of_the_official_workbook_read_in_words(): void
    {
        CdaRegulatoryProfile::current()->update(['cooperative_category' => 'secondary', 'cte_status' => 'valid']);
        $capr = $this->report('capr');
        $content = app(ExportContentBuilder::class)->build($capr, $this->admin);

        $this->assertSame('Secondary', $content->profileValue('cooperative_category'));
        $this->assertSame('Valid', $content->profileValue('cte_status'));
        $this->assertSame('secondary', $content->profile['cooperative_category'], 'The stored key stays as it is.');
    }

    public function test_the_footer_text_keeps_clear_of_the_page_number(): void
    {
        $capr = $this->report('capr');
        CdaReportValue::query()->updateOrCreate(['report_id' => $capr->id, 'field_key' => 'membership.members_total'], ['value' => 5]);
        $this->finalizeWithSnapshot($capr);

        $dom = new DOMDocument;
        @$dom->loadHTML($this->html($capr));
        $xpath = new DOMXPath($dom);

        $text = $xpath->query('//table[contains(@class, "footer")]//td[contains(@class, "footer-text")]');
        $page = $xpath->query('//table[contains(@class, "footer")]//td[contains(@class, "footer-page")]');
        $this->assertSame(1, $text->length, 'The footer facts sit in their own cell.');
        $this->assertSame(1, $page->length, 'The page number has its own reserved cell.');
        $this->assertStringContainsString('checksum', $text->item(0)->textContent);
        $this->assertSame('', trim($page->item(0)->textContent), 'The renderer draws "Page X of Y" into the reserved cell.');

        $css = $xpath->query('//style')->item(0)->textContent;
        $this->assertMatchesRegularExpression('/td\.footer-page\s*\{[^}]*width:\s*'.PdfRenderer::PAGE_COLUMN_MM.'mm/', $css);
        $this->assertGreaterThanOrEqual(30, PdfRenderer::PAGE_COLUMN_MM);

        $pdf = app(PdfRenderer::class)->render(app(ExportContentBuilder::class)->build($capr->fresh(), $this->admin));
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    private function sheet(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-c5');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $sheet;
    }
}
