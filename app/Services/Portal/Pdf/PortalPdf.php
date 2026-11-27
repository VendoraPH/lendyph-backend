<?php

namespace App\Services\Portal\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * A portal document as a PDF (portal design §1, §3): the Blade view
 * `resources/views/portal/document.blade.php` (auto-escaped) through dompdf
 * on A4, with the CDA renderer's locked-down options: no remote fetches, no
 * embedded PHP, no JavaScript, and file access chrooted to the portal view
 * folder. DejaVu Sans, bundled with dompdf, renders the ₱ sign.
 */
class PortalPdf
{
    public const FONT = 'DejaVu Sans';

    private const PT_PER_MM = 72 / 25.4;

    public function html(PortalDocument $document): string
    {
        return view('portal.document', ['document' => $document])->render();
    }

    public function render(PortalDocument $document): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont(self::FONT);
        $options->setChroot([resource_path('views/portal')]);
        $options->setTempDir(sys_get_temp_dir());
        $options->setFontCache($this->fontCache());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($document), 'UTF-8');
        $dompdf->setPaper('A4', $document->orientation);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont(self::FONT);
        $canvas->page_text(
            $canvas->get_width() - 40 * self::PT_PER_MM,
            $canvas->get_height() - 10 * self::PT_PER_MM,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            7,
            [0.32, 0.38, 0.43],
        );

        return (string) $dompdf->output();
    }

    /** dompdf's parsed font metrics, in the framework cache (writable by php-fpm). */
    private function fontCache(): string
    {
        $path = storage_path('framework/cache/dompdf');

        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }
}
