<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\AuditLog;
use App\Models\BrandingSetting;
use App\Models\CdaDocumentVersion;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Models\CdaReportValue;
use App\Models\Document;
use App\Models\User;
use App\Services\Cda\Exports\ExportContentBuilder;
use App\Services\Cda\Exports\ExportSupport;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\PeriodOpener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;
use ZipArchive;

/**
 * Server exports (CDA design §9): PDF through dompdf and Blade, Excel written
 * in-house, stored as a generated document version, audited, and downloaded
 * through a signed link. Drafts carry the DRAFT mark and a `-DRAFT` file
 * name; finalized reports export from their snapshot.
 */
class ReportExportsTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private const DRAFT_MARK = 'DRAFT — not for submission';

    private CdaReport $capr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $period = app(PeriodOpener::class)->ensure(2026);
        $this->capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exporter(array $extra = []): User
    {
        return $this->userWith(['cda:view', 'cda:export', 'branches:view_all', ...$extra]);
    }

    private function value(string $fieldKey, mixed $value): void
    {
        CdaReportValue::query()->updateOrCreate(['report_id' => $this->capr->id, 'field_key' => $fieldKey], ['value' => $value]);
    }

    private function finalize(array $values, array $profile = []): CdaReportSnapshot
    {
        $snapshot = CdaReportSnapshot::query()->create([
            'report_id' => $this->capr->id,
            'version' => 1,
            'template_id' => $this->capr->template_id,
            'payload' => json_encode([
                'profile' => ['stored' => $profile, 'pulled' => ['name' => ['value' => 'Snapshot Coop'], 'address' => ['value' => 'Old Street']]],
                'values' => array_map(fn ($key, $value) => ['field_key' => $key, 'value' => $value], array_keys($values), $values),
            ]),
            'checksum' => str_repeat('b', 64),
            'finalized_by' => $this->admin->id,
            'finalized_at' => now(),
        ]);
        $this->capr->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id])->save();

        return $snapshot;
    }

    /** @return array<string, string> the workbook's parts by name */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-test');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The export is a valid zip.');
        $parts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $parts;
    }

    private function storedBytes(int $versionId): string
    {
        $version = CdaDocumentVersion::query()->with('file')->findOrFail($versionId);

        return Storage::disk('private')->get($version->file->file_path);
    }

    public function test_a_draft_pdf_is_marked_draft_stored_and_audited(): void
    {
        $user = $this->exporter();

        $data = $this->actingAs($user)->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'pdf'])
            ->assertCreated()->json('data');

        $this->assertTrue($data['is_draft']);
        $this->assertSame(1, $data['version']);
        $this->assertSame('pdf', $data['format']);
        $this->assertStringEndsWith('-DRAFT.pdf', $data['file_name']);
        $this->assertStringContainsString('/api/files/documents/', $data['url']);
        $this->assertStringContainsString('signature=', $data['url']);
        $this->assertNull($data['snapshot_version']);

        $bytes = $this->storedBytes($data['id']);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('DejaVuSans', $bytes, 'DejaVu Sans is embedded, so ₱ renders.');
        $this->assertSame(hash('sha256', $bytes), $data['sha256']);

        $version = CdaDocumentVersion::query()->with('cdaDocument')->findOrFail($data['id']);
        $this->assertSame('generated', $version->origin);
        $this->assertTrue($version->is_draft);
        $this->assertSame($this->capr->id, $version->cdaDocument->report_id);

        $audit = AuditLog::query()->where('action', 'cda_export_generated')->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(CdaReport::class, $audit->auditable_type);
        $this->assertStringContainsString('PDF', (string) $audit->description);
        $this->assertStringContainsString('draft', (string) $audit->description);

        $again = $this->actingAs($user)->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'pdf'])->assertCreated();
        $this->assertSame(2, $again->json('data.version'));
    }

    public function test_the_pdf_page_carries_the_draft_mark_the_footer_peso_amounts_and_escapes_text(): void
    {
        $this->value('loans.loans_outstanding', '1500.00');
        $this->value('general.contact', '<script>alert(1)</script>');
        $user = $this->exporter();
        $user->forceFill(['first_name' => 'Liza', 'last_name' => "D'Souza"])->save();

        $html = app(PdfRenderer::class)->html(app(ExportContentBuilder::class)->build($this->capr, $user));

        $this->assertStringContainsString(self::DRAFT_MARK, $html);
        $this->assertStringContainsString('₱1,500.00', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('CY2026', $html);
        $this->assertStringContainsString('Template 2026.2', $html);
        $this->assertStringContainsString('by Liza D&#039;Souza', $html, 'The name is escaped once, as Blade prints it.');
        $this->assertStringNotContainsString('&amp;#039;', $html);
        $this->assertStringContainsString('Generated Nov 20, 2026', $html);
        $this->assertStringContainsString('DejaVu Sans', $html);
    }

    public function test_a_finalized_export_comes_from_the_snapshot_not_the_live_figures(): void
    {
        $snapshot = $this->finalize(['membership.members_total' => 120, 'general.cooperative_name' => 'Snapshot Coop'], ['cda_registration_no' => 'CDA-REG-0001']);
        $this->value('membership.members_total', 999);
        BrandingSetting::query()->update(['organization_name' => 'Renamed Coop 2027']);

        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertCreated()->json('data');

        $this->assertFalse($data['is_draft']);
        $this->assertStringEndsWith('.xlsx', $data['file_name']);
        $this->assertStringNotContainsString('DRAFT', $data['file_name']);
        $this->assertSame(1, $data['snapshot_version']);
        $this->assertSame($snapshot->id, CdaDocumentVersion::query()->findOrFail($data['id'])->snapshot_id);

        $sheet = $this->unzip($this->storedBytes($data['id']))['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('Snapshot Coop', $sheet);
        $this->assertStringContainsString('<v>120</v>', $sheet);
        $this->assertStringNotContainsString('999', $sheet);
        $this->assertStringNotContainsString('Renamed Coop', $sheet);
        $this->assertStringNotContainsString(self::DRAFT_MARK, $sheet);
        $this->assertStringContainsString(str_repeat('b', 64), $sheet, 'The footer names the snapshot checksum.');

        $html = app(PdfRenderer::class)->html(app(ExportContentBuilder::class)->build($this->capr->fresh(), $this->admin));
        $this->assertStringNotContainsString(self::DRAFT_MARK, $html);
        $this->assertStringContainsString('Snapshot v1', $html);
    }

    public function test_the_generated_xlsx_is_a_valid_workbook_with_inline_strings_and_no_formulas(): void
    {
        $this->value('membership.members_total', 42);
        $this->value('general.contact', '=HYPERLINK("http://example.test","x")');

        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertCreated()->json('data');

        $this->assertStringEndsWith('-DRAFT.xlsx', $data['file_name']);
        $parts = $this->unzip($this->storedBytes($data['id']));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
            $this->assertArrayHasKey($part, $parts);
        }

        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertNotFalse(simplexml_load_string($sheet), 'The sheet is well-formed XML.');
        $this->assertStringContainsString(self::DRAFT_MARK, $sheet);
        $this->assertStringContainsString('<v>42</v>', $sheet);
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('=HYPERLINK(&quot;http://example.test&quot;,&quot;x&quot;)', $sheet);
        $this->assertStringContainsString('<oddFooter>', $sheet);
    }

    public function test_template_mode_fills_the_mapped_cells_of_the_official_workbook(): void
    {
        $definition = $this->capr->template->definition;
        $definition['export']['excel'] = ['mode' => 'template', 'draft_cell' => 'Sheet1!A1', 'cells' => [
            'Sheet1!C5' => 'profile.cda_registration_no',
            'Sheet1!B7' => 'membership.members_total',
            'Sheet1!C3' => 'general.cooperative_name',
        ]];
        $path = 'cda/templates/test/official.xlsx';
        Storage::disk('private')->put($path, $this->officialWorkbook());
        $template = CdaReportTemplate::query()->create([
            'report_type' => 'capr', 'version' => '2026.9', 'name' => 'CAPR with the official workbook',
            'effective_from' => '2026-01-01', 'definition' => $definition, 'status' => CdaReportTemplate::STATUS_DRAFT,
        ]);
        $file = Document::query()->create([
            'documentable_type' => CdaReportTemplate::class, 'documentable_id' => $template->id, 'type' => 'cda_template_workbook',
            'file_path' => $path, 'original_filename' => 'official.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file_size' => 1,
        ]);
        $template->forceFill(['excel_template_document_id' => $file->id])->save();
        $this->capr->forceFill(['template_id' => $template->id])->save();
        $this->value('membership.members_total', 77);
        $this->value('general.cooperative_name', 'Bayanihan & Sons <Coop>');
        CdaRegulatoryProfile::current()->update(['cda_registration_no' => '9520-1234']);

        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertCreated()->json('data');

        $parts = $this->unzip($this->storedBytes($data['id']));
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertArrayNotHasKey('xl/calcChain.xml', $parts, 'calcChain.xml is dropped.');
        $this->assertStringNotContainsString('calcChain', $parts['[Content_Types].xml']);
        $this->assertStringNotContainsString('calcChain', $parts['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('fullCalcOnLoad="1"', $parts['xl/workbook.xml']);
        $this->assertMatchesRegularExpression('#<c r="A1" t="inlineStr"><is><t[^>]*>'.self::DRAFT_MARK.'</t></is></c>#u', $sheet);
        $this->assertMatchesRegularExpression('#<c r="C5" t="inlineStr"><is><t[^>]*>9520-1234</t></is></c>#', $sheet);
        $this->assertStringContainsString('<c r="B7"><v>77</v></c>', $sheet);
        $this->assertStringContainsString('Bayanihan &amp; Sons &lt;Coop&gt;', $sheet);
        $this->assertStringContainsString('<c r="D5" t="s"><v>0</v></c>', $sheet, 'Unmapped cells are kept.');
        $this->assertStringNotContainsString('<c r="C5" t="s">', $sheet, 'The mapped cell replaced the old one.');
        $this->assertNotFalse(simplexml_load_string($sheet));
    }

    public function test_the_signed_link_serves_the_file_and_a_tampered_one_is_refused(): void
    {
        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'pdf'])
            ->assertCreated()->json('data');
        $this->app['auth']->forgetGuards();

        $download = $this->get($data['url'])->assertOk();
        $this->assertSame($this->storedBytes($data['id']), $download->streamedContent());
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->get($data['url'].'x')->assertForbidden();
    }

    public function test_the_list_shows_each_export_with_a_signed_link_newest_first(): void
    {
        $user = $this->exporter();
        $this->actingAs($user)->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'pdf'])->assertCreated();
        $this->actingAs($user)->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])->assertCreated();

        $rows = $this->actingAs($this->userWith(['cda:view', 'branches:view_all']))
            ->getJson("/api/cda/reports/{$this->capr->id}/exports")->assertOk()->json('data');

        $this->assertSame(['xlsx', 'pdf'], array_column($rows, 'format'));
        $this->assertSame([1, 1], array_column($rows, 'version'));
        $this->assertStringContainsString('signature=', $rows[0]['url']);
        $this->assertSame($user->id, $rows[0]['generated_by']['id']);
    }

    public function test_permissions_restricted_reports_and_bad_input(): void
    {
        $uri = "/api/cda/reports/{$this->capr->id}/exports";

        $this->actingAs($this->userWith(['cda:view', 'branches:view_all']))->postJson($uri, ['format' => 'pdf'])->assertForbidden();
        $this->actingAs($this->userWith(['cda:view', 'cda:export']))->postJson($uri, ['format' => 'pdf'])->assertForbidden();
        $this->actingAs($this->userWith(['cda:export', 'branches:view_all']))->getJson($uri)->assertForbidden();
        $this->actingAs($this->userWith(['cda:view', 'cda:export']))->getJson($uri)->assertForbidden();
        $this->actingAs($this->userWith(['cda:view', 'branches:view_all']))->getJson($uri)->assertOk();
        $this->actingAs($this->exporter())->postJson($uri, ['format' => 'docx'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.format.0', 'Pick PDF (pdf) or Excel (xlsx).');

        $statements = CdaReport::query()->where('period_id', $this->capr->period_id)->where('report_type', 'financial_statements')->firstOrFail();
        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$statements->id}/exports", ['format' => 'pdf'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Audited Financial Statements is restricted: it needs the cda:view_audit permission.');
        $this->actingAs($this->exporter())->getJson("/api/cda/reports/{$statements->id}/exports")->assertForbidden();
        $this->actingAs($this->exporter(['cda:view_audit']))->postJson("/api/cda/reports/{$statements->id}/exports", ['format' => 'pdf'])->assertCreated();
        $this->actingAs($this->exporter())->postJson('/api/cda/reports/999999/exports', ['format' => 'pdf'])->assertNotFound();
    }

    public function test_excel_without_the_zip_extension_answers_503_in_words(): void
    {
        $this->app->instance(ExportSupport::class, new class extends ExportSupport
        {
            public function zipAvailable(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertStatus(503)
            ->assertJsonPath('message', "Excel exports need the PHP zip extension, which this server doesn't have. Ask your administrator to install it; PDF exports still work.");
        $this->assertSame(0, CdaDocumentVersion::query()->count());
    }

    /** The fixture workbook with a calculation chain and a cell already in C5 and D5. */
    private function officialWorkbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-official');
        copy(base_path('tests/Fixtures/cda/blank.xlsx'), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="5"><c r="C5" t="s"><v>0</v></c><c r="D5" t="s"><v>0</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->addFromString('xl/calcChain.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><calcChain xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><c r="C5" i="1"/></calcChain>');
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', str_replace('</Types>', '<Override PartName="/xl/calcChain.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.calcChain+xml"/></Types>', $types));
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/calcChain" Target="calcChain.xml"/></Relationships>', $rels));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
