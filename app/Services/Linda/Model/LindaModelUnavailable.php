<?php

namespace App\Services\Linda\Model;

use InvalidArgumentException;
use RuntimeException;

/**
 * A model was asked and couldn't answer. It carries only a fixed reason code,
 * never the provider's message text and never the exception it came from, so
 * nothing a provider said (or the question it echoed) can reach a log or a
 * response through it.
 *
 * A call that was answered but couldn't be used (`max_tokens`, `bad_output`)
 * also carries the usage it was billed, so its cost still counts against the
 * monthly cap.
 */
final class LindaModelUnavailable extends RuntimeException
{
    /** Every reason code, in the order the plan lists them. */
    public const REASONS = [
        'rate_limited', 'overloaded', 'timeout', 'connection', 'auth',
        'bad_request', 'bad_output', 'max_tokens', 'no_key', 'no_prices',
        'internal', 'redirect', 'custom_headers',
    ];

    public function __construct(public readonly string $reason, public readonly ?LindaUsage $usage = null)
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown Linda model reason code.');
        }

        parent::__construct("The Linda model is unavailable ({$reason}).");
    }
}
