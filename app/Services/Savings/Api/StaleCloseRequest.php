<?php

namespace App\Services\Savings\Api;

use RuntimeException;

/**
 * A close request whose payout no longer matches what was approved (the
 * balance or the interest moved since it was requested). ApprovalDesk rolls
 * the approval back, rejects the request in a write of its own, and answers
 * 409, so the account is free for a new request at once.
 *
 * @internal
 */
final class StaleCloseRequest extends RuntimeException
{
    public function __construct(public readonly int $approvalId)
    {
        parent::__construct(AccountCloser::STALE_REASON);
    }
}
