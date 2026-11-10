<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\CdaReport;
use App\Models\CdaReportTemplate;
use App\Models\CdaSetting;
use App\Services\BranchAccess;
use App\Services\Cda\TemplateDefinitionValidator;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The C1 review's template and access fixes:
 * - M2: `overridable` is refused on a calculation that depends, directly or
 *   through other calculations, on a fixed source (loans, accounting, fs,
 *   share capital, savings, another report, PESOS), and an `editable` table
 *   can't read a fixed source;
 * - M3: every object in a definition takes only its own keys;
 * - L1: a template saved twice at once answers 422 or 409, never 500;
 * - L2: an edit checks `effective_until` against the stored `effective_from`;
 * - L3: an active template only ever becomes retired;
 * - L4: the permission is checked before the record is looked up.
 */
class CdaReviewFixesTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

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

    private function messages(array $definition): string
    {
        return collect($this->validator->validate($definition, 'capr'))->map(fn (array $e) => $e['path'].': '.$e['message'])->implode("\n");
    }

    public function test_an_overridable_calculation_on_a_fixed_source_is_refused_directly_or_transitively(): void
    {
        $direct = $this->capr();
        $direct['sections'][] = ['key' => 'derived', 'title' => 'Derived', 'kind' => 'fields', 'fields' => [
            ['key' => 'net_loans', 'label' => 'Net', 'type' => 'money', 'overridable' => true, 'calc' => ['op' => 'sub', 'args' => ['loans_outstanding', 'provision_gap']]],
        ]];
        $this->assertStringContainsString("can't be overridden", $this->messages($direct));

        $transitive = $this->capr();
        $transitive['sections'][] = ['key' => 'derived', 'title' => 'Derived', 'kind' => 'fields', 'fields' => [
            ['key' => 'step_one', 'label' => 'Step', 'type' => 'money', 'calc' => ['op' => 'add', 'args' => ['total_assets', 0]]],
            ['key' => 'step_two', 'label' => 'Step', 'type' => 'money', 'overridable' => true, 'calc' => ['op' => 'mul', 'args' => ['step_one', 2]]],
        ]];
        $this->assertStringContainsString('derived.step_two', $this->messages($transitive));

        $report = $this->capr();
        $report['sections'][] = ['key' => 'cross', 'title' => 'Cross', 'kind' => 'fields', 'fields' => [
            ['key' => 'other', 'label' => 'Other', 'type' => 'money', 'overridable' => true, 'source' => ['key' => 'report.field', 'params' => ['type' => 'pesos', 'field' => 'result.score']]],
        ]];
        $this->assertStringContainsString("can't be overridden", $this->messages($report));

        $manualOnly = $this->capr();
        $manualOnly['sections'][] = ['key' => 'derived', 'title' => 'Derived', 'kind' => 'fields', 'fields' => [
            ['key' => 'staff_total', 'label' => 'Staff', 'type' => 'number', 'overridable' => true, 'calc' => ['op' => 'add', 'args' => ['employees_regular', 'employees_casual']]],
        ]];
        $this->assertSame('', $this->messages($manualOnly));

        $sumOfFixedTable = $this->capr();
        $sumOfFixedTable['sections'][] = ['key' => 'derived', 'title' => 'Derived', 'kind' => 'fields', 'fields' => [
            ['key' => 'released_total', 'label' => 'Released', 'type' => 'money', 'overridable' => true, 'calc' => ['op' => 'sum_column', 'args' => ['loans_by_product', 'amount']]],
        ]];
        $this->assertStringContainsString('derived.released_total', $this->messages($sumOfFixedTable));

        $editableTable = $this->capr();
        $editableTable['sections'][] = ['key' => 'products_copy', 'title' => 'Products', 'kind' => 'table', 'editable' => true, 'source' => ['key' => 'loans.by_product'], 'columns' => [['key' => 'product', 'label' => 'Product', 'format' => 'text']]];
        $this->assertStringContainsString('products_copy', $this->messages($editableTable));
    }

    public function test_every_object_refuses_keys_it_doesnt_know(): void
    {
        $mutations = [
            'sections.0.colour' => function (array $d): array {
                $d['sections'][0]['colour'] = 'red';

                return $d;
            },
            'sections.0.fields.0.regex' => function (array $d): array {
                $d['sections'][0]['fields'][0]['regex'] = '/.*/';

                return $d;
            },
            'sections.0.fields.0.source.script' => function (array $d): array {
                $d['sections'][0]['fields'][0]['source']['script'] = 'x';

                return $d;
            },
            'sections.3.columns.0.width' => function (array $d): array {
                $d['sections'][3]['columns'][0]['width'] = 10;

                return $d;
            },
            'rules.0.regex' => function (array $d): array {
                $d['rules'][0]['regex'] = '^.*$';

                return $d;
            },
            'documents.0.path' => function (array $d): array {
                $d['documents'][0]['path'] = '/etc';

                return $d;
            },
            'meta.owner' => function (array $d): array {
                $d['meta']['owner'] = 'x';

                return $d;
            },
            'calc' => function (array $d): array {
                $d['sections'][1]['fields'][3]['calc']['eval'] = '1';

                return $d;
            },
        ];

        foreach ($mutations as $label => $mutate) {
            $messages = $this->messages($mutate($this->capr()));
            $this->assertStringContainsString('Unknown key', $messages, $label);
        }
    }

    public function test_a_template_saved_twice_at_once_answers_422_not_500(): void
    {
        $body = ['report_type' => 'capr', 'version' => '2031.1', 'name' => 'CAPR', 'effective_from' => '2031-01-01', 'definition' => $this->capr()];
        $this->postJson('/api/cda/templates', $body)->assertCreated();

        DB::table('cda_report_templates')->where('version', '2031.1')->update(['version' => '2031.1-x']);
        CdaReportTemplate::creating(function (CdaReportTemplate $template): void {
            if ($template->version === '2031.2') {
                DB::table('cda_report_templates')->insert([
                    'report_type' => 'capr', 'version' => '2031.2', 'name' => 'Raced', 'effective_from' => '2031-01-01',
                    'definition' => '{}', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->postJson('/api/cda/templates', [...$body, 'version' => '2031.2'])->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_the_settings_row_that_template_writes_lock_is_seeded_once(): void
    {
        $this->assertSame(1, CdaSetting::query()->count());

        (require database_path('migrations/2026_11_11_100100_create_cda_tables.php'))->up();

        $this->assertSame(1, CdaSetting::query()->count());
    }

    public function test_an_edit_checks_the_end_date_against_the_stored_start(): void
    {
        $draft = CdaReportTemplate::factory()->create(['report_type' => 'capr', 'version' => '2032.1', 'effective_from' => '2032-01-01']);

        $this->putJson("/api/cda/templates/{$draft->id}", ['effective_until' => '2031-06-30'])->assertUnprocessable()->assertJsonValidationErrors('effective_until');
        $this->putJson("/api/cda/templates/{$draft->id}", ['effective_from' => '2033-01-01', 'effective_until' => '2033-12-31'])->assertOk();

        $bounded = CdaReportTemplate::factory()->create(['report_type' => 'capr', 'version' => '2034.1', 'effective_from' => '2034-01-01', 'effective_until' => '2034-12-31']);
        $this->putJson("/api/cda/templates/{$bounded->id}", ['effective_from' => '2035-01-01'])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
    }

    public function test_an_active_template_only_ever_becomes_retired(): void
    {
        $active = CdaReportTemplate::query()->where('report_type', 'pesos')->where('status', 'active')->firstOrFail();

        try {
            $active->update(['status' => 'draft']);
            $this->fail('An active template went back to draft.');
        } catch (LogicException) {
            $this->assertSame('active', $active->fresh()->status);
        }

        $active->refresh()->update(['status' => 'retired', 'reason' => 'Replaced']);
        $this->assertSame('retired', $active->fresh()->status);

        $draft = CdaReportTemplate::factory()->create(['report_type' => 'pesos', 'version' => '2040.1']);
        $this->expectException(LogicException::class);
        $draft->update(['status' => 'retired']);
    }

    public function test_the_permission_is_checked_before_the_record_is_looked_up(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $report = CdaReport::query()->firstOrFail();
        $template = CdaReportTemplate::query()->firstOrFail();
        $this->actingAs($this->userWith(['cda:view']));

        foreach (["/api/cda/templates/{$template->id}", '/api/cda/templates/999999'] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }

        foreach ([$report->id, 999999] as $id) {
            $this->patchJson("/api/cda/reports/{$id}/deadline", ['override_on' => null])->assertForbidden();
        }

        $this->actingAs($this->userWith(['cda:view', 'cda:settings', BranchAccess::VIEW_ALL]));
        $this->getJson('/api/cda/templates/999999')->assertNotFound();
        $this->patchJson('/api/cda/reports/999999/deadline', ['override_on' => null])->assertNotFound();
    }
}
