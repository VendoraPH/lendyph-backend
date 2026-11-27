<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaReport;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Cda\CdaAccess;
use App\Services\SignedFileLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Downloading a CDA file (decided: standard). A signed-in caller reaches a
 * CDA document's or an export's file only with `cda:view` and
 * `branches:view_all`, plus the restricted view permission for mediation,
 * audit or tax files; otherwise the file answers 404, as another branch's
 * member file does. Every download of a CDA file writes an audit row, and a
 * mediation file's row names it by type and id, never by its title.
 */
class CdaDocumentDownloadsTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    private const PARTY = 'Juan Dela Cruz v. Maria Santos';

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

    /**
     * A CDA document with one version and its file on the private disk.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function cdaFile(array $attributes, string $owner = CdaDocument::class): Document
    {
        $document = CdaDocument::factory()->create(['period_id' => $this->period()->id, ...$attributes]);
        $path = "cda/documents/{$document->id}.pdf";
        Storage::disk('private')->put($path, 'pdf-bytes');
        $file = Document::query()->create([
            'documentable_type' => $owner,
            'documentable_id' => $owner === CdaReport::class ? $document->report_id : $document->id,
            'type' => $owner === CdaReport::class ? 'cda_export' : 'cda_document',
            'file_path' => $path,
            'original_filename' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 9,
        ]);
        CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id, 'version' => 1, 'document_id' => $file->id]);

        return $file;
    }

    private function link(Document $file): string
    {
        return SignedFileLink::to('files.document', ['document' => $file->id]);
    }

    private function report(string $type): CdaReport
    {
        return CdaReport::query()->where('period_id', $this->period()->id)->where('report_type', $type)->where('part', 'annual')->sole();
    }

    /** @return list<AuditLog> */
    private function downloads(): array
    {
        return AuditLog::query()->where('action', 'downloaded')->orderBy('id')->get()->all();
    }

    public function test_a_signed_in_caller_with_cda_view_downloads_and_the_download_is_audited(): void
    {
        $file = $this->cdaFile(['category' => 'minutes', 'title' => 'Board minutes']);
        $user = $this->cdaUser([CdaAccess::VIEW]);
        $this->actingAs($user);

        $this->get($this->link($file))->assertOk();

        $rows = $this->downloads();
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows[0]->user_id);
        $this->assertSame(CdaDocument::class, $rows[0]->auditable_type);
        $this->assertEquals(['document_id' => $file->id, 'version' => 1], $rows[0]->new_values);
        $this->assertSame("Minutes of meetings #{$file->documentable_id}, version 1, downloaded.", $rows[0]->description);
    }

    public function test_without_cda_view_or_view_all_a_signed_in_caller_gets_404(): void
    {
        $file = $this->cdaFile(['category' => 'minutes']);
        $url = $this->link($file);

        foreach ([$this->userWith([CdaAccess::VIEW]), $this->cdaUser([CdaAccess::PREPARE])] as $user) {
            $this->actingAs($user);
            $this->get($url)->assertNotFound();
        }

        $this->assertSame([], $this->downloads());
    }

    public function test_audit_tax_and_mediation_files_need_their_view_permission(): void
    {
        $files = [
            CdaAccess::VIEW_AUDIT => $this->cdaFile(['category' => 'audit_report']),
            CdaAccess::VIEW_TAX => $this->cdaFile(['category' => 'tax']),
            CdaAccess::VIEW_MEDIATION => $this->cdaFile(['category' => 'mediation', 'title' => self::PARTY]),
        ];

        foreach ($files as $permission => $file) {
            $url = $this->link($file);
            $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
            $this->get($url)->assertNotFound();
            $this->actingAs($this->cdaUser([CdaAccess::VIEW, $permission]));
            $this->get($url)->assertOk();
        }
    }

    public function test_an_export_of_a_restricted_report_needs_its_view_permission(): void
    {
        $file = $this->cdaFile(['category' => 'report_export', 'report_id' => $this->report('financial_statements')->id, 'title' => 'Audited Financial Statements 2026 · PDF export'], CdaReport::class);
        $url = $this->link($file);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
        $this->get($url)->assertNotFound();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::VIEW_AUDIT]));
        $this->get($url)->assertOk();
        $this->assertCount(1, $this->downloads());
    }

    public function test_a_mediation_download_is_audited_by_type_and_id_never_by_title(): void
    {
        $file = $this->cdaFile(['category' => 'other', 'subject_type' => 'case', 'subject_id' => 1, 'title' => self::PARTY, 'description' => self::PARTY]);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::VIEW_MEDIATION]));

        $this->get($this->link($file))->assertOk();

        $row = $this->downloads()[0];
        $this->assertSame("Mediation document #{$file->documentable_id}, version 1, downloaded.", $row->description);
        $this->assertStringNotContainsString('Juan', json_encode([$row->old_values, $row->new_values, $row->description]));
    }

    public function test_a_link_opened_in_a_browser_tab_is_audited_against_the_user_it_was_made_for(): void
    {
        $file = $this->cdaFile(['category' => 'minutes']);
        $user = $this->cdaUser([CdaAccess::VIEW]);
        $this->actingAs($user);
        $url = $this->link($file);

        $this->openAsBrowser($url)->assertOk();

        $this->assertSame($user->id, $this->downloads()[0]->user_id);
        $this->assertEquals(['document_id' => $file->id, 'version' => 1, 'via' => 'link'], $this->downloads()[0]->new_values);
    }

    public function test_a_link_is_refused_once_its_user_loses_the_permission_or_is_deactivated(): void
    {
        $file = $this->cdaFile(['category' => 'mediation', 'title' => self::PARTY]);
        $role = Role::create(['name' => 'cda_link_probe', 'guard_name' => 'web']);
        $role->givePermissionTo([BranchAccess::VIEW_ALL, CdaAccess::VIEW, CdaAccess::VIEW_MEDIATION]);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user);
        $url = $this->link($file);

        $this->openAsBrowser($url)->assertOk();

        $role->revokePermissionTo(CdaAccess::VIEW_MEDIATION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->openAsBrowser($url)->assertNotFound();

        $role->givePermissionTo(CdaAccess::VIEW_MEDIATION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->update(['status' => 'inactive']);
        $this->openAsBrowser($url)->assertNotFound();

        $this->assertCount(1, $this->downloads());
    }

    public function test_a_cda_link_made_with_nobody_signed_in_is_refused(): void
    {
        $file = $this->cdaFile(['category' => 'minutes']);
        $this->app['auth']->forgetGuards();

        $this->openAsBrowser($this->link($file))->assertNotFound();
        $this->assertSame([], $this->downloads());
    }

    public function test_member_files_are_unchanged_for_a_signed_in_caller(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        Storage::disk('private')->put('documents/valid_id/borrower/1/id.jpg', 'id-bytes');
        $file = Document::query()->create([
            'documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id',
            'file_path' => 'documents/valid_id/borrower/1/id.jpg', 'original_filename' => 'id.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 8,
        ]);

        $this->get($this->link($file))->assertOk();
        $this->openAsBrowser($this->link($file))->assertOk();
        $this->assertSame([], $this->downloads());
    }

    public function test_the_member_document_routes_never_reach_a_cda_file(): void
    {
        $file = $this->cdaFile(['category' => 'minutes']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, 'borrowers:view', 'borrowers:delete']));

        $this->getJson("/api/documents/{$file->id}")->assertNotFound();
        $this->deleteJson("/api/documents/{$file->id}")->assertNotFound();

        Storage::disk('private')->assertExists($file->file_path);
        $this->assertDatabaseHas('documents', ['id' => $file->id]);
    }
}
