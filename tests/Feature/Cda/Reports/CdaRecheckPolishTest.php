<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\TemplateDefinitionValidator;
use App\Services\Cda\YearEndCapture;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The CDA recheck's backend polish:
 * - a document that a template's document rule already checks raises one
 *   issue (the rule's, which names the document), counted once in readiness
 *   and the critical count;
 * - the year-end capture's report refresh can fail without masking or
 *   replacing the capture's own result or error.
 */
class CdaRecheckPolishTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_seeded_capr_raises_one_issue_for_a_missing_signed_copy_and_uploading_it_clears_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $report = $this->report($this->openYear(), 'capr');
        app(ReportEngine::class)->refresh($report);
        $report->refresh();

        $missing = collect($report->issues)->filter(fn (array $issue) => str_contains($issue['message'], 'CAPR') && str_contains($issue['message'], 'uploaded'));
        $this->assertSame(["The signed CAPR (final copy) hasn't been uploaded."], $missing->pluck('message')->values()->all());
        $this->assertSame('rule:signed_capr_uploaded', $missing->first()['id']);
        $this->assertNotContains('document:signed_capr', collect($report->issues)->pluck('id')->all());
        $this->assertSame(['rule:signed_capr_uploaded'], array_values(array_filter(
            $report->readiness_breakdown['failed'],
            fn (string $id) => in_array($id, ['rule:signed_capr_uploaded', 'document:signed_capr'], true),
        )), 'Readiness counts the document once.');
        $criticalBefore = $report->critical_count;
        $totalBefore = $report->readiness_breakdown['total'];

        $document = CdaDocument::factory()->create(['period_id' => $report->period_id, 'category' => 'capr', 'report_id' => $report->id]);
        CdaDocumentVersion::factory()->create(['cda_document_id' => $document->id, 'final_key' => (string) $document->id]);
        app(ReportEngine::class)->refresh($report);
        $report->refresh();

        $ids = collect($report->issues)->pluck('id');
        $this->assertNotContains('rule:signed_capr_uploaded', $ids->all());
        $this->assertNotContains('document:signed_capr', $ids->all());
        $this->assertSame($criticalBefore - 1, $report->critical_count, 'One critical issue goes with the upload.');
        $this->assertSame($totalBefore, $report->readiness_breakdown['total']);
        $this->assertNotContains('rule:signed_capr_uploaded', $report->readiness_breakdown['failed']);
    }

    public function test_only_capr_lists_a_document_its_own_rule_already_checks(): void
    {
        $doubles = [];

        foreach (glob(database_path('cda-templates/*.json')) as $path) {
            $json = json_decode((string) file_get_contents($path), true);
            $definition = $json['definition'] ?? $json;
            $this->assertSame([], app(TemplateDefinitionValidator::class)->validate($definition, (string) $json['report_type']), basename($path));

            foreach ($definition['documents'] ?? [] as $document) {
                foreach ($definition['rules'] ?? [] as $rule) {
                    if (($rule['type'] ?? null) === 'document' && $rule['document_category'] === $document['category']) {
                        $doubles[] = basename($path, '.json').':'.$document['key'];
                    }
                }
            }
        }

        $this->assertSame(['capr:signed_capr'], $doubles, 'A new double needs the same one-issue check.');
    }

    public function test_a_failed_report_refresh_after_a_capture_is_reported_and_the_capture_still_stands(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        Auth::logout();
        Exceptions::fake();
        $this->failQueries(fn (string $sql) => self::loadsReports($sql), 'The reports could not be loaded.');

        $this->artisan('cda:capture-year-end')->expectsOutputToContain('Captured 2026')->assertSuccessful();

        $period = CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();
        $this->assertNotNull($period->year_end_captured_at);
        $this->assertSame('2026-12-31', $period->year_end_capture['as_of']);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The reports could not be loaded.');
    }

    public function test_a_failed_capture_keeps_its_own_error_when_the_refresh_fails_too(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        Auth::logout();
        Exceptions::fake();
        $this->failQueries(fn (string $sql) => str_contains($sql, 'from `cda_reporting_periods`') && str_contains($sql, 'for update'), 'The capture failed.');
        $this->failQueries(fn (string $sql) => self::loadsReports($sql), 'The reports could not be loaded.');

        try {
            app(YearEndCapture::class)->capture('2026-12-31');
            $this->fail('The capture should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('The capture failed.', $e->getMessage(), 'The refresh never replaces the capture\'s own error.');
        }

        $this->assertNull(CdaReportingPeriod::query()->where('year', 2026)->firstOrFail()->year_end_captured_at);
        $this->assertSame(11, CdaReport::query()->count(), 'The period was opened with its reports.');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The reports could not be loaded.');
    }

    /** PeriodOpener::refreshReports()'s first query: the period's applicable reports. */
    private static function loadsReports(string $sql): bool
    {
        return str_contains($sql, 'from `cda_reports`') && str_contains($sql, '`applicable` = ?') && str_contains($sql, 'order by `id`');
    }

    /** The next query matching $matches fails, as a lost connection would, after it ran. */
    private function failQueries(callable $matches, string $message): void
    {
        $failed = false;

        DB::listen(function (QueryExecuted $query) use ($matches, $message, &$failed): void {
            if (! $failed && $matches($query->sql)) {
                $failed = true;

                throw new RuntimeException($message);
            }
        });
    }
}
