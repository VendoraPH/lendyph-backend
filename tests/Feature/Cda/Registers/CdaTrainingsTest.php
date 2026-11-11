<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Officer trainings (CDA design §6): a completed training needs its date,
 * hours are two decimals, and a certificate is a CDA document version with
 * the training as its subject, served by a signed link. Deleting a training
 * withdraws its certificate and keeps the file.
 */
class CdaTrainingsTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    private CdaOfficer $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        Storage::fake('private');
        $this->period();
        $this->officer = CdaOfficer::factory()->create(['name' => 'Ana Reyes', 'position' => 'treasurer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_training_with_a_certificate_stores_it_as_a_versioned_document_with_a_signed_link(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $data = $this->post('/api/cda/trainings', [
            'officer_id' => $this->officer->id,
            'title' => 'Fundamentals of Cooperatives',
            'requirement_key' => 'fundamentals',
            'is_mandatory' => '1',
            'provider' => 'CDA Training Center',
            'started_on' => '2026-03-02',
            'completed_on' => '2026-03-03',
            'hours' => '16',
            'certificate_number' => 'CERT-001',
            'certificate' => UploadedFile::fake()->create('certificate.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('16.00', $data['hours']);
        $this->assertSame(['id' => $this->officer->id, 'name' => 'Ana Reyes', 'position' => 'treasurer'], $data['officer']);
        $this->assertTrue($data['is_mandatory']);
        $this->assertSame(1, $data['certificate']['version']);
        $this->assertSame('certificate.pdf', $data['certificate']['file_name']);

        $document = CdaDocument::query()->where('subject_type', 'training')->where('subject_id', $data['id'])->sole();
        $this->assertSame('certificates', $document->category);
        $this->assertSame($document->id, $data['certificate']['cda_document_id']);
        $this->assertSame(2026, (int) $document->period?->year);

        $this->openAsBrowser($data['certificate']['url'])->assertOk();
    }

    public function test_a_new_certificate_on_update_adds_a_version_and_never_overwrites(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));
        $id = $this->post('/api/cda/trainings', [
            'officer_id' => $this->officer->id, 'title' => 'Governance', 'completed_on' => '2026-04-01', 'hours' => '8',
            'certificate' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $data = $this->post("/api/cda/trainings/{$id}", [
            '_method' => 'PUT',
            'hours' => '8.5',
            'certificate' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('8.50', $data['hours']);
        $this->assertSame(2, $data['certificate']['version']);
        $versions = CdaDocumentVersion::query()->where('cda_document_id', $data['certificate']['cda_document_id'])->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        foreach ($versions as $version) {
            Storage::disk('private')->assertExists($version->file->file_path);
        }
    }

    public function test_refusals_read_in_words(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $errors = $this->post('/api/cda/trainings', [
            'officer_id' => 999999, 'title' => '', 'status' => 'completed', 'hours' => '1.234',
            'started_on' => '2026-05-02', 'completed_on' => '2026-05-01',
            'certificate' => UploadedFile::fake()->create('virus.exe', 5, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->json('errors');

        $this->assertSame(['Choose an officer from the register.'], $errors['officer_id']);
        $this->assertSame(['Give the training\'s title.'], $errors['title']);
        $this->assertSame(['Hours can have at most two decimals.'], $errors['hours']);
        $this->assertSame(['A training can\'t be completed before it starts.'], $errors['completed_on']);
        $this->assertStringContainsString('.xlsx', implode(' ', $errors['certificate']));

        $this->postJson('/api/cda/trainings', ['officer_id' => $this->officer->id, 'title' => 'T', 'status' => 'completed', 'hours' => '2'])
            ->assertUnprocessable()->assertJsonPath('errors.completed_on.0', 'Give the date a completed training was finished.');
    }

    public function test_deleting_a_training_withdraws_its_certificate_and_keeps_the_file(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));
        $data = $this->post('/api/cda/trainings', [
            'officer_id' => $this->officer->id, 'title' => 'Governance', 'completed_on' => '2026-04-01', 'hours' => '8',
            'certificate' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->json('data');

        $this->deleteJson("/api/cda/trainings/{$data['id']}")->assertOk();

        $this->assertDatabaseMissing('cda_officer_trainings', ['id' => $data['id']]);
        $document = CdaDocument::query()->findOrFail($data['certificate']['cda_document_id']);
        $this->assertSame('withdrawn', $document->status);
        $this->assertSame('The training was deleted.', $document->withdraw_reason);
        Storage::disk('private')->assertExists($document->versions()->sole()->file->file_path);
    }

    public function test_the_list_filters_by_officer_and_year(): void
    {
        $other = CdaOfficer::factory()->create();
        $a = CdaOfficerTraining::factory()->create(['officer_id' => $this->officer->id, 'completed_on' => '2026-02-01']);
        CdaOfficerTraining::factory()->create(['officer_id' => $this->officer->id, 'completed_on' => '2024-02-01', 'started_on' => '2024-02-01']);
        CdaOfficerTraining::factory()->create(['officer_id' => $other->id, 'completed_on' => '2026-02-01']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));

        $this->assertSame([$a->id], array_column($this->getJson("/api/cda/trainings?officer_id={$this->officer->id}&year=2026")->assertOk()->json('data'), 'id'));
    }

    public function test_every_training_route_needs_its_permission_and_view_all(): void
    {
        $training = CdaOfficerTraining::factory()->create(['officer_id' => $this->officer->id]);
        $body = ['officer_id' => $this->officer->id, 'title' => 'Basic Accounting', 'hours' => '4', 'status' => 'scheduled'];

        $this->actingAs($this->userWith([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));
        $this->getJson('/api/cda/trainings')->assertForbidden();
        $this->postJson('/api/cda/trainings', $body)->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
        $this->getJson('/api/cda/trainings')->assertOk();
        $this->postJson('/api/cda/trainings', $body)->assertForbidden();
        $this->putJson("/api/cda/trainings/{$training->id}", ['hours' => '2'])->assertForbidden();
        $this->deleteJson("/api/cda/trainings/{$training->id}")->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_OFFICERS]));
        $this->getJson('/api/cda/trainings')->assertForbidden();
        $this->postJson('/api/cda/trainings', $body)->assertCreated();
        $this->putJson("/api/cda/trainings/{$training->id}", ['hours' => '2'])->assertOk();
        $this->deleteJson("/api/cda/trainings/{$training->id}")->assertOk();
    }

    public function test_a_255_character_title_still_files_its_certificate(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));
        $title = str_repeat('Governance and Ethics ', 12).'Final';
        $title = substr($title, 0, 255);
        $this->assertSame(255, strlen($title));

        $data = $this->post('/api/cda/trainings', [
            'officer_id' => $this->officer->id, 'title' => $title, 'completed_on' => '2026-04-01', 'hours' => '8',
            'certificate' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $document = CdaDocument::query()->findOrFail($data['certificate']['cda_document_id']);
        $this->assertSame(255, mb_strlen($document->title));
        $this->assertStringStartsWith('Training certificate: Governance', $document->title);
    }

    public function test_a_certificate_files_under_the_nearest_period_when_its_year_has_none(): void
    {
        $this->dropPeriods();
        $this->period(2023);
        $this->period(2027);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $data = $this->post('/api/cda/trainings', [
            'officer_id' => $this->officer->id, 'title' => 'Governance', 'completed_on' => '2024-04-01', 'started_on' => '2024-04-01', 'hours' => '8',
            'certificate' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame(2023, (int) CdaDocument::query()->findOrFail($data['certificate']['cda_document_id'])->period->year);
    }

    public function test_a_certificate_with_no_reporting_period_at_all_is_refused_in_words(): void
    {
        $this->dropPeriods();
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));
        $before = CdaOfficerTraining::query()->count();

        $this->post('/api/cda/trainings', [
            'officer_id' => $this->officer->id, 'title' => 'Governance', 'completed_on' => '2026-04-01', 'hours' => '8',
            'certificate' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.certificate.0', 'Open a CDA reporting period first, so the certificate has a year to be filed under.');

        $this->assertSame($before, CdaOfficerTraining::query()->count());
        $this->postJson('/api/cda/trainings', ['officer_id' => $this->officer->id, 'title' => 'Governance', 'completed_on' => '2026-04-01', 'hours' => '8'])->assertCreated();
    }

    private function dropPeriods(): void
    {
        CdaReport::query()->delete();
        CdaReportingPeriod::query()->delete();
    }
}
