<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaDocumentVersion;
use App\Models\CdaReport;
use App\Models\CdaReportTemplate;
use App\Models\CdaReportValue;
use App\Models\Document;
use App\Models\User;
use App\Services\Cda\PeriodOpener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;
use ZipArchive;

/**
 * Phase 3 follow-ups, item B5. An export that fills the official workbook
 * (template mode) only writes the mapped cells, so it printed no "to verify"
 * note. While the template is unverified (the other exports' rule:
 * `meta.verified`, PesosSource::verified() for PESOS) it gets a separate
 * "Notes" sheet at the end, whose first line says so, and the document
 * properties' Subject reads "To verify". The official form's own sheets and
 * cells are left as the fill writes them.
 */
class WorkbookVerifyNoteTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private CdaReport $capr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $period = app(PeriodOpener::class)->ensure(2026);
        $this->capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        CdaReportValue::query()->updateOrCreate(['report_id' => $this->capr->id, 'field_key' => 'membership.members_total'], ['value' => 77]);
        CdaReportValue::query()->updateOrCreate(['report_id' => $this->capr->id, 'field_key' => 'general.contact'], ['value' => 'Front desk']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exporter(): User
    {
        return $this->userWith(['cda:view', 'cda:export', 'branches:view_all']);
    }

    /** Gives the CAPR a template-mode template, verified or not, over the blank fixture workbook. */
    private function useWorkbook(bool $verified, ?string $extraSheet = null, ?string $appXml = null): void
    {
        $definition = $this->capr->template->definition;
        $definition['meta']['verified'] = $verified;
        $definition['export']['excel'] = ['mode' => 'template', 'draft_cell' => 'Sheet1!A1', 'cells' => ['Sheet1!C5' => 'membership.members_total', 'Sheet1!B2' => 'general.contact']];
        $path = 'cda/templates/test/official-'.uniqid().'.xlsx';
        Storage::disk('private')->put($path, $this->workbook($extraSheet, $appXml));
        $template = CdaReportTemplate::query()->create([
            'report_type' => 'capr', 'version' => '2026.'.random_int(10, 99999), 'name' => 'CAPR with the official workbook',
            'effective_from' => '2026-01-01', 'definition' => $definition, 'status' => CdaReportTemplate::STATUS_DRAFT,
        ]);
        $file = Document::query()->create([
            'documentable_type' => CdaReportTemplate::class, 'documentable_id' => $template->id, 'type' => 'cda_template_workbook',
            'file_path' => $path, 'original_filename' => 'official.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file_size' => 1,
        ]);
        $template->forceFill(['excel_template_document_id' => $file->id])->save();
        $this->capr->forceFill(['template_id' => $template->id])->save();
    }

    /** The blank fixture with a value in C5, optionally with a second sheet of this name. */
    private function workbook(?string $extraSheet, ?string $appXml = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-official');
        copy(base_path('tests/Fixtures/cda/blank.xlsx'), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="5"><c r="C5" t="s"><v>0</v></c></row></sheetData></worksheet>');

        if ($extraSheet !== null) {
            $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');
            $zip->addFromString('xl/workbook.xml', str_replace('</sheets>', '<sheet name="'.$extraSheet.'" sheetId="2" r:id="rId2"/></sheets>', (string) $zip->getFromName('xl/workbook.xml')));
            $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>', (string) $zip->getFromName('xl/_rels/workbook.xml.rels')));
        }

        if ($appXml !== null) {
            $zip->addFromString('docProps/app.xml', $appXml);
            $zip->addFromString('[Content_Types].xml', str_replace('</Types>', '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>', (string) $zip->getFromName('[Content_Types].xml')));
            $zip->addFromString('_rels/.rels', str_replace('</Relationships>', '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>', (string) $zip->getFromName('_rels/.rels')));
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** @return array<string, string> every part of the exported workbook by name */
    private function exportParts(): array
    {
        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertCreated()->json('data');
        $version = CdaDocumentVersion::query()->with('file')->findOrFail($data['id']);
        $path = tempnam(sys_get_temp_dir(), 'cda-read');
        file_put_contents($path, Storage::disk('private')->get($version->file->file_path));
        $zip = new ZipArchive;
        $zip->open($path);
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $parts;
    }

    /** @return list<string> the workbook's sheet names, in order */
    private static function sheetNames(array $parts): array
    {
        preg_match_all('/<sheet [^>]*name="([^"]+)"/', $parts['xl/workbook.xml'], $m);

        return $m[1];
    }

    /** The part a sheet name points at, through the workbook's relationships. */
    private static function sheetPart(array $parts, string $name): string
    {
        preg_match('/<sheet [^>]*name="'.preg_quote($name, '/').'"[^>]*r:id="([^"]+)"/', $parts['xl/workbook.xml'], $id);
        preg_match('/<Relationship [^>]*Id="'.preg_quote($id[1], '/').'"[^>]*Target="([^"]+)"/', $parts['xl/_rels/workbook.xml.rels'], $target);

        return 'xl/'.$target[1];
    }

    public function test_an_unverified_template_gets_a_notes_sheet_and_a_to_verify_subject(): void
    {
        $this->useWorkbook(verified: false);

        $parts = $this->exportParts();

        $this->assertSame(['Sheet1', 'Notes'], self::sheetNames($parts));
        $notes = $parts[self::sheetPart($parts, 'Notes')];
        $this->assertMatchesRegularExpression('#<row r="1"><c r="A1" t="inlineStr"><is><t[^>]*>Template not yet verified against the official CDA form</t></is></c></row>#', $notes);
        $this->assertStringContainsString('/xl/worksheets/'.basename(self::sheetPart($parts, 'Notes')).'" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"', $parts['[Content_Types].xml']);

        $this->assertArrayHasKey('docProps/core.xml', $parts);
        $this->assertStringContainsString('<dc:subject>To verify</dc:subject>', $parts['docProps/core.xml']);
        $this->assertStringContainsString('Target="docProps/core.xml"', $parts['_rels/.rels']);
        $this->assertStringContainsString('PartName="/docProps/core.xml"', $parts['[Content_Types].xml']);
        $this->assertStringContainsString('fullCalcOnLoad="1"', $parts['xl/workbook.xml']);
    }

    public function test_a_verified_template_gets_neither(): void
    {
        $this->useWorkbook(verified: true);

        $parts = $this->exportParts();

        $this->assertSame(['Sheet1'], self::sheetNames($parts));
        $this->assertStringNotContainsString('To verify', $parts['docProps/core.xml'] ?? '');
        $this->assertStringNotContainsString('Template not yet verified', implode('', $parts));
    }

    public function test_the_mapped_cells_are_the_same_either_way(): void
    {
        $this->useWorkbook(verified: true);
        $verified = $this->exportParts()['xl/worksheets/sheet1.xml'];

        $this->useWorkbook(verified: false);
        $unverified = $this->exportParts()['xl/worksheets/sheet1.xml'];

        $this->assertSame($verified, $unverified);
        $this->assertStringContainsString('<c r="C5"><v>77</v></c>', $unverified);
        $this->assertMatchesRegularExpression('#<c r="B2" t="inlineStr"><is><t[^>]*>Front desk</t></is></c>#', $unverified);
    }

    public function test_the_notes_sheet_joins_the_document_properties_sheet_list(): void
    {
        $this->useWorkbook(verified: false, appXml: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Microsoft Excel</Application>'
            .'<HeadingPairs><vt:vector size="4" baseType="variant">'
            .'<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant>'
            .'<vt:variant><vt:lpstr>Named Ranges</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant>'
            .'</vt:vector></HeadingPairs>'
            .'<TitlesOfParts><vt:vector size="2" baseType="lpstr"><vt:lpstr>Sheet1</vt:lpstr><vt:lpstr>Sheet1!Print_Area</vt:lpstr></vt:vector></TitlesOfParts>'
            .'</Properties>');

        $app = $this->exportParts()['docProps/app.xml'];

        $this->assertStringContainsString('<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>2</vt:i4></vt:variant>', $app);
        $this->assertStringContainsString('<vt:variant><vt:lpstr>Named Ranges</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant>', $app);
        $this->assertStringContainsString('<TitlesOfParts><vt:vector size="3" baseType="lpstr"><vt:lpstr>Sheet1</vt:lpstr><vt:lpstr>Notes</vt:lpstr><vt:lpstr>Sheet1!Print_Area</vt:lpstr></vt:vector></TitlesOfParts>', $app);
        $this->assertStringContainsString('<Application>Microsoft Excel</Application>', $app);
    }

    public function test_a_verified_template_leaves_the_document_properties_sheet_list_alone(): void
    {
        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            .'<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Sheet1</vt:lpstr></vt:vector></TitlesOfParts></Properties>';
        $this->useWorkbook(verified: true, appXml: $appXml);

        $this->assertSame($appXml, $this->exportParts()['docProps/app.xml']);
    }

    public function test_a_workbook_that_already_has_a_notes_sheet_keeps_it(): void
    {
        $this->useWorkbook(verified: false, extraSheet: 'Notes');

        $parts = $this->exportParts();

        $this->assertSame(['Sheet1', 'Notes', 'Notes (2)'], self::sheetNames($parts));
        $this->assertSame('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>', $parts['xl/worksheets/sheet2.xml']);
        $this->assertStringContainsString('Template not yet verified against the official CDA form', $parts[self::sheetPart($parts, 'Notes (2)')]);
    }
}
