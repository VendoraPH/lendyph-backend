<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\AuditLog;
use App\Models\CdaDocument;
use App\Models\CdaMediationCase;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Mediation and conciliation cases (CDA design §6): restricted to
 * `cda:view_mediation` / `cda:manage_mediation`, numbered MC-YYYY-NNNN, in
 * each half they overlap, and none of their text ever reaches an audit row.
 */
class CdaMediationCasesTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    private const SECRET_TEXT = ['Juan Dela Cruz', 'Maria Santos', 'Boundary dispute over the store', 'Atty. Reyes', 'Settled by a written agreement', 'Sensitive remark'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        $this->period();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_case_is_numbered_by_its_filing_year_and_its_text_never_reaches_the_audit(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW_MEDIATION, CdaAccess::MANAGE_MEDIATION]));
        $before = (int) (AuditLog::query()->max('id') ?? 0);

        $first = $this->postJson('/api/cda/periods/2026/mediation-cases', [
            'filed_on' => '2026-02-10',
            'complainant' => 'Juan Dela Cruz',
            'respondent' => 'Maria Santos',
            'nature' => 'Boundary dispute over the store',
            'description' => 'Boundary dispute over the store',
            'mediator' => 'Atty. Reyes',
            'status' => 'pending',
            'remarks' => 'Sensitive remark',
        ])->assertCreated()->json('data');
        $second = $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2026-08-01', 'nature' => 'Unpaid share', 'status' => 'pending'])
            ->assertCreated()->json('data');

        $this->assertSame('MC-2026-0001', $first['case_number']);
        $this->assertSame('MC-2026-0002', $second['case_number']);
        $this->assertSame('Juan Dela Cruz', $first['complainant']);
        $this->assertSame(['h1', 'h2'], $first['halves']);
        $this->assertSame(['h2'], $second['halves']);

        $this->putJson("/api/cda/mediation-cases/{$first['id']}", [
            'status' => 'resolved', 'resolved_on' => '2026-03-15', 'resolution' => 'Settled by a written agreement', 'meetings_count' => 2,
        ])->assertOk()->assertJsonPath('data.halves', ['h1']);
        $this->deleteJson("/api/cda/mediation-cases/{$second['id']}")->assertOk();

        $rows = AuditLog::query()->where('id', '>', $before)->get();
        $this->assertGreaterThanOrEqual(4, $rows->count());
        $everything = $rows->map(fn (AuditLog $row) => json_encode([$row->old_values, $row->new_values, $row->description], JSON_UNESCAPED_UNICODE))->implode(' ');
        foreach (self::SECRET_TEXT as $text) {
            $this->assertStringNotContainsString($text, $everything);
        }
        $this->assertStringContainsString('MC-2026-0001', $everything);
    }

    public function test_a_case_spanning_both_halves_appears_in_both(): void
    {
        $spanning = CdaMediationCase::factory()->create(['filed_on' => '2026-05-01', 'resolved_on' => '2026-08-15', 'status' => 'resolved']);
        $firstHalf = CdaMediationCase::factory()->create(['filed_on' => '2026-02-01', 'resolved_on' => '2026-03-01', 'status' => 'resolved']);
        $open = CdaMediationCase::factory()->create(['filed_on' => '2025-11-20', 'resolved_on' => null, 'status' => 'in_mediation']);
        CdaMediationCase::factory()->create(['filed_on' => '2025-01-10', 'resolved_on' => '2025-12-20', 'status' => 'resolved']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW_MEDIATION]));

        $ids = fn (string $query) => collect($this->getJson("/api/cda/periods/2026/mediation-cases{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$spanning->id, $firstHalf->id, $open->id])->sort()->values()->all(), $ids('?half=h1'));
        $this->assertSame(collect([$spanning->id, $open->id])->sort()->values()->all(), $ids('?half=h2'));
        $this->assertSame(collect([$spanning->id, $firstHalf->id, $open->id])->sort()->values()->all(), $ids(''));
        $this->getJson('/api/cda/periods/2026/mediation-cases?half=h3')->assertUnprocessable()->assertJsonPath('errors.half.0', 'Choose the first half (h1) or the second half (h2).');
    }

    public function test_refusals_read_in_words(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW_MEDIATION, CdaAccess::MANAGE_MEDIATION]));

        $errors = $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2026-12-01', 'status' => 'resolved'])->assertUnprocessable()->json('errors');
        $this->assertSame(['The filing date can\'t be in the future.'], $errors['filed_on']);
        $this->assertSame(['Give the date the case was resolved, escalated or withdrawn.'], $errors['resolved_on']);

        $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2026-03-01', 'status' => 'pending', 'resolved_on' => '2026-03-05'])
            ->assertUnprocessable()->assertJsonPath('errors.resolved_on.0', 'A pending case or one still in mediation has no closing date.');

        $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2025-01-01', 'status' => 'resolved', 'resolved_on' => '2025-02-01'])
            ->assertUnprocessable()->assertJsonPath('errors.filed_on.0', 'This case was closed before 2026, so it doesn\'t belong to the 2026 register.');
    }

    public function test_mediation_is_restricted_and_every_route_needs_its_permission_and_view_all(): void
    {
        $case = CdaMediationCase::factory()->create(['filed_on' => '2026-03-01']);
        $body = ['filed_on' => '2026-03-02', 'status' => 'pending'];

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::PREPARE, CdaAccess::VIEW_AUDIT, CdaAccess::VIEW_TAX]));
        $this->getJson('/api/cda/periods/2026/mediation-cases')->assertForbidden();
        $this->postJson('/api/cda/periods/2026/mediation-cases', $body)->assertForbidden();

        $this->actingAs($this->userWith([CdaAccess::VIEW_MEDIATION, CdaAccess::MANAGE_MEDIATION]));
        $this->getJson('/api/cda/periods/2026/mediation-cases')->assertForbidden();
        $this->postJson('/api/cda/periods/2026/mediation-cases', $body)->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW_MEDIATION]));
        $this->getJson('/api/cda/periods/2026/mediation-cases')->assertOk();
        $this->postJson('/api/cda/periods/2026/mediation-cases', $body)->assertForbidden();
        $this->putJson("/api/cda/mediation-cases/{$case->id}", ['meetings_count' => 1])->assertForbidden();
        $this->deleteJson("/api/cda/mediation-cases/{$case->id}")->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_MEDIATION]));
        $this->getJson('/api/cda/periods/2026/mediation-cases')->assertForbidden();
        $this->postJson('/api/cda/periods/2026/mediation-cases', $body)->assertCreated();
        $this->putJson("/api/cda/mediation-cases/{$case->id}", ['meetings_count' => 1])->assertOk();
        $this->deleteJson("/api/cda/mediation-cases/{$case->id}")->assertOk();
    }

    public function test_a_deleted_cases_number_is_never_reused_and_its_documents_stay_linked(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::VIEW_MEDIATION, CdaAccess::MANAGE_MEDIATION]));
        $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2026-02-10', 'status' => 'pending'])->assertCreated();
        $last = $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2026-03-10', 'status' => 'pending'])->assertCreated()->json('data');
        $document = CdaDocument::factory()->create(['period_id' => $this->period()->id, 'category' => 'other', 'subject_type' => 'case', 'subject_id' => $last['id']]);

        $this->deleteJson("/api/cda/mediation-cases/{$last['id']}")->assertOk();

        $this->assertSoftDeleted('cda_mediation_cases', ['id' => $last['id']]);
        $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2026-04-01', 'status' => 'pending'])
            ->assertCreated()->assertJsonPath('data.case_number', 'MC-2026-0003');
        $this->assertNotContains($last['id'], array_column($this->getJson('/api/cda/periods/2026/mediation-cases')->json('data'), 'id'));

        $document->refresh();
        $this->assertSame('case', $document->subject_type);
        $this->assertSame($last['case_number'], CdaMediationCase::withTrashed()->findOrFail($document->subject_id)->case_number);
        $this->assertContains($document->id, array_column($this->getJson('/api/cda/periods/2026/documents')->assertOk()->json('data'), 'id'));
    }
}
