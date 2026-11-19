<?php

namespace Tests\Feature\Phase6\Amla\Cases;

use App\Models\AmlaCase;
use App\Models\AmlaCaseEvidence;
use App\Models\AmlaCaseNote;
use App\Models\AmlaLogEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Cases (task §12–13; AMLA-B6): `amla:cases` and head-office access, notes
 * and decision reasons encrypted, evidence on the private disk under the
 * document upload checks, every view and download in the AMLA log, and
 * nothing outside AMLA.
 */
class AmlaCaseTest extends TestCase
{
    use CaseTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cases_need_amla_cases_and_head_office_access_and_every_view_is_logged(): void
    {
        $member = $this->member();
        $this->actingAs($this->amlaUser(['amla:cases']))->postJson('/api/amla/cases', ['title' => 'x', 'borrower_id' => $member->id])->assertForbidden();
        $this->actingAs($this->headOffice(['amla:alerts']))->getJson('/api/amla/cases')->assertForbidden();

        $officer = $this->headOffice(['amla:cases']);
        $case = $this->openCase($officer);
        $this->assertMatchesRegularExpression('/^CASE-20261120-\d{6}$/', $case->reference);

        $this->actingAs($officer)->getJson('/api/amla/cases')->assertOk()->assertJsonPath('data.0.reference', $case->reference);
        $this->getJson("/api/amla/cases/{$case->id}")->assertOk()->assertJsonPath('data.status', 'open');
        $this->getJson('/api/amla/cases/999999999')->assertNotFound();

        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'case_viewed')->where('subject_type', 'case')->where('subject_id', $case->id)->count());
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'cases_viewed')->count());
    }

    public function test_notes_and_the_decision_are_encrypted_and_a_decision_closes_or_marks_the_case_for_an_str(): void
    {
        $officer = $this->headOffice(['amla:cases']);
        $case = $this->openCase($officer);
        $other = $this->openCase($officer);
        $outside = $this->outsideAmlaCounts();

        $this->actingAs($officer)->postJson("/api/amla/cases/{$case->id}/notes", ['body' => 'Member deposited cash every day for a week.'])->assertCreated();
        $this->assertNotSame('Member deposited cash every day for a week.', AmlaCaseNote::query()->toBase()->value('body'));
        $this->getJson("/api/amla/cases/{$case->id}")->assertJsonPath('data.notes.0.body', 'Member deposited cash every day for a week.');

        $this->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'str', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('determination_date');
        $this->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'str', 'reason' => 'Pattern has no business reason.', 'determination_date' => '2026-11-21'])
            ->assertUnprocessable()->assertJsonValidationErrors('determination_date');
        $this->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'str', 'reason' => 'Pattern has no business reason.', 'determination_date' => '2026-11-20'])
            ->assertOk()->assertJsonPath('data.status', 'str_required')->assertJsonPath('data.determination_date', '2026-11-20');
        $this->assertNotSame('Pattern has no business reason.', AmlaCase::query()->toBase()->value('decision_reason'));
        $this->postJson("/api/amla/cases/{$case->id}/decision", ['decision' => 'no_str', 'reason' => 'Changed my mind.'])->assertConflict();

        $this->actingAs($officer)->postJson("/api/amla/cases/{$other->id}/decision", ['decision' => 'no_str', 'reason' => 'Salary documented.'])
            ->assertOk()->assertJsonPath('data.status', 'closed_no_report');
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_evidence_passes_the_upload_checks_stays_private_and_each_download_is_logged(): void
    {
        $officer = $this->headOffice(['amla:cases']);
        $case = $this->openCase($officer);

        $this->actingAs($officer)->post("/api/amla/cases/{$case->id}/evidence", ['file' => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post("/api/amla/cases/{$case->id}/evidence", ['file' => UploadedFile::fake()->createWithContent('statement.pdf.exe', "%PDF-1.4\n%")], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $id = $this->post("/api/amla/cases/{$case->id}/evidence", ['file' => UploadedFile::fake()->createWithContent('Juan Dela Cruz bank statement.pdf', "%PDF-1.4\n%%EOF\n"), 'description' => 'Bank statement'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $evidence = AmlaCaseEvidence::query()->findOrFail($id);
        Storage::disk('private')->assertExists($evidence->file_path);
        $this->assertStringStartsWith("amla/cases/{$case->id}/", $evidence->file_path);
        $this->assertStringNotContainsString('Juan', $evidence->file_path);

        $this->get("/api/amla/cases/{$case->id}/evidence/{$id}")->assertOk()->assertDownload();
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'case_evidence_downloaded')->count());
        $this->assertStringNotContainsString('Juan', json_encode(AmlaLogEntry::query()->pluck('details')->all()));

        $otherCase = $this->openCase($officer);
        $this->actingAs($officer)->get("/api/amla/cases/{$otherCase->id}/evidence/{$id}", ['Accept' => 'application/json'])->assertNotFound();
    }
}
