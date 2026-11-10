<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaMediationCase;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReport;
use App\Models\CdaSubmission;
use App\Services\Cda\CdaAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The compliance document repository (CDA design §1, §3): every upload is a
 * new version, never overwritten; one version per document is the final one;
 * withdrawing keeps every file; files are served only by signed links;
 * uploads take UploadFileTypes plus .xls and .xlsx; audit, tax and mediation
 * documents need their view permission.
 */
class CdaDocumentsTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        Storage::fake('private');
        $this->period();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $fields */
    private function upload(array $fields, string $url = '/api/cda/periods/2026/documents'): TestResponse
    {
        return $this->post($url, $fields, ['Accept' => 'application/json']);
    }

    private function pdf(string $name = 'board-resolution.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 12, 'application/pdf');
    }

    public function test_versions_are_added_and_never_overwritten_and_one_is_final(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));

        $document = $this->upload(['title' => 'Board minutes', 'category' => 'minutes', 'file' => $this->pdf('v1.pdf'), 'final' => '1', 'notes' => 'First draft'])
            ->assertCreated()->json('data');
        $this->assertSame(1, $document['version_count']);
        $this->assertSame(1, $document['current_version']['version']);
        $this->assertTrue($document['current_version']['is_final']);
        $this->assertSame(64, strlen($document['current_version']['sha256']));
        $this->assertSame('Minutes of meetings', $document['category_label']);

        $second = $this->upload(['file' => $this->pdf('v2.pdf')], "/api/cda/documents/{$document['id']}/versions")->assertCreated()->json('data');
        $this->assertSame(2, $second['current_version']['version']);
        $this->assertSame(1, $second['final_version']['version']);
        $this->assertFalse($second['current_version']['is_final']);

        $third = $this->upload(['file' => $this->pdf('v3.pdf'), 'final' => '1', 'version_label' => 'Signed'], "/api/cda/documents/{$document['id']}/versions")->assertCreated()->json('data');
        $this->assertSame(3, $third['final_version']['version']);
        $this->assertSame('Signed', $third['final_version']['version_label']);

        $versions = $this->getJson("/api/cda/documents/{$document['id']}/versions")->assertOk()->json('data');
        $this->assertSame([3, 2, 1], array_column($versions, 'version'));
        $this->assertSame([true, false, false], array_column($versions, 'is_final'));
        $this->assertSame(['v3.pdf', 'v2.pdf', 'v1.pdf'], array_column($versions, 'file_name'));
        $this->assertSame(1, CdaDocumentVersion::query()->where('cda_document_id', $document['id'])->whereNotNull('final_key')->count());
        foreach (CdaDocumentVersion::query()->where('cda_document_id', $document['id'])->with('file')->get() as $version) {
            Storage::disk('private')->assertExists($version->file->file_path);
        }
    }

    public function test_files_are_served_only_by_signed_links(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));
        $url = $this->upload(['title' => 'Certificate of registration', 'category' => 'other', 'file' => $this->pdf()])->json('data.current_version.url');

        $this->assertStringContainsString('signature=', $url);
        $this->openAsBrowser($url)->assertOk();
        $this->openAsBrowser(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
        $this->openAsBrowser(strtok($url, '?'))->assertForbidden();

        Carbon::setTestNow(now()->addMinutes(31));
        $this->openAsBrowser($url)->assertForbidden();
    }

    public function test_uploads_take_the_document_types_plus_excel_and_refuse_the_rest_in_words(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));

        $this->upload(['title' => 'Trial balance', 'category' => 'other', 'file' => UploadedFile::fake()->createWithContent('tb.xlsx', file_get_contents(base_path('tests/Fixtures/cda/blank.xlsx')))])
            ->assertCreated()->assertJsonPath('data.current_version.format', 'xlsx');
        $this->upload(['title' => 'Old trial balance', 'category' => 'other', 'file' => UploadedFile::fake()->create('tb.xls', 10, 'application/vnd.ms-excel')])
            ->assertCreated()->assertJsonPath('data.current_version.format', 'xls');
        $this->upload(['title' => 'Photo', 'category' => 'other', 'file' => UploadedFile::fake()->create('photo.jfif', 10, 'image/jpeg')])->assertCreated();

        $this->upload(['title' => 'Bad', 'category' => 'other', 'file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The file must be a picture (JPG or PNG), a PDF, a Word document or an Excel workbook.');
        $this->upload(['title' => 'Renamed', 'category' => 'other', 'file' => UploadedFile::fake()->create('report.bat', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', "The file's name must end in .jpg, .jpeg, .jpe, .jfif, .png, .pdf, .doc, .docx, .xls or .xlsx.");

        $errors = $this->upload(['category' => 'nonsense'])->assertUnprocessable()->json('errors');
        $this->assertSame(['Choose a file to upload.'], $errors['file']);
        $this->assertSame(['Give the document a title.'], $errors['title']);
        $this->assertSame(['Choose a category from the list.'], $errors['category']);
    }

    public function test_withdrawing_keeps_every_file_and_refuses_new_versions(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));
        $id = $this->upload(['title' => 'Old bylaws', 'category' => 'other', 'file' => $this->pdf()])->json('data.id');

        $this->postJson("/api/cda/documents/{$id}/withdraw", [])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Give the reason the document is withdrawn.');
        $this->postJson("/api/cda/documents/{$id}/withdraw", ['reason' => 'Superseded by the amended bylaws'])
            ->assertOk()->assertJsonPath('data.status', 'withdrawn')->assertJsonPath('data.withdraw_reason', 'Superseded by the amended bylaws');

        $version = CdaDocumentVersion::query()->where('cda_document_id', $id)->with('file')->sole();
        Storage::disk('private')->assertExists($version->file->file_path);
        $this->postJson("/api/cda/documents/{$id}/withdraw", ['reason' => 'again'])->assertConflict();
        $this->upload(['file' => $this->pdf()], "/api/cda/documents/{$id}/versions")->assertConflict()
            ->assertJsonPath('message', 'This document was withdrawn, so it takes no new versions.');
        $this->assertSame('withdrawn', $this->getJson("/api/cda/documents/{$id}/versions")->assertOk()->json('meta.document.status'));
    }

    public function test_audit_tax_and_mediation_documents_need_their_view_permission(): void
    {
        $period = $this->period();
        $fs = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'financial_statements')->sole();
        $audit = CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'audit_report']);
        $byReport = CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'other', 'report_id' => $fs->id]);
        $open = CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'minutes']);
        $tax = CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'tax']);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));
        $this->assertSame([$open->id], array_column($this->getJson('/api/cda/periods/2026/documents')->assertOk()->json('data'), 'id'));
        $this->getJson("/api/cda/documents/{$audit->id}/versions")->assertForbidden();
        $this->getJson("/api/cda/documents/{$byReport->id}/versions")->assertForbidden();
        $this->postJson("/api/cda/documents/{$tax->id}/withdraw", ['reason' => 'x'])->assertForbidden();
        $this->upload(['title' => 'AFS', 'category' => 'audit_report', 'file' => $this->pdf()])->assertForbidden();
        $this->upload(['title' => 'Mediation minutes', 'category' => 'mediation', 'file' => $this->pdf()])->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD, CdaAccess::VIEW_AUDIT]));
        $ids = array_column($this->getJson('/api/cda/periods/2026/documents')->json('data'), 'id');
        sort($ids);
        $this->assertSame([$audit->id, $byReport->id, $open->id], $ids);
        $this->getJson("/api/cda/documents/{$audit->id}/versions")->assertOk();
        $this->upload(['title' => 'AFS', 'category' => 'audit_report', 'file' => $this->pdf(), 'report_id' => $fs->id])->assertCreated()->assertJsonPath('data.report_type', 'financial_statements');
    }

    public function test_a_report_from_another_period_is_refused(): void
    {
        $other = CdaReport::query()->where('period_id', $this->period(2025)->id)->where('report_type', 'capr')->sole();
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));

        $this->upload(['title' => 'CAPR', 'category' => 'capr', 'report_id' => $other->id, 'file' => $this->pdf()])
            ->assertUnprocessable()->assertJsonPath('errors.report_id.0', 'Choose a report of the 2026 period.');
    }

    public function test_every_document_route_needs_its_permission_and_view_all(): void
    {
        $document = CdaDocument::factory()->create(['period_id' => $this->period()->id, 'category' => 'minutes']);

        $this->actingAs($this->userWith([CdaAccess::VIEW, CdaAccess::UPLOAD]));
        $this->getJson('/api/cda/periods/2026/documents')->assertForbidden();
        $this->getJson("/api/cda/documents/{$document->id}/versions")->assertForbidden();
        $this->upload(['title' => 'Minutes', 'category' => 'minutes', 'file' => $this->pdf()])->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
        $this->getJson('/api/cda/periods/2026/documents')->assertOk();
        $this->getJson("/api/cda/documents/{$document->id}/versions")->assertOk();
        $this->upload(['title' => 'Minutes', 'category' => 'minutes', 'file' => $this->pdf()])->assertForbidden();
        $this->upload(['file' => $this->pdf()], "/api/cda/documents/{$document->id}/versions")->assertForbidden();
        $this->postJson("/api/cda/documents/{$document->id}/withdraw", ['reason' => 'x'])->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::UPLOAD]));
        $this->getJson('/api/cda/periods/2026/documents')->assertForbidden();
        $this->upload(['title' => 'Minutes', 'category' => 'minutes', 'file' => $this->pdf()])->assertCreated();
        $this->upload(['file' => $this->pdf()], "/api/cda/documents/{$document->id}/versions")->assertCreated();
        $this->postJson("/api/cda/documents/{$document->id}/withdraw", ['reason' => 'Replaced'])->assertOk();
    }

    public function test_owned_documents_need_the_owners_write_permission(): void
    {
        $period = $this->period();
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->sole();
        $training = CdaOfficerTraining::factory()->create();
        $case = CdaMediationCase::factory()->create(['filed_on' => '2026-03-01']);
        $submission = CdaSubmission::factory()->create(['report_id' => $capr->id]);
        $owned = [
            CdaAccess::MANAGE_OFFICERS => CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'certificates', 'subject_type' => 'training', 'subject_id' => $training->id]),
            CdaAccess::SUBMIT => CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'submission_proof', 'report_id' => $capr->id, 'subject_type' => 'submission', 'subject_id' => $submission->id]),
            CdaAccess::MANAGE_MEDIATION => CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'other', 'subject_type' => 'case', 'subject_id' => $case->id]),
        ];
        $base = [CdaAccess::VIEW, CdaAccess::UPLOAD, CdaAccess::VIEW_MEDIATION];

        foreach ($owned as $permission => $document) {
            $this->actingAs($this->cdaUser($base));
            $this->upload(['file' => $this->pdf()], "/api/cda/documents/{$document->id}/versions")->assertForbidden()
                ->assertJsonPath('message', "This document belongs to its register: changing it needs the {$permission} permission.");
            $this->postJson("/api/cda/documents/{$document->id}/withdraw", ['reason' => 'x'])->assertForbidden();

            $this->actingAs($this->cdaUser([...$base, $permission]));
            $this->upload(['file' => $this->pdf()], "/api/cda/documents/{$document->id}/versions")->assertCreated();
            $this->postJson("/api/cda/documents/{$document->id}/withdraw", ['reason' => 'Replaced'])->assertOk();
        }

        $this->actingAs($this->cdaUser($base));
        $this->upload(['title' => 'Certificates', 'category' => 'certificates', 'file' => $this->pdf()])->assertForbidden();
        $this->upload(['title' => 'Mediation minutes', 'category' => 'mediation', 'file' => $this->pdf()])->assertForbidden();
        $this->upload(['title' => 'Case papers', 'category' => 'other', 'subject_type' => 'case', 'subject_id' => $case->id, 'file' => $this->pdf()])->assertForbidden();
        $this->actingAs($this->cdaUser([...$base, CdaAccess::MANAGE_MEDIATION]));
        $this->upload(['title' => 'Case papers', 'category' => 'other', 'subject_type' => 'case', 'subject_id' => $case->id, 'file' => $this->pdf()])->assertCreated();
    }

    public function test_a_certificate_for_a_training_is_added_from_the_training(): void
    {
        $training = CdaOfficerTraining::factory()->create();
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD, CdaAccess::MANAGE_OFFICERS]));

        $this->upload(['title' => 'Certificate', 'category' => 'certificates', 'subject_type' => 'training', 'subject_id' => $training->id, 'file' => $this->pdf()])
            ->assertUnprocessable()->assertJsonPath('errors.category.0', 'Add a certificate from the training.');
        $this->upload(['title' => 'Seminar certificates', 'category' => 'certificates', 'file' => $this->pdf()])->assertCreated();
    }

    public function test_restricted_subjects_and_categories_are_refused_before_any_lookup(): void
    {
        $case = CdaMediationCase::factory()->create(['filed_on' => '2026-03-01']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));

        foreach ([$case->id, 999999] as $id) {
            $this->upload(['title' => 'Probe', 'category' => 'other', 'subject_type' => 'case', 'subject_id' => $id, 'file' => $this->pdf()])->assertForbidden();
        }

        $this->upload(['title' => 'Probe', 'category' => 'audit_report', 'report_id' => 999999])->assertForbidden();
        $this->upload(['title' => 'Probe', 'category' => 'tax'])->assertForbidden();
    }

    public function test_a_file_name_with_control_characters_is_cleaned_and_still_downloads(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));

        $version = $this->upload(['title' => 'Minutes', 'category' => 'minutes', 'file' => UploadedFile::fake()->create("board\x10min\x13utes.pdf", 10, 'application/pdf')])
            ->assertCreated()->json('data.current_version');

        $this->assertSame('boardminutes.pdf', $version['file_name']);
        $this->openAsBrowser($version['url'])->assertOk();
    }

    public function test_the_list_takes_the_same_queries_for_one_document_or_many(): void
    {
        $period = $this->period();
        $uploaders = [$this->cdaUser([CdaAccess::UPLOAD]), $this->cdaUser([CdaAccess::UPLOAD])];
        $make = function (int $count) use ($period, $uploaders): void {
            for ($i = 0; $i < $count; $i++) {
                $document = CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'minutes', 'created_by' => $uploaders[$i % 2]->id]);
                CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id, 'uploaded_by' => $uploaders[($i + 1) % 2]->id]);
            }
        };
        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/cda/periods/2026/documents')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $make(1);
        $queries();
        $one = $queries();
        $make(6);
        $this->assertSame($one, $queries());
        $this->assertNotNull($this->getJson('/api/cda/periods/2026/documents')->json('data.0.created_by.name'));
    }
}
