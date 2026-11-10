<?php

namespace App\Services\Savings;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A savings write that answered with an error response: IdempotentWrite
 * throws it to roll the key row back, then answers that same response.
 *
 * @internal
 */
final class RefusedWrite extends RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('The savings write was refused.');
    }
}
