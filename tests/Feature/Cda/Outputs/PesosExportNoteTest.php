<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Models\CdaReportValue;
use App\Services\Cda\Exports\ExportContent;
use App\Services\Cda\Exports\ExportContentBuilder;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Pesos\PesosEngine;
use App\Services\Cda\Reports\NamedChecks;
use App\Services\Cda\Reports\ReportDefinition;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\Sources\SourceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An export of a PESOS report carries the "to verify" note while either its
 * template is unverified (`meta.verified`) or the PESOS rules behind its
 * figures still carry their own "to verify" list (the `pesos.result`
 * figures' `meta.to_verify`), drafts and finalized reports alike. A PESOS
 * report with no figure carrying the rules' flag keeps the note.
 */
class PesosExportNoteTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private CdaReport $pesos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $period = app(PeriodOpener::class)->ensure(2026);
        $this->pesos = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'pesos')->firstOrFail();
        app(ReportEngine::class)->refresh($this->pesos);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Sets the template's `meta.verified`, past the model's guard on active templates. */
    private function markTemplateVerified(bool $verified): void
    {
        $template = CdaReportTemplate::query()->findOrFail($this->pesos->template_id);
        $definition = $template->definition;
        $definition['meta']['verified'] = $verified;
        DB::table('cda_report_templates')->where('id', $template->id)->update(['definition' => json_encode($definition)]);
    }

    /** Sets the rules' own flag on every stored PESOS figure. */
    private function markRulesVerified(bool $verified): void
    {
        CdaReportValue::query()->where('report_id', $this->pesos->id)->whereNotNull('source_value')->get()
            ->each(function (CdaReportValue $row) use ($verified): void {
                $source = $row->source_value;
                if (! is_array($source['meta'] ?? null) || ! array_key_exists('to_verify', $source['meta'])) {
                    return;
                }
                $source['meta']['to_verify'] = $verified ? [] : PesosEngine::TO_VERIFY;
                $source['meta']['verified'] = $verified;
                $row->forceFill(['source_value' => $source])->save();
            });
    }

    /**
     * Points the report at a new snapshot freezing this definition and these values.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $values
     */
    private function finalizeWith(array $definition, array $values, int $version): void
    {
        $snapshot = CdaReportSnapshot::query()->create([
            'report_id' => $this->pesos->id,
            'version' => $version,
            'template_id' => $this->pesos->template_id,
            'payload' => json_encode(['template' => ['definition' => $definition, 'version' => '2026.1'], 'values' => $values]),
            'checksum' => str_repeat((string) $version, 64),
            'finalized_by' => $this->admin->id,
            'finalized_at' => now(),
        ]);
        $this->pesos->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id])->save();
    }

    private function content(): ExportContent
    {
        return app(ExportContentBuilder::class)->build($this->pesos->fresh(), $this->admin);
    }

    private function assertNote(bool $expected, string $message): void
    {
        $content = $this->content();
        $this->assertSame($expected, $content->unverified, $message);
        $html = app(PdfRenderer::class)->html($content);
        $expected
            ? $this->assertStringContainsString(ExportContent::UNVERIFIED_NOTE, $html, $message)
            : $this->assertStringNotContainsString(ExportContent::UNVERIFIED_NOTE, $html, $message);
    }

    public function test_the_stored_pesos_figures_carry_the_rules_to_verify_flag(): void
    {
        $flags = CdaReportValue::query()->where('report_id', $this->pesos->id)->get()
            ->map(fn (CdaReportValue $row) => $row->source_value['meta']['to_verify'] ?? null)->filter()->values();

        $this->assertNotEmpty($flags, 'The refreshed PESOS figures keep the rules\' "to verify" list.');
        $this->assertSame(PesosEngine::TO_VERIFY, $flags->first());
    }

    public function test_an_unverified_template_carries_the_note(): void
    {
        $this->markTemplateVerified(false);
        $this->markRulesVerified(true);

        $this->assertNote(true, 'The template is still unverified.');
    }

    public function test_a_verified_template_still_carries_the_note_while_the_rules_are_to_verify(): void
    {
        $this->markTemplateVerified(true);
        $this->markRulesVerified(false);

        $this->assertNote(true, 'The PESOS rules are still to verify.');
    }

    public function test_the_note_goes_only_when_the_template_and_the_rules_are_both_verified(): void
    {
        $this->markTemplateVerified(true);
        $this->markRulesVerified(true);

        $this->assertNote(false, 'Both are verified.');
    }

    public function test_a_pesos_report_with_no_rules_flag_keeps_the_note(): void
    {
        $this->markTemplateVerified(true);
        CdaReportValue::query()->where('report_id', $this->pesos->id)->delete();

        $this->assertNote(true, 'Without a figure carrying the rules\' flag, the note stays.');
    }

    public function test_the_on_screen_check_agrees_with_the_export_note(): void
    {
        $period = $this->pesos->period()->firstOrFail();
        $check = fn (): ?string => app(NamedChecks::class)->run('pesos.rules_verified', [], new SourceContext($period), new ReportDefinition(['sections' => []]));

        foreach ([[false, false, true], [false, true, true], [true, false, true], [true, true, false]] as [$template, $rules, $note]) {
            $this->markTemplateVerified($template);
            $this->markRulesVerified($rules);
            $label = 'template '.($template ? 'verified' : 'unverified').', rules '.($rules ? 'verified' : 'to verify');

            $this->assertSame($note, $check() !== null, "The pesos.rules_verified check, {$label}.");
            $this->assertSame($note, $this->content()->unverified, "The export note, {$label}.");
        }

        CdaReportValue::query()->where('report_id', $this->pesos->id)->delete();
        $this->assertNotNull($check(), 'Without a figure carrying the rules\' flag, the check stays open, as the note does.');
    }

    public function test_a_finalized_pesos_report_reads_both_flags_from_its_snapshot(): void
    {
        $this->markTemplateVerified(true);
        $definition = CdaReportTemplate::query()->findOrFail($this->pesos->template_id)->definition;
        $values = fn (array $toVerify) => CdaReportValue::query()->where('report_id', $this->pesos->id)->get()
            ->map(fn (CdaReportValue $row) => [
                'field_key' => $row->field_key,
                'value' => $row->value,
                'source_value' => $row->source_value,
            ])
            ->map(function (array $row) use ($toVerify): array {
                if (is_array($row['source_value']['meta'] ?? null) && array_key_exists('to_verify', $row['source_value']['meta'])) {
                    $row['source_value']['meta']['to_verify'] = $toVerify;
                    $row['source_value']['meta']['verified'] = true;
                }

                return $row;
            })->all();

        $unverified = $definition;
        $unverified['meta']['verified'] = false;
        $this->markRulesVerified(true);

        $this->finalizeWith($definition, $values(PesosEngine::TO_VERIFY), 1);
        $this->assertNote(true, 'The snapshot froze rules still to verify; the live figures don\'t count.');

        $this->finalizeWith($definition, $values([]), 2);
        $this->assertNote(false, 'The snapshot froze a verified template and verified rules.');

        $this->finalizeWith($unverified, $values([]), 3);
        $this->assertNote(true, 'The snapshot froze an unverified template.');
    }
}
