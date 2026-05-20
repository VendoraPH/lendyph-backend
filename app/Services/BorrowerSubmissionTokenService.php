<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\BorrowerSubmissionToken;
use Illuminate\Support\Str;

class BorrowerSubmissionTokenService
{
    /**
     * Public registration submission tokens live for 15 minutes. The borrower
     * row is created first (status=pending), then this token is issued so the
     * unauthenticated client can attach a photo + valid IDs against the same id.
     */
    public const TTL_MINUTES = 15;

    public function issue(Borrower $borrower, ?string $ipAddress = null): BorrowerSubmissionToken
    {
        return BorrowerSubmissionToken::create([
            'borrower_id' => $borrower->id,
            'token' => 'stk_'.Str::random(32),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Returns the matching token if it is bound to the given borrower id and
     * has not expired; returns null otherwise.
     */
    public function verify(string $token, int $borrowerId): ?BorrowerSubmissionToken
    {
        $record = BorrowerSubmissionToken::where('token', $token)
            ->where('borrower_id', $borrowerId)
            ->first();

        if (! $record || $record->isExpired()) {
            return null;
        }

        return $record;
    }
}
