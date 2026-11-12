<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\CdaReportTemplate;
use App\Services\Cda\ReportTypes;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\TemplateDefinitionValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Templates (CDA design §2, §3): the seeded v1 templates are valid and marked
 * unverified; the validator refuses unknown keys and params, cycles, bad
 * references, free patterns and an overridable loan, accounting, fs, share
 * capital or savings field; the manager keeps an active template immutable
 * (409), refuses overlapping active templates (422) and validates on
 * activation and on the dry-run.
 */
class CdaTemplatesTest extends TestCase
{
    use SetupLendyPH;

    private TemplateDefinitionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->validator = app(TemplateDefinitionValidator::class);
    }

    private function capr(): array
    {
        return CdaReportTemplate::query()->where('report_type', 'capr')->where('status', 'active')->firstOrFail()->definition;
    }

    public function test_every_report_type_has_one_seeded_active_unverified_valid_template(): void
    {
        foreach (ReportTypes::ALL as $type) {
            $templates = CdaReportTemplate::query()->where('report_type', $type)->where('status', 'active')->get();
            $this->assertCount(1, $templates, "{$type} has one active template.");
            $template = $templates->first();
            $this->assertFalse($template->definition['meta']['verified'], "{$type} is marked to verify.");
            $this->assertSame([], $this->validator->validate($template->definition, $type), "{$type}'s seeded definition is valid.");
            $this->assertSame('2026-01-01', $template->effective_from->toDateString());
        }
    }

    public function test_the_seeded_capr_uses_every_source_family_but_mediation(): void
    {
        $families = collect($this->validator->sourceKeysIn($this->capr()))
            ->map(fn (string $key) => SourceCatalog::KEYS[$key]['family'])
            ->unique()->sort()->values()->all();

        $every = array_values(array_diff(SourceCatalog::FAMILIES, ['mediation']));
        sort($every);

        $this->assertSame($every, $families, 'Mediation figures stay in the mediation report.');
    }

    public function test_required_documents_are_needed_to_submit_never_to_finalize(): void
    {
        foreach (CdaReportTemplate::query()->whereIn('version', ['2026.1', '2026.2'])->get() as $template) {
            foreach ($template->definition['documents'] ?? [] as $document) {
                $this->assertSame('submit', $document['stage'], "{$template->report_type}: {$document['key']} is produced after finalizing.");
            }

            foreach ($template->definition['rules'] ?? [] as $rule) {
                if ($rule['type'] === 'document') {
                    $this->assertSame('submit', $rule['stage'], "{$template->report_type}: {$rule['id']}");
                }
            }
        }
    }

    public function test_the_sworn_statement_is_dated_from_the_period_start_to_the_deadline(): void
    {
        $sworn = CdaReportTemplate::query()->where('report_type', 'sworn_statement')->where('status', 'active')->firstOrFail()->definition;
        $rule = collect($sworn['rules'])->firstWhere('type', 'date_range');

        $this->assertSame(['from' => 'period_start', 'to' => 'effective_deadline'], ['from' => $rule['from'], 'to' => $rule['to']]);

        $bad = $sworn;
        $bad['rules'][0]['to'] = 'next_tuesday';
        $messages = collect($this->validator->validate($bad, 'sworn_statement'))->pluck('message')->implode("\n");
        $this->assertStringContainsString('next_tuesday', $messages);
    }

    public function test_excel_cells_accept_quoted_and_absolute_sheet_addresses(): void
    {
        $withCells = function (array $cells, string $draftCell): array {
            $definition = $this->capr();
            $definition['export']['excel'] = ['mode' => 'template', 'cells' => $cells, 'draft_cell' => $draftCell];

            return $definition;
        };

        $valid = $withCells([
            "'Annex A'!C3" => 'general.cooperative_name',
            "'Sched-1'!B2" => 'membership.members_total',
            "'Annex (A)'!D4" => 'profile.tin',
            'Sheet1!$C$5' => 'general.tin',
            "'O''Brien'!a1" => 'general.address',
        ], "'Annex A'!\$A\$1");
        $this->assertSame([], $this->validator->validate($valid, 'capr'));

        foreach (["'Bad/Name'!A1", 'Sheet1!A0', "'Annex A!A1", "'Annex'A'!A1", "'Colon:'!B2"] as $bad) {
            $errors = $this->validator->validate($withCells([$bad => 'general.tin'], 'CAPR!A1'), 'capr');
            $this->assertNotSame([], $errors, $bad);

            $errors = $this->validator->validate($withCells(['CAPR!C5' => 'general.tin'], $bad), 'capr');
            $this->assertStringContainsString('draft_cell', collect($errors)->pluck('path')->implode(' '), $bad);
        }
    }

    public function test_mediation_sources_are_refused_outside_the_mediation_template(): void
    {
        $leaks = [
            'field' => function (array $d): array {
                $d['sections'][] = ['key' => 'disputes', 'title' => 'Disputes', 'kind' => 'fields', 'fields' => [
                    ['key' => 'cases', 'label' => 'Cases', 'type' => 'number', 'source' => ['key' => 'mediation.summary']],
                ]];

                return $d;
            },
            'table' => function (array $d): array {
                $d['sections'][] = ['key' => 'case_list', 'title' => 'Cases', 'kind' => 'table', 'source' => ['key' => 'mediation.cases'], 'columns' => [['key' => 'case_number', 'label' => 'Case']]];

                return $d;
            },
            'report field' => function (array $d): array {
                $d['sections'][] = ['key' => 'disputes', 'title' => 'Disputes', 'kind' => 'fields', 'fields' => [
                    ['key' => 'cases', 'label' => 'Cases', 'type' => 'number', 'source' => ['key' => 'report.field', 'params' => ['type' => 'mediation', 'part' => 'h1', 'field' => 'summary.cases_total']]],
                ]];

                return $d;
            },
            'rule operand' => function (array $d): array {
                $d['rules'][] = ['id' => 'cases_leak', 'type' => 'compare', 'left' => 'source:mediation.summary', 'op' => 'gte', 'right' => 0, 'severity' => 'info', 'category' => 'invalid_data'];

                return $d;
            },
        ];

        foreach ($leaks as $label => $leak) {
            $messages = collect($this->validator->validate($leak($this->capr()), 'capr'))->pluck('message')->implode("\n");
            $this->assertStringContainsString('Mediation figures are restricted', $messages, $label);
        }

        $mediation = CdaReportTemplate::query()->where('report_type', 'mediation')->where('status', 'active')->firstOrFail()->definition;
        $this->assertSame([], $this->validator->validate($mediation, 'mediation'));
    }

    public function test_the_seed_migration_runs_safely_twice(): void
    {
        $count = CdaReportTemplate::query()->count();
        (require database_path('migrations/2026_11_11_100300_seed_cda_templates.php'))->up();
        $this->assertSame($count, CdaReportTemplate::query()->count());
    }

    public function test_the_validator_refuses_bad_keys_params_references_cycles_and_overridable_sources(): void
    {
        $definition = $this->capr();

        $unknownSource = $definition;
        $unknownSource['sections'][0]['fields'][0]['source'] = ['key' => 'members.everyone'];
        $this->assertErrorMentions($unknownSource, 'members.everyone');

        $badParam = $definition;
        $badParam['sections'][0]['fields'][0]['source'] = ['key' => 'members.count', 'params' => ['gender' => 'other']];
        $this->assertErrorMentions($badParam, 'gender');

        $unknownParam = $definition;
        $unknownParam['sections'][0]['fields'][0]['source'] = ['key' => 'members.count', 'params' => ['colour' => 'red']];
        $this->assertErrorMentions($unknownParam, 'colour');

        $overridable = $definition;
        $overridable['sections'][] = ['key' => 'extra', 'title' => 'Extra', 'kind' => 'fields', 'fields' => [
            ['key' => 'loans_out', 'label' => 'Loans', 'type' => 'money', 'source' => ['key' => 'loans.outstanding', 'params' => ['measure' => 'principal']], 'overridable' => true],
        ]];
        $this->assertErrorMentions($overridable, 'can\'t be overridden');

        $cycle = $definition;
        $cycle['sections'][] = ['key' => 'loop', 'title' => 'Loop', 'kind' => 'fields', 'fields' => [
            ['key' => 'a', 'label' => 'A', 'type' => 'number', 'calc' => ['op' => 'add', 'args' => ['b', 1]]],
            ['key' => 'b', 'label' => 'B', 'type' => 'number', 'calc' => ['op' => 'sub', 'args' => ['a', 1]]],
        ]];
        $this->assertErrorMentions($cycle, 'cycle');

        $badOp = $definition;
        $badOp['sections'][] = ['key' => 'ops', 'title' => 'Ops', 'kind' => 'fields', 'fields' => [
            ['key' => 'x', 'label' => 'X', 'type' => 'number', 'calc' => ['op' => 'eval', 'args' => ['1+1']]],
        ]];
        $this->assertErrorMentions($badOp, 'eval');

        $badRef = $definition;
        $badRef['rules'][] = ['id' => 'nope', 'type' => 'sum_equals', 'parts' => ['nowhere_field'], 'total' => 'members_total', 'severity' => 'warning', 'category' => 'member_data'];
        $this->assertErrorMentions($badRef, 'nowhere_field');

        $freePattern = $definition;
        $freePattern['rules'][] = ['id' => 'regex', 'type' => 'pattern', 'field' => 'members_total', 'pattern' => '/^.*$/', 'severity' => 'warning', 'category' => 'invalid_data'];
        $this->assertErrorMentions($freePattern, 'pattern');

        $badCategory = $definition;
        $badCategory['rules'][] = ['id' => 'cat', 'type' => 'check', 'check' => 'accounting.year_closed', 'severity' => 'critical', 'category' => 'vibes'];
        $this->assertErrorMentions($badCategory, 'vibes');

        $badHref = $definition;
        $badHref['rules'][] = ['id' => 'href', 'type' => 'check', 'check' => 'profile.complete', 'severity' => 'warning', 'category' => 'missing_required_data', 'fix_href' => 'https://evil.example/'];
        $this->assertErrorMentions($badHref, 'fix_href');

        $badCheck = $definition;
        $badCheck['rules'][] = ['id' => 'chk', 'type' => 'check', 'check' => 'php.exec', 'severity' => 'critical', 'category' => 'invalid_data'];
        $this->assertErrorMentions($badCheck, 'php.exec');

        $wrongParts = $definition;
        $wrongParts['parts'] = ['h1'];
        $this->assertErrorMentions($wrongParts, 'parts');
    }

    public function test_the_dry_run_answers_valid_or_the_errors_in_words_and_writes_nothing(): void
    {
        $count = CdaReportTemplate::query()->count();
        $bad = $this->capr();
        $bad['sections'][0]['fields'][0]['source'] = ['key' => 'members.everyone'];

        $this->postJson('/api/cda/templates/validate', ['report_type' => 'capr', 'definition' => $this->capr()])
            ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.errors', []);
        $response = $this->postJson('/api/cda/templates/validate', ['report_type' => 'capr', 'definition' => $bad])
            ->assertOk()->assertJsonPath('data.valid', false);

        $this->assertNotEmpty($response->json('data.errors.0.path'));
        $this->assertStringContainsString('members.everyone', $response->json('data.errors.0.message'));
        $this->assertSame($count, CdaReportTemplate::query()->count());
    }

    public function test_a_draft_is_created_edited_activated_and_retired_and_an_active_one_is_immutable(): void
    {
        $created = $this->postJson('/api/cda/templates', [
            'report_type' => 'capr', 'version' => '2027.1', 'name' => 'CAPR CY2027',
            'effective_from' => '2027-01-01', 'definition' => $this->capr(),
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.verified', false);
        $id = $created->json('data.id');

        $this->postJson('/api/cda/templates', [
            'report_type' => 'capr', 'version' => '2027.1', 'name' => 'Again', 'effective_from' => '2027-01-01', 'definition' => $this->capr(),
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->putJson("/api/cda/templates/{$id}", ['name' => 'CAPR CY2027 (draft 2)'])->assertOk()->assertJsonPath('data.name', 'CAPR CY2027 (draft 2)');

        $this->postJson("/api/cda/templates/{$id}/activate")->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        $seeded = CdaReportTemplate::query()->where('report_type', 'capr')->where('version', '!=', '2027.1')->firstOrFail();
        $this->postJson("/api/cda/templates/{$seeded->id}/retire", ['reason' => 'Superseded by CY2027'])->assertOk();

        $this->postJson("/api/cda/templates/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertNotNull(CdaReportTemplate::query()->findOrFail($id)->activated_at);

        $this->putJson("/api/cda/templates/{$id}", ['name' => 'Changed'])->assertStatus(409);
        $this->postJson("/api/cda/templates/{$id}/activate")->assertStatus(409);
        $this->postJson("/api/cda/templates/{$id}/retire")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/cda/templates/{$id}/retire", ['reason' => 'Replaced by the official form'])
            ->assertOk()->assertJsonPath('data.status', 'retired')->assertJsonPath('data.reason', 'Replaced by the official form');
        $this->putJson("/api/cda/templates/{$id}", ['name' => 'Changed'])->assertStatus(409);

        $this->assertTrue(AuditLog::query()->where('auditable_type', CdaReportTemplate::class)->where('auditable_id', $id)->exists());
    }

    public function test_activating_an_invalid_definition_answers_422_with_the_errors(): void
    {
        $bad = $this->capr();
        $bad['sections'][0]['fields'][0]['source'] = ['key' => 'members.everyone'];
        $draft = CdaReportTemplate::factory()->create(['report_type' => 'capr', 'version' => '2030.1', 'effective_from' => '2030-01-01', 'definition' => $bad]);

        $seeded = CdaReportTemplate::query()->where('report_type', 'capr')->where('status', 'active')->firstOrFail();
        $this->postJson("/api/cda/templates/{$seeded->id}/retire", ['reason' => 'Superseded'])->assertOk();

        $this->postJson("/api/cda/templates/{$draft->id}/activate")->assertUnprocessable()->assertJsonValidationErrors('definition');
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_an_active_template_refuses_changes_at_the_model_too(): void
    {
        $active = CdaReportTemplate::query()->where('report_type', 'pesos')->where('status', 'active')->firstOrFail();

        $this->expectException(\LogicException::class);
        $active->update(['name' => 'Edited behind the API']);
    }

    public function test_an_excel_workbook_is_attached_to_a_draft_only(): void
    {
        Storage::fake('private');
        $draft = CdaReportTemplate::factory()->create(['report_type' => 'atir', 'version' => '2027.1', 'effective_from' => '2027-01-01']);
        $active = CdaReportTemplate::query()->where('report_type', 'atir')->where('status', 'active')->firstOrFail();
        $xlsx = fn () => UploadedFile::fake()->createWithContent('ATIR-2026.xlsx', file_get_contents(base_path('tests/Fixtures/cda/blank.xlsx')));

        $this->post("/api/cda/templates/{$draft->id}/excel-file", ['file' => $xlsx()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.excel_template.file_name', 'ATIR-2026.xlsx');
        $this->assertNotNull($draft->fresh()->excel_template_document_id);

        $this->post("/api/cda/templates/{$active->id}/excel-file", ['file' => $xlsx()], ['Accept' => 'application/json'])->assertStatus(409);
        $this->post("/api/cda/templates/{$draft->id}/excel-file", ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    private function assertErrorMentions(array $definition, string $needle): void
    {
        $errors = $this->validator->validate($definition, 'capr');
        $text = collect($errors)->map(fn (array $error) => $error['path'].': '.$error['message'])->implode("\n");

        $this->assertNotSame([], $errors, "Expected an error mentioning {$needle}.");
        $this->assertStringContainsString($needle, $text);
    }
}
