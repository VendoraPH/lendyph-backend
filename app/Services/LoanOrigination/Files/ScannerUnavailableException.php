<?php

namespace App\Services\LoanOrigination\Files;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A scanner is set up but couldn't check the file: the upload is refused
 * (503), never kept unchecked.
 */
final class ScannerUnavailableException extends RuntimeException
{
    public const MESSAGE = 'Files can\'t be checked for viruses right now. Try again in a few minutes.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => self::MESSAGE], 503);
    }
}
