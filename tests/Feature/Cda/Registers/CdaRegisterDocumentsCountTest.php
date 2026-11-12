<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaDocument;
use App\Models\CdaMediationCase;
use App\Models\CdaOfficerTraining;
use App\Models\CdaSocialProgram;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Gap round B7: a register row's attachments. The document list takes
 * `subject_id` with `subject_type`, and training, program and case rows carry
 * `documents_count`: the active documents attached to the row that the caller
 * may see under the existing restriction rules (withdrawn ones stay listed
 * but aren't counted).
 */
class CdaRegisterDocumentsCountTest extends TestCase
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

    public function test_the_document_list_filters_by_subject_id_with_its_type(): void
    {
        $program = CdaSocialProgram::factory()->create(['period_id' => $this->period()->id]);
        $other = CdaSocialProgram::factory()->create(['period_id' => $this->period()->id]);
        $mine = $this->attach('program', $program->id);
        $this->attach('program', $other->id);
        $this->attach('training', $program->id);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));

        $ids = array_column($this->getJson("/api/cda/periods/2026/documents?subject_type=program&subject_id={$program->id}")->assertOk()->json('data'), 'id');
        $this->assertSame([$mine->id], $ids);

        $this->getJson("/api/cda/periods/2026/documents?subject_id={$program->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_type']);
        $this->getJson('/api/cda/periods/2026/documents?subject_type=program&subject_id=first')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_program_rows_count_only_active_documents_the_caller_may_see(): void
    {
        $program = CdaSocialProgram::factory()->create(['period_id' => $this->period()->id]);
        $bare = CdaSocialProgram::factory()->create(['period_id' => $this->period()->id]);
        $this->attach('program', $program->id);
        $this->attach('program', $program->id, ['status' => 'withdrawn']);
        $this->attach('program', $program->id, ['category' => 'audit_report']);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
        $rows = collect($this->getJson('/api/cda/periods/2026/social-programs')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(1, $rows[$program->id]['documents_count'], 'The audit report is hidden without the audit view; the withdrawn one is not counted.');
        $this->assertSame(0, $rows[$bare->id]['documents_count']);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::VIEW_AUDIT]));
        $rows = collect($this->getJson('/api/cda/periods/2026/social-programs')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(2, $rows[$program->id]['documents_count']);
    }

    public function test_training_and_case_rows_carry_documents_count(): void
    {
        $training = CdaOfficerTraining::factory()->create();
        $case = CdaMediationCase::factory()->create();
        $this->attach('training', $training->id, ['category' => 'certificates']);
        $this->attach('training', $training->id, ['category' => 'certificates']);
        $this->attach('case', $case->id, ['category' => 'mediation']);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::VIEW_MEDIATION]));

        $training = collect($this->getJson('/api/cda/trainings')->assertOk()->json('data'))->firstWhere('id', $training->id);
        $this->assertSame(2, $training['documents_count']);

        $case = collect($this->getJson('/api/cda/periods/2026/mediation-cases')->assertOk()->json('data'))->firstWhere('id', $case->id);
        $this->assertSame(1, $case['documents_count']);
    }

    public function test_a_saved_row_carries_its_count_too(): void
    {
        $program = CdaSocialProgram::factory()->create(['period_id' => $this->period()->id]);
        $this->attach('program', $program->id);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_SOCIAL]))
            ->putJson("/api/cda/social-programs/{$program->id}", ['name' => 'Coastal clean-up'])
            ->assertOk()
            ->assertJsonPath('data.documents_count', 1);
    }

    /** @param  array<string, mixed>  $attributes */
    private function attach(string $type, int $id, array $attributes = []): CdaDocument
    {
        return CdaDocument::factory()->create([
            'period_id' => $this->period()->id,
            'category' => 'other',
            'subject_type' => $type,
            'subject_id' => $id,
            ...$attributes,
        ]);
    }
}
