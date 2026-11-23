<?php

namespace App\Services\LoanOrigination\Files;

/**
 * LOAN_ORIGINATION_SCANNER holds a value that is neither `none` nor
 * `clamav`. That is a misconfiguration, never "no scanner": every upload is
 * refused (503) until the setting is fixed.
 */
final class MisconfiguredScanner implements FileScanner
{
    public function name(): string
    {
        return 'misconfigured';
    }

    public function scan(string $path): ScanResult
    {
        return ScanResult::Failed;
    }
}
