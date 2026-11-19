<?php

namespace App\Services\LoanOrigination\Files;

use Illuminate\Validation\ValidationException;

/**
 * A file the store won't keep, with the reason in words: 422 on `file`.
 */
final class UploadRefusedException extends ValidationException
{
    public static function because(string $message): self
    {
        return self::withMessages(['file' => $message]);
    }
}
