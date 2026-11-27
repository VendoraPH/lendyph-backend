<?php

namespace App\Services\Portal\Auth;

use Illuminate\Http\JsonResponse;

/**
 * An answer a portal auth service hands back to its controller: the status
 * and the exact body. The generic refusals are fixed constants, so a refusal
 * for an unknown member and for a known one are byte-identical.
 */
final readonly class PortalAnswer
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(public int $status, public array $body) {}

    /** @param  array<string, mixed>  $data */
    public static function ok(array $data, int $status = 200): self
    {
        return new self($status, ['success' => true, 'data' => $data]);
    }

    public static function refused(int $status, string $message, ?string $code = null): self
    {
        return new self($status, array_filter(['success' => false, 'message' => $message, 'code' => $code], fn ($value) => $value !== null));
    }

    public function toResponse(): JsonResponse
    {
        return response()->json($this->body, $this->status);
    }
}
