<?php

namespace App\Services\LoanOrigination\Files;

/**
 * The scanner seam for uploaded files (origination decision 4). Bound in
 * LoanOriginationServiceProvider from `loan_origination.scanner.driver`:
 * NoScanner (`none`, the default) or ClamAvScanner (`clamav`).
 */
interface FileScanner
{
    /** A short name kept with each scanned file. */
    public function name(): string;

    /** Scan the file at an absolute local path. Never throws: a fault is Failed. */
    public function scan(string $path): ScanResult;
}
