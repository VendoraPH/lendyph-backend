<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaDocument;
use App\Models\CdaReport;
use App\Models\CdaSubmission;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\SubmissionProofs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Proof of a filing with CDA (CDA design §3): a document about the
 * submission (category `submission_proof`), each upload a new final version,
 * on `cda:submit`, and the report type's view permission when it is
 * restricted.
 */
class CdaSubmissionProofTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function submissionFor(string $type, string $part = 'annual'): CdaSubmission
    {
        $report = CdaReport::query()->where('period_id', $this->period()->id)->where('report_type', $type)->where('part', $part)->sole();

        return CdaSubmission::factory()->create(['report_id' => $report->id, 'submitted_on' => '2027-04-20']);
    }

    private function proof(int $submissionId, string $name = 'receipt.pdf'): TestResponse
    {
        return $this->post("/api/cda/submissions/{$submissionId}/proof", ['file' => UploadedFile::fake()->create($name, 8, 'application/pdf'), 'notes' => 'CAIS receipt'], ['Accept' => 'application/json']);
    }

    public function test_a_proof_is_a_final_version_of_the_submissions_document(): void
    {
        $submission = $this->submissionFor('capr');
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::SUBMIT]));

        $first = $this->proof($submission->id)->assertCreated()->json('data');
        $this->assertSame($submission->id, $first['submission_id']);
        $this->assertSame('submission_proof', $first['document']['category']);
        $this->assertSame('capr', $first['document']['report_type']);
        $this->assertTrue($first['document']['current_version']['is_final']);

        $second = $this->proof($submission->id, 'stamped.pdf')->assertCreated()->json('data');
        $this->assertSame($first['document']['id'], $second['document']['id']);
        $this->assertSame(2, $second['document']['final_version']['version']);
        $this->assertSame(1, CdaDocument::query()->where('subject_type', 'submission')->where('subject_id', $submission->id)->count());

        $presented = app(SubmissionProofs::class)->present($submission->fresh());
        $this->assertSame('stamped.pdf', $presented['file_name']);
        $this->openAsBrowser($presented['url'])->assertOk();
    }

    public function test_a_restricted_reports_proof_needs_its_view_permission(): void
    {
        $submission = $this->submissionFor('mediation', 'h1');

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::SUBMIT]));
        $this->proof($submission->id)->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::SUBMIT, CdaAccess::VIEW_MEDIATION]));
        $this->proof($submission->id)->assertCreated();
    }

    public function test_the_proof_route_needs_submit_and_view_all_and_a_file(): void
    {
        $submission = $this->submissionFor('social_audit');

        $this->actingAs($this->userWith([CdaAccess::VIEW, CdaAccess::SUBMIT]));
        $this->proof($submission->id)->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]));
        $this->proof($submission->id)->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::SUBMIT]));
        $this->postJson("/api/cda/submissions/{$submission->id}/proof", [])->assertUnprocessable()->assertJsonPath('errors.file.0', 'Choose a file to upload.');
        $this->proof($submission->id)->assertCreated();
        $this->proof(999999)->assertNotFound();
    }
}
