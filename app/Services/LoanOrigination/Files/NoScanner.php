<?php

namespace App\Services\LoanOrigination\Files;

/**
 * No virus scanner is set up: every file is kept and marked "Not scanned",
 * so staff can see it wasn't checked.
 */
final class NoScanner implements FileScanner
{
    public function name(): string
    {
        return 'none';
    }

    public function scan(string $path): ScanResult
    {
        return ScanResult::NotScanned;
    }
}
