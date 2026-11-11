<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaDocumentVersion;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\Cda\Exports\ExportContent;
use App\Services\Cda\Exports\ExportContentBuilder;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\PeriodOpener;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;
use ZipArchive;

/**
 * Export review fixes (H1, L9, L14, L15): negative money reads "(₱400.00)"
 * in the PDF and the Excel text and stays a signed number in Excel cells; a
 * report that changes while it renders answers 409 and keeps nothing; a
 * storage failure answers 503, records no version and leaves no partial
 * file; exports share the project's `exports` limiter (5 a minute per user).
 */
class ExportSafetyTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private CdaReport $capr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $period = app(PeriodOpener::class)->ensure(2026);
        $this->capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exporter(): User
    {
        return $this->userWith(['cda:view', 'cda:export', 'branches:view_all']);
    }

    private function value(string $fieldKey, mixed $value): void
    {
        CdaReportValue::query()->updateOrCreate(['report_id' => $this->capr->id, 'field_key' => $fieldKey], ['value' => $value]);
    }

    private function negativeFigures(): void
    {
        $this->value('financial.net_surplus', '-400.00');
        $this->value('loan_classification', [
            ['label' => 'Adjusted', 'loan_count' => 1, 'outstanding_principal' => '-1500.50', 'rate_percent' => '5.00', 'required' => '-75.03'],
        ]);
    }

    public function test_negative_money_reads_in_parentheses_in_the_pdf(): void
    {
        $this->negativeFigures();

        $html = app(PdfRenderer::class)->html(app(ExportContentBuilder::class)->build($this->capr, $this->admin));

        $this->assertStringContainsString('(₱400.00)', $html, 'A field.');
        $this->assertStringContainsString('(₱1,500.50)', $html, 'A table cell.');
        $this->assertStringContainsString('(₱75.03)', $html);
        $this->assertStringNotContainsString('₱0.00', $html);
    }

    public function test_negative_money_stays_a_signed_number_with_a_parentheses_format_in_excel(): void
    {
        $this->negativeFigures();
        $this->value('general.contact', 'see note');

        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertCreated()->json('data');

        $parts = $this->unzip(CdaDocumentVersion::query()->with('file')->findOrFail($data['id'])->file->file_path);
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('s="1"><v>-400.00</v>', $sheet);
        $this->assertStringContainsString('s="1"><v>-1500.50</v>', $sheet);
        $this->assertStringContainsString('formatCode="&quot;₱&quot;#,##0.00;(&quot;₱&quot;#,##0.00)"', $parts['xl/styles.xml']);
    }

    public function test_negative_money_shown_as_text_in_excel_reads_in_parentheses(): void
    {
        $this->value('financial.net_surplus', -400.5);

        $data = $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])
            ->assertCreated()->json('data');

        $sheet = $this->unzip(CdaDocumentVersion::query()->with('file')->findOrFail($data['id'])->file->file_path)['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('>(₱400.50)<', $sheet, 'A figure stored as a JSON number is written as its display text.');
    }

    public function test_a_report_that_changes_while_rendering_answers_409_and_keeps_nothing(): void
    {
        $report = $this->capr;
        $this->app->instance(PdfRenderer::class, new class($report) extends PdfRenderer
        {
            public function __construct(private readonly CdaReport $report) {}

            public function render(ExportContent $content): string
            {
                $snapshot = CdaReportSnapshot::query()->create([
                    'report_id' => $this->report->id, 'version' => 1, 'template_id' => $this->report->template_id,
                    'payload' => '{}', 'checksum' => str_repeat('d', 64), 'finalized_at' => now(),
                ]);
                CdaReport::query()->whereKey($this->report->id)->update(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id]);

                return parent::render($content);
            }
        });

        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'pdf'])
            ->assertConflict()
            ->assertJsonPath('message', 'The report changed while it was being exported. Export it again.');

        $this->assertSame(0, CdaDocumentVersion::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles('cda/exports'));
    }

    public function test_a_save_that_returns_false_answers_503_records_nothing_and_removes_the_partial_file(): void
    {
        $this->assertFailedSave(fn ($put) => $put->andReturn(false));
    }

    public function test_a_save_that_throws_answers_503_records_nothing_and_removes_the_partial_file(): void
    {
        $this->assertFailedSave(fn ($put) => $put->andThrow(new RuntimeException('disk full')));
    }

    private function assertFailedSave(callable $fails): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $fails($disk->shouldReceive('put')->once());
        $disk->shouldReceive('delete')->once()->with(Mockery::pattern('#^cda/exports/'.$this->capr->id.'/[0-9a-f-]{36}\.pdf$#'))->andReturn(true);
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);

        $this->actingAs($this->exporter())->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'pdf'])
            ->assertStatus(503)
            ->assertJsonPath('message', "The export couldn't be saved to the server's storage, so nothing was recorded. Try again; if it keeps failing, ask your administrator to check the disk.");
        $this->assertSame(0, CdaDocumentVersion::query()->count());
    }

    public function test_exports_use_the_shared_exports_limiter_of_five_a_minute_per_user(): void
    {
        $user = $this->exporter();
        $other = $this->exporter();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])->assertCreated();
        }

        $this->actingAs($user)->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])->assertStatus(429);
        $this->actingAs($other)->postJson("/api/cda/reports/{$this->capr->id}/exports", ['format' => 'xlsx'])->assertCreated();
        $this->actingAs($user)->getJson("/api/cda/reports/{$this->capr->id}/exports")->assertOk();

        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/cda/reports/{report}/exports' && in_array('POST', $route->methods(), true));
        $this->assertContains('throttle:exports', $route->gatherMiddleware());
    }

    /** @return array<string, string> */
    private function unzip(string $storedPath): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-test');
        file_put_contents($path, Storage::disk('private')->get($storedPath));
        $zip = new ZipArchive;
        $zip->open($path);
        $parts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $parts;
    }
}
