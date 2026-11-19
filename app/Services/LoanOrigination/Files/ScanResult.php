<?php

namespace App\Services\LoanOrigination\Files;

/**
 * What a scanner said about a file. `NotScanned` comes only from NoScanner;
 * `Failed` (the scanner couldn't be reached or answered an error) is never
 * read as clean.
 */
enum ScanResult: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case NotScanned = 'not_scanned';
    case Failed = 'failed';
}
