<?php

namespace App\Services\Linda\Model;

use InvalidArgumentException;
use RuntimeException;

/**
 * A model was asked and couldn't answer. It carries only a fixed reason code,
 * never the provider's message text and never the exception it came from, so
 * nothing a provider said (or the question it echoed) can reach a log or a
 * response through it.
 */
final class LindaModelUnavailable extends RuntimeException
{
    /** Every reason code, in the order the plan lists them. */
    public const REASONS = [
        'rate_limited', 'overloaded', 'timeout', 'connection', 'auth',
        'bad_request', 'bad_output', 'max_tokens', 'no_key', 'no_prices',
    ];

    public function __construct(public readonly string $reason)
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown Linda model reason code.');
        }

        parent::__construct("The Linda model is unavailable ({$reason}).");
    }
}
