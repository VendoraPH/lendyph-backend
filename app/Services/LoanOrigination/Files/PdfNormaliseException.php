<?php

namespace App\Services\LoanOrigination\Files;

use RuntimeException;

/**
 * qpdf couldn't rewrite a PDF, or its copy grew past the limit. The store
 * turns it into a refusal in words; the PDF is never kept.
 */
final class PdfNormaliseException extends RuntimeException
{
    public bool $tooLarge = false;

    public static function tooLarge(): self
    {
        $exception = new self('The PDF opens up past the size that can be checked.');
        $exception->tooLarge = true;

        return $exception;
    }
}
