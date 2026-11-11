<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaOfficer;
use App\Models\CdaReport;
use App\Services\Cda\Exports\ExportContentBuilder;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Reports\ReportEngine;
use DOMElement;
use DOMNode;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A CDA PDF keeps each section heading on the page where its content starts:
 * a heading never sits alone at the foot of a page with its table on the
 * next. Checked on a real CAPR with 40 officers, laid out by dompdf with the
 * renderer's view and paper, and pushed down so the "Officers" heading falls
 * just above the foot of page 1, where it used to be left behind.
 */
class PdfHeadingsKeptWithContentTest extends TestCase
{
    use SetupLendyPH;

    /** A4 height, in pt. */
    private const PAGE_HEIGHT_PT = 841.89;

    /** Room left under the heading at the foot of the page: less than one table row. */
    private const GAP_PT = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_capr_heading_at_the_foot_of_a_page_moves_to_the_page_of_its_table(): void
    {
        CdaOfficer::factory()->count(40)->create();
        $period = app(PeriodOpener::class)->ensure(2026);
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        app(ReportEngine::class)->refresh($capr);
        $content = app(ExportContentBuilder::class)->build($capr->fresh(), $this->admin);
        $this->assertSame('portrait', $content->orientation);
        $html = app(PdfRenderer::class)->html($content);

        $plain = $this->layout($html);
        $this->assertNotNull($plain['officers'], 'The CAPR has an Officers heading.');
        $this->assertSame(1, $plain['officers']['page']);

        // Push everything down so the heading ends GAP_PT above the foot of page 1.
        $bottom = self::PAGE_HEIGHT_PT - PdfRenderer::MARGIN_BOTTOM_MM * 72 / 25.4;
        $filler = $bottom - self::GAP_PT - $plain['officers']['bottom'];
        $this->assertGreaterThan(0, $filler);
        $pushed = $this->layout(str_replace('<body>', '<body><div style="height: '.round($filler, 2).'pt"></div>', $html));

        $this->assertGreaterThan(1, max(array_column($pushed['sequence'], 'page')), 'The report runs over more than one page.');
        $headings = $this->headingPages($pushed['sequence']);
        $this->assertArrayHasKey('Officers', $headings);

        foreach ($headings as $title => $pages) {
            $this->assertSame($pages['heading'], $pages['content'], "The \"{$title}\" heading is on page {$pages['heading']}, its content starts on page {$pages['content']}.");
        }

        $this->assertSame(2, $headings['Officers']['heading'], 'The Officers heading moves to page 2, with its table.');
    }

    /**
     * Lays the HTML out as PdfRenderer does and gives the text drawn, in
     * order, with its page and the top-level block it belongs to (fixed
     * blocks, the footer and the DRAFT mark, left out), and where the
     * Officers heading ends on its page.
     *
     * @return array{sequence: list<array{block: string, text: string, page: int}>, officers: array{page: int, bottom: float}|null}
     */
    private function layout(string $html): array
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont(PdfRenderer::FONT);
        $options->setChroot([resource_path('views/cda')]);
        $options->setTempDir(sys_get_temp_dir());

        $sequence = [];
        $officers = null;
        $dompdf = new Dompdf($options);
        $dompdf->setCallbacks([['event' => 'begin_frame', 'f' => function (Frame $frame, Canvas $canvas) use (&$sequence, &$officers): void {
            $node = $frame->get_node();
            $page = $canvas->get_page_number();

            if ($node->nodeName === 'h2' && trim($node->textContent) === 'Officers' && $officers === null) {
                [, $y] = $frame->get_position();
                $officers = ['page' => $page, 'bottom' => (float) $y + (float) $frame->get_margin_height()];
            }

            if ($node->nodeName !== '#text' || trim($node->nodeValue) === '') {
                return;
            }

            $top = self::topBlock($node);

            if ($top === null || preg_match('/\b(footer|watermark)\b/', $top->getAttribute('class')) === 1) {
                return;
            }

            $sequence[] = ['block' => $top->nodeName, 'text' => trim($node->nodeValue), 'page' => $page];
        }]]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return ['sequence' => $sequence, 'officers' => $officers];
    }

    /** The body's child that holds the node (dompdf clones the body when it splits a page). */
    private static function topBlock(DOMNode $node): ?DOMElement
    {
        $top = $node;

        while ($top->parentNode !== null && $top->parentNode->nodeName !== 'body') {
            $top = $top->parentNode;
        }

        return $top->parentNode !== null && $top instanceof DOMElement ? $top : null;
    }

    /**
     * For each section heading, its page and the page of the first text
     * drawn after it.
     *
     * @param  list<array{block: string, text: string, page: int}>  $sequence
     * @return array<string, array{heading: int, content: int}>
     */
    private function headingPages(array $sequence): array
    {
        $headings = [];

        foreach ($sequence as $index => $entry) {
            if ($entry['block'] === 'h2' && isset($sequence[$index + 1]) && $sequence[$index + 1]['block'] !== 'h2') {
                $headings[$entry['text']] = ['heading' => $entry['page'], 'content' => $sequence[$index + 1]['page']];
            }
        }

        return $headings;
    }
}
