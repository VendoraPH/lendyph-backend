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
 * A CDA PDF keeps each section heading with its content, checked on a real
 * CAPR with 40 officers laid out by dompdf with the renderer's view and
 * paper:
 *
 * - a table section's heading and column headers start every page the table
 *   runs to, and each of those pages holds some of its rows, so they are
 *   never left alone at the foot of a page;
 * - a heading that isn't followed by a table is on the page where its
 *   content starts.
 *
 * As laid out, the "Officers" heading falls at the foot of page 1, where it
 * and the column headers used to be left with every row on page 2. The
 * layout is also pushed down so the table starts part-way down a page and
 * runs on to the next.
 */
class PdfHeadingsKeptWithContentTest extends TestCase
{
    use SetupLendyPH;

    /** A4 height, in pt. */
    private const PAGE_HEIGHT_PT = 841.89;

    /** The CAPR officers table's column headers, in order. */
    private const OFFICER_COLUMNS = ['Name', 'Position', 'Sex', 'Term start', 'Term end'];

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

    public function test_the_officers_heading_and_column_headers_are_never_left_at_the_foot_of_page_one(): void
    {
        $layout = $this->layout($this->caprHtml());
        $pages = $this->officerPages($layout['sequence']);

        $this->assertArrayHasKey(2, $pages, 'The Officers table is on page 2.');
        $this->assertNotContains($this->lastOnPage($layout['sequence'], 1), ['Officers', ...self::OFFICER_COLUMNS], 'Page 1 doesn\'t end with the Officers heading or its column headers.');

        foreach ($pages as $page => $entries) {
            $this->assertOfficerPageStartsWithItsHeadings($page, $entries);
        }
    }

    public function test_a_table_running_over_two_pages_repeats_its_heading_and_column_headers(): void
    {
        $html = $this->caprHtml();
        $plain = $this->layout($html);

        // Push page 1's content about half a page further, so the table starts part-way down a page.
        $pushed = $this->layout($this->pushed($html, $this->footOfPage() - $plain['bottoms'][1] + 400));
        $pages = $this->officerPages($pushed['sequence']);

        $this->assertGreaterThan(1, count($pages), 'The 40 officers run over more than one page.');

        foreach ($pages as $page => $entries) {
            $this->assertOfficerPageStartsWithItsHeadings($page, $entries);
        }
    }

    public function test_a_heading_not_followed_by_a_table_is_on_the_page_where_its_content_starts(): void
    {
        $html = $this->caprHtml();
        $plain = $this->layout($html);
        $room = $this->footOfPage() - $plain['bottoms'][1];

        foreach ([0, $room + 200, $room + 400] as $filler) {
            $headings = $this->headingPages($this->layout($this->pushed($html, $filler))['sequence']);
            $this->assertNotSame([], $headings);

            foreach ($headings as $title => $pages) {
                $this->assertSame($pages['heading'], $pages['content'], "Pushed {$filler}pt: the \"{$title}\" heading is on page {$pages['heading']}, its content starts on page {$pages['content']}.");
            }
        }
    }

    private function caprHtml(): string
    {
        CdaOfficer::factory()->count(40)->create();
        $period = app(PeriodOpener::class)->ensure(2026);
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        app(ReportEngine::class)->refresh($capr);
        $content = app(ExportContentBuilder::class)->build($capr->fresh(), $this->admin);
        $this->assertSame('portrait', $content->orientation);

        return app(PdfRenderer::class)->html($content);
    }

    /** Where a page's content must end: the paper's height less the bottom margin, in pt. */
    private function footOfPage(): float
    {
        return self::PAGE_HEIGHT_PT - PdfRenderer::MARGIN_BOTTOM_MM * 72 / 25.4;
    }

    /** The HTML with an empty block of the given height at the top of the body. */
    private function pushed(string $html, float $filler): string
    {
        $this->assertGreaterThanOrEqual(0, $filler);

        return $filler == 0
            ? $html
            : str_replace('<body>', '<body><div style="height: '.round($filler, 2).'pt"></div>', $html);
    }

    /**
     * The Officers table's text, by page: from its first heading to the next
     * section's heading.
     *
     * @param  list<array{block: string, role: string, text: string, page: int}>  $sequence
     * @return array<int, list<array{block: string, role: string, text: string, page: int}>>
     */
    private function officerPages(array $sequence): array
    {
        $pages = [];
        $inside = false;

        foreach ($sequence as $entry) {
            if ($entry['role'] === 'heading') {
                if ($inside && $entry['text'] !== 'Officers') {
                    break;
                }

                $inside = $inside || $entry['text'] === 'Officers';
            }

            if ($inside) {
                $pages[$entry['page']][] = $entry;
            }
        }

        $this->assertNotSame([], $pages, 'The CAPR has an Officers table.');

        return $pages;
    }

    /** @param  list<array{block: string, role: string, text: string, page: int}>  $entries */
    private function assertOfficerPageStartsWithItsHeadings(int $page, array $entries): void
    {
        $this->assertSame(
            [['heading', 'Officers'], ...array_map(fn (string $label) => ['header', $label], self::OFFICER_COLUMNS)],
            array_map(fn (array $entry) => [$entry['role'], $entry['text']], array_slice($entries, 0, 1 + count(self::OFFICER_COLUMNS))),
            "Page {$page} starts the Officers table with its heading and column headers.",
        );
        $this->assertContains('cell', array_column($entries, 'role'), "Page {$page} holds at least one officer row.");
    }

    /** @param  list<array{block: string, role: string, text: string, page: int}>  $sequence */
    private function lastOnPage(array $sequence, int $page): ?string
    {
        $onPage = array_values(array_filter($sequence, fn (array $entry) => $entry['page'] === $page));

        return $onPage === [] ? null : $onPage[count($onPage) - 1]['text'];
    }

    /**
     * Lays the HTML out as PdfRenderer does and gives the text drawn, in
     * order, with its page, its role and the top-level block it belongs to
     * (fixed blocks, the footer and the DRAFT mark, left out), and where the
     * content drawn on each page ends.
     *
     * @return array{sequence: list<array{block: string, role: string, text: string, page: int}>, bottoms: array<int, float>}
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
        $bottoms = [];
        $dompdf = new Dompdf($options);
        $dompdf->setCallbacks([['event' => 'begin_frame', 'f' => function (Frame $frame, Canvas $canvas) use (&$sequence, &$bottoms): void {
            $node = $frame->get_node();
            $page = $canvas->get_page_number();
            $top = self::topBlock($node);

            if ($top === null || preg_match('/\b(footer|watermark)\b/', $top->getAttribute('class')) === 1) {
                return;
            }

            [, $y] = $frame->get_position();
            $bottoms[$page] = max($bottoms[$page] ?? 0.0, (float) $y + (float) $frame->get_margin_height());

            if ($node->nodeName !== '#text' || trim($node->nodeValue) === '') {
                return;
            }

            $sequence[] = ['block' => $top->nodeName, 'role' => self::role($node->parentNode), 'text' => trim($node->nodeValue), 'page' => $page];
        }]]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return ['sequence' => $sequence, 'bottoms' => $bottoms];
    }

    /** A section heading: an h2, or the heading row a table section carries in its header. */
    private static function isHeading(?DOMNode $node): bool
    {
        return $node instanceof DOMElement
            && ($node->nodeName === 'h2' || preg_match('/\bsection-title\b/', $node->getAttribute('class')) === 1);
    }

    /** What a text is: a section heading, a column header, a table cell, or other text. */
    private static function role(?DOMNode $element): string
    {
        return match (true) {
            self::isHeading($element) => 'heading',
            $element?->nodeName === 'th' => 'header',
            $element?->nodeName === 'td' => 'cell',
            default => 'other',
        };
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
     * For each heading drawn as an h2 (a section that isn't a table with
     * rows), its page and the page of the first text drawn after it.
     *
     * @param  list<array{block: string, role: string, text: string, page: int}>  $sequence
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
