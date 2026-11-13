<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\AuditLog;
use App\Models\CdaDocument;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReport;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A training's documents are filed under its year's period, and its
 * `documents_count` reads that period. When the training's year changes, its
 * documents not linked to a report move with it, one by one and audited.
 * `cda:refile-training-documents` does the same for trainings already saved
 * with a mismatch, as a system change.
 */
class TrainingDocumentsFollowTheTrainingTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        Storage::fake('private');
        $this->period(2025);
        $this->period(2026);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_training_moved_to_another_year_keeps_its_documents_count(): void
    {
        $training = CdaOfficerTraining::factory()->create(['completed_on' => '2026-03-01']);
        $certificate = $this->attach($training, 2026, ['category' => 'certificates']);
        $other = $this->attach($training, 2026);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));
        $this->assertSame(2, $this->documentsCount($training));

        $this->putJson("/api/cda/trainings/{$training->id}", ['completed_on' => '2025-06-15'])
            ->assertOk()
            ->assertJsonPath('data.documents_count', 2);

        $this->assertSame(2, $this->documentsCount($training));
        $this->assertSame($this->period(2025)->id, $certificate->fresh()->period_id);
        $this->assertSame($this->period(2025)->id, $other->fresh()->period_id);

        foreach ([$certificate, $other] as $document) {
            $row = AuditLog::query()->where('auditable_type', CdaDocument::class)->where('auditable_id', $document->id)
                ->where('action', 'updated')->latest('id')->first();
            $this->assertNotNull($row, 'Each moved document writes its own audit row.');
            $this->assertSame($this->period(2026)->id, $row->old_values['period_id']);
            $this->assertSame($this->period(2025)->id, $row->new_values['period_id']);
            $this->assertSame(auth()->id(), $row->user_id);
        }
    }

    public function test_a_document_linked_to_a_report_is_never_moved(): void
    {
        $training = CdaOfficerTraining::factory()->create(['completed_on' => '2026-03-01']);
        $report = CdaReport::query()->where('period_id', $this->period(2026)->id)->orderBy('id')->firstOrFail();
        $linked = $this->attach($training, 2026, ['report_id' => $report->id]);
        $loose = $this->attach($training, 2026);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]))
            ->putJson("/api/cda/trainings/{$training->id}", ['completed_on' => '2025-06-15'])
            ->assertOk();

        $this->assertSame($this->period(2026)->id, $linked->fresh()->period_id);
        $this->assertSame($this->period(2025)->id, $loose->fresh()->period_id);
    }

    public function test_a_change_within_the_same_period_moves_nothing(): void
    {
        $training = CdaOfficerTraining::factory()->create(['completed_on' => '2026-03-01']);
        $document = $this->attach($training, 2026);
        $before = AuditLog::query()->where('auditable_type', CdaDocument::class)->count();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]))
            ->putJson("/api/cda/trainings/{$training->id}", ['completed_on' => '2026-09-30', 'hours' => '4'])
            ->assertOk();

        $this->assertSame($this->period(2026)->id, $document->fresh()->period_id);
        $this->assertSame($before, AuditLog::query()->where('auditable_type', CdaDocument::class)->count());
    }

    public function test_the_data_fix_dry_run_lists_the_moves_and_writes_nothing(): void
    {
        [$training, $misfiled, $linked] = $this->mismatch();
        $before = AuditLog::query()->count();

        $this->artisan('cda:refile-training-documents', ['--dry-run' => true])
            ->expectsOutputToContain("Document #{$misfiled->id} of training #{$training->id}: would move from 2026 to 2025")
            ->expectsOutputToContain('Would move 1 document(s)')
            ->assertSuccessful();

        $this->assertSame($this->period(2026)->id, $misfiled->fresh()->period_id);
        $this->assertSame($this->period(2026)->id, $linked->fresh()->period_id);
        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_the_data_fix_moves_each_document_as_a_system_change_and_a_second_run_changes_nothing(): void
    {
        [$training, $misfiled, $linked] = $this->mismatch();
        $undated = CdaOfficerTraining::factory()->create(['completed_on' => null, 'started_on' => null]);
        $undatedDocument = $this->attach($undated, 2025);

        $this->artisan('cda:refile-training-documents')
            ->expectsOutputToContain("Document #{$misfiled->id} of training #{$training->id}: moved from 2026 to 2025")
            ->expectsOutputToContain("Training #{$undated->id} left as it is: it has no completed or started date")
            ->expectsOutputToContain('Moved 1 document(s)')
            ->assertSuccessful();

        $this->assertSame($this->period(2025)->id, $misfiled->fresh()->period_id);
        $this->assertSame($this->period(2026)->id, $linked->fresh()->period_id, 'A report-linked document stays.');
        $this->assertSame($this->period(2025)->id, $undatedDocument->fresh()->period_id, 'An undated training is uncertain and left alone.');

        $row = AuditLog::query()->where('auditable_type', CdaDocument::class)->where('auditable_id', $misfiled->id)
            ->where('action', 'updated')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id, 'A data fix is a system change.');
        $this->assertSame(['period_id' => $this->period(2026)->id], $row->old_values);
        $this->assertSame(['period_id' => $this->period(2025)->id], $row->new_values);
        $this->assertStringContainsString('2025 reporting period', (string) $row->description);
        $this->assertSame(1, AuditLog::query()->where('auditable_type', CdaDocument::class)->where('auditable_id', $misfiled->id)->where('action', 'updated')->count());

        $before = AuditLog::query()->count();
        $this->artisan('cda:refile-training-documents')
            ->expectsOutputToContain('Moved 0 document(s)')
            ->assertSuccessful();
        $this->assertSame($before, AuditLog::query()->count());
    }

    /**
     * A training completed in 2025 whose document still sits in 2026 (saved
     * before documents followed the training), plus a report-linked one.
     *
     * @return array{0: CdaOfficerTraining, 1: CdaDocument, 2: CdaDocument}
     */
    private function mismatch(): array
    {
        $training = CdaOfficerTraining::factory()->create(['completed_on' => '2025-06-15']);
        $report = CdaReport::query()->where('period_id', $this->period(2026)->id)->orderBy('id')->firstOrFail();

        return [$training, $this->attach($training, 2026), $this->attach($training, 2026, ['report_id' => $report->id])];
    }

    private function documentsCount(CdaOfficerTraining $training): int
    {
        return collect($this->getJson('/api/cda/trainings')->assertOk()->json('data'))->firstWhere('id', $training->id)['documents_count'];
    }

    /** @param  array<string, mixed>  $attributes */
    private function attach(CdaOfficerTraining $training, int $year, array $attributes = []): CdaDocument
    {
        return CdaDocument::factory()->create([
            'period_id' => $this->period($year)->id,
            'category' => 'other',
            'subject_type' => 'training',
            'subject_id' => $training->id,
            ...$attributes,
        ]);
    }
}
