<?php

namespace App\Services\Cda\Exports;

use ZipArchive;

/**
 * What the server can export with (CDA design §9). Excel needs PHP's zip
 * extension (ZipArchive); a server without it answers 503 in words, and PDF
 * exports still work. Bound in the container so a test can stand in for a
 * server without it.
 */
class ExportSupport
{
    public const ZIP_MISSING = "Excel exports need the PHP zip extension, which this server doesn't have. Ask your administrator to install it; PDF exports still work.";

    public function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }
}
