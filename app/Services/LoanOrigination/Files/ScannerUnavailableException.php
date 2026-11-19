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

    public const MISCONFIGURED = 'Files can\'t be accepted right now: the server\'s virus scanner setting isn\'t recognised. Ask your administrator to check it.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }

    public static function misconfigured(): self
    {
        return new self(self::MISCONFIGURED);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage()], 503);
    }
}
