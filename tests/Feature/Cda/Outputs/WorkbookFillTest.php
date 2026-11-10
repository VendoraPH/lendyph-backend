<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaDocumentVersion;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Models\CdaReportValue;
use App\Models\Document;
use App\Models\User;
use App\Services\Cda\Exports\XlsxTemplateFiller;
use App\Services\Cda\PeriodOpener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;
use ZipArchive;

/**
 * Filling the official workbook (CDA design §9, review fixes M2–M4, L5–L8):
 * absolute references fill, a draft needs a writable DRAFT cell, sheets that
 * aren't found are noted, only money, percent and number fields become
 * numbers, and a workbook with a DOCTYPE, an oversized part or cells without
 * references is refused in words. Control characters never reach the XML.
 */
class WorkbookFillTest extends TestCase
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

    private function value(string $fieldKey, mixed $value): void
    {
        CdaReportValue::query()->updateOrCreate(['report_id' => $this->capr->id, 'field_key' => $fieldKey], ['value' => $value]);
    }

    /**
     * Gives the CAPR a template whose Excel block maps these cells into a
     * workbook built from the fixture, with this Sheet1 XML.
     *
     * @param  array<string, string>  $cells
     */
    private function useWorkbook(array $cells, ?string $draftCell = 'Sheet1!A1', ?string $sheetXml = null): void
    {
        $definition = $this->capr->template->definition;
        $definition['export']['excel'] = array_filter(['mode' => 'template', 'draft_cell' => $draftCell, 'cells' => $cells], fn ($v) => $v !== null);
        $path = 'cda/templates/test/official-'.uniqid().'.xlsx';
        Storage::disk('private')->put($path, $this->workbook($sheetXml));
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

    private function workbook(?string $sheetXml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-official');
        copy(base_path('tests/Fixtures/cda/blank.xlsx'), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml ?? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="5"><c r="C5" t="s"><v>0</v></c></row></sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function finalize(): void
    {
        $snapshot = CdaReportSnapshot::query()->create([
            'report_id' => $this->capr->id, 'version' => 1, 'template_id' => $this->capr->template_id,
            'payload' => json_encode(['values' => [['field_key' => 'membership.members_total', 'value' => 12]]]),
            'checksum' => str_repeat('c', 64), 'finalized_by' => $this->admin->id, 'finalized_at' => now(),
        ]);
        $this->capr->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id])->save();
    }

    /** @return array{0: array<string, mixed>, 1: string} the response data and Sheet1's XML */
    private function exportSheet(): array
    {
        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertCreated()->json('data');
        $version = CdaDocumentVersion::query()->with('file')->findOrFail($data['id']);
        $path = tempnam(sys_get_temp_dir(), 'cda-read');
        file_put_contents($path, Storage::disk('private')->get($version->file->file_path));
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return [$data, $sheet];
    }

    public function test_absolute_references_fill(): void
    {
        $this->useWorkbook(['Sheet1!$C$5' => 'membership.members_total', 'Sheet1!$B$2' => 'general.contact'], 'Sheet1!$A$1');
        $this->value('membership.members_total', 77);
        $this->value('general.contact', 'Front desk');

        [, $sheet] = $this->exportSheet();

        $this->assertStringContainsString('<c r="C5"><v>77</v></c>', $sheet);
        $this->assertMatchesRegularExpression('#<c r="B2" t="inlineStr"><is><t[^>]*>Front desk</t></is></c>#', $sheet);
        $this->assertMatchesRegularExpression('#<c r="A1" t="inlineStr"><is><t[^>]*>DRAFT — not for submission</t>#u', $sheet);
        $this->assertStringNotContainsString('$', $sheet);
    }

    public function test_a_draft_needs_a_writable_draft_cell(): void
    {
        $message = "This template's official workbook has no cell for the DRAFT mark, so a draft can't be exported from it. A super admin can set export.excel.draft_cell in a new template version; a finalized report exports without it.";

        $this->useWorkbook(['Sheet1!C5' => 'membership.members_total'], null);
        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertUnprocessable()->assertJsonPath('message', $message);

        $this->useWorkbook(['Sheet1!C5' => 'membership.members_total'], 'Missing!A1');
        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertUnprocessable()->assertJsonPath('message', $message);
        $this->assertSame(0, CdaDocumentVersion::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles('cda/exports'), 'No file is kept.');

        $this->finalize();
        [$data] = $this->exportSheet();
        $this->assertFalse($data['is_draft'], 'A finalized report needs no DRAFT cell.');
    }

    public function test_sheets_that_are_not_found_are_noted(): void
    {
        $this->useWorkbook(['Sheet1!C5' => 'membership.members_total', 'Officers!B2' => 'officer_summary.chairperson', "'Annex A'!C3" => 'general.tin']);

        [$data] = $this->exportSheet();

        $this->assertStringContainsString('Filled into the official workbook.', $data['notes']);
        $this->assertStringContainsString('These mapped sheets are not in the workbook, so their cells were left empty: Annex A, Officers.', $data['notes']);
    }

    public function test_only_money_percent_and_number_fields_become_numbers(): void
    {
        $this->useWorkbook([
            'Sheet1!B1' => 'profile.cda_registration_no',
            'Sheet1!B2' => 'general.contact',
            'Sheet1!B3' => 'membership.members_total',
            'Sheet1!B4' => 'financial.net_surplus',
            'Sheet1!B5' => 'loans.par_30',
        ]);
        CdaRegulatoryProfile::current()->update(['cda_registration_no' => '00123']);
        $this->value('general.contact', '0917');
        $this->value('membership.members_total', '42');
        $this->value('financial.net_surplus', '-400.00');
        $this->value('loans.par_30', '3.50');

        [, $sheet] = $this->exportSheet();

        $this->assertMatchesRegularExpression('#<c r="B1" t="inlineStr"><is><t[^>]*>00123</t></is></c>#', $sheet, 'Profile values keep leading zeros.');
        $this->assertMatchesRegularExpression('#<c r="B2" t="inlineStr"><is><t[^>]*>0917</t></is></c>#', $sheet, 'Text fields stay text.');
        $this->assertStringContainsString('<c r="B3"><v>42</v></c>', $sheet);
        $this->assertStringContainsString('<c r="B4"><v>-400.00</v></c>', $sheet, 'Negative money stays a signed number.');
        $this->assertStringContainsString('<c r="B5"><v>3.50</v></c>', $sheet);
    }

    public function test_control_characters_never_reach_the_xml(): void
    {
        $this->useWorkbook(['Sheet1!B2' => 'general.contact']);
        $this->value('general.contact', "Front\x01 desk\x0B");

        [, $sheet] = $this->exportSheet();
        $this->assertNotFalse(simplexml_load_string($sheet));
        $this->assertMatchesRegularExpression('#<t[^>]*>Front desk</t>#', $sheet);

        $this->capr->forceFill(['template_id' => CdaReportTemplate::query()->where('report_type', 'capr')->where('status', 'active')->value('id')])->save();
        [, $generated] = $this->exportSheet();
        $this->assertNotFalse(simplexml_load_string($generated), 'The generated layout strips them too.');
        $this->assertStringContainsString('Front desk', $generated);
    }

    public function test_a_workbook_with_a_doctype_is_refused(): void
    {
        $this->useWorkbook(['Sheet1!C5' => 'membership.members_total'], 'Sheet1!A1', '<?xml version="1.0"?><!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="5"><c r="C5"><v>&x;</v></c></row></sheetData></worksheet>');

        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The official workbook attached to this template holds a document type declaration, which exports refuse. A super admin can save it again in Excel and attach it to a new template version.');
        $this->assertSame(0, CdaDocumentVersion::query()->count());
    }

    public function test_cells_or_rows_without_references_are_refused(): void
    {
        $message = 'The official workbook attached to this template has rows or cells without a reference (r), so its cells can\'t be found. A super admin can save it again in Excel and attach it to a new template version.';

        $this->useWorkbook(['Sheet1!C5' => 'membership.members_total'], 'Sheet1!A1', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="5"><c t="s"><v>0</v></c></row></sheetData></worksheet>');
        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertUnprocessable()->assertJsonPath('message', $message);

        $this->useWorkbook(['Sheet1!C5' => 'membership.members_total'], 'Sheet1!A1', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row><c r="C5"><v>0</v></c></row></sheetData></worksheet>');
        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertUnprocessable()->assertJsonPath('message', $message);
    }

    public function test_an_oversized_workbook_part_is_refused(): void
    {
        $this->assertSame(50 * 1024 * 1024, XlsxTemplateFiller::MAX_PART_BYTES);
        $this->app->instance(XlsxTemplateFiller::class, new XlsxTemplateFiller(maxPartBytes: 200));
        $this->useWorkbook(['Sheet1!C5' => 'membership.members_total'], 'Sheet1!A1', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.str_repeat('<row r="9"/>', 40).'</sheetData></worksheet>');

        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The official workbook attached to this template is too large to fill (a part is over 50 MB unpacked). A super admin can attach a smaller workbook to a new template version.');
    }
}
