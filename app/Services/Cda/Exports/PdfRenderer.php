<?php

namespace App\Services\Cda\Exports;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * A CDA report as a PDF (CDA design §9): the Blade view
 * `resources/views/cda/report.blade.php` (auto-escaped) through dompdf, on A4
 * in the template's orientation.
 *
 * dompdf runs locked down: no remote fetches, no embedded PHP, no
 * JavaScript, and its file access is chrooted to the view folder, so a value
 * in a report can never make it read a file or call out. DejaVu Sans, bundled
 * with dompdf, renders the ₱ sign. "Page X of Y" is drawn on every page after
 * layout; the view's fixed footer carries the rest.
 */
final class PdfRenderer
{
    public const FONT = 'DejaVu Sans';

    public function html(ExportContent $content): string
    {
        return view('cda.report', ['content' => $content])->render();
    }

    public function render(ExportContent $content): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont(self::FONT);
        $options->setChroot([resource_path('views/cda')]);
        $options->setTempDir(sys_get_temp_dir());
        $options->setFontCache($this->fontCache());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($content), 'UTF-8');
        $dompdf->setPaper('A4', $content->orientation);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont(self::FONT);
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.32, 0.38, 0.43]);

        return (string) $dompdf->output();
    }

    /**
     * Where dompdf keeps its parsed font metrics: the framework cache, which
     * php-fpm can write (vendor/ may not be). dompdf only writes there when
     * the folder is writable, and otherwise re-reads the bundled metrics.
     */
    private function fontCache(): string
    {
        $path = storage_path('framework/cache/dompdf');

        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }
}
