<?php

namespace App\Services\Cic\Submissions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A CIC file step refused, in words: `{success: false, message, code?, data?}`
 * with its HTTP status (422 for a rule, 403 for the file's own generator, 409
 * when the rebuilt file differs). Nothing was written.
 *
 * `data` carries counts and issue summaries only, never a member's values.
 */
final class CicSubmissionRefusal extends RuntimeException
{
    /** @param  array<string, mixed>|null  $data */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly ?string $refusalCode = null,
        private readonly ?array $data = null,
    ) {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>|null  $data */
    public static function unprocessable(string $message, ?string $code = null, ?array $data = null): self
    {
        return new self($message, 422, $code, $data);
    }

    public static function conflict(string $message, ?string $code = null): self
    {
        return new self($message, 409, $code);
    }

    public static function forbidden(string $message, ?string $code = null): self
    {
        return new self($message, 403, $code);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function refusalCode(): ?string
    {
        return $this->refusalCode;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->refusalCode,
            'data' => $this->data,
        ], fn ($value) => $value !== null), $this->status);
    }
}
