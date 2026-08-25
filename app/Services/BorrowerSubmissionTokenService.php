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

    /**
     * Issues a token, persisting only its SHA-256.
     *
     * The plaintext is returned once on the model's `plainTextToken` property and
     * never stored, mirroring how Sanctum handles personal access tokens. Storing
     * the raw value meant any read of the database — a backup artifact, the
     * nightly `db:backup`, an injection elsewhere — handed over live tokens for
     * every borrower created in the preceding 15 minutes.
     *
     * The value itself is unguessable either way: Str::random uses random_bytes,
     * so 32 chars over a 62-char alphabet is ~190 bits. This is about what a
     * database read is worth, not about brute force.
     */
    public function issue(Borrower $borrower, ?string $ipAddress = null): BorrowerSubmissionToken
    {
        $plainText = 'stk_'.Str::random(32);

        $record = BorrowerSubmissionToken::create([
            'borrower_id' => $borrower->id,
            'token' => hash('sha256', $plainText),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'ip_address' => $ipAddress,
        ]);

        $record->plainTextToken = $plainText;

        return $record;
    }

    /**
     * Returns the matching token if it is bound to the given borrower id and
     * has not expired; returns null otherwise.
     */
    public function verify(string $token, int $borrowerId): ?BorrowerSubmissionToken
    {
        // Look up by hash — the column holds the SHA-256, never the plaintext.
        // Scoped to borrower_id as well as the token so a token issued for one
        // applicant cannot be used against another.
        $record = BorrowerSubmissionToken::where('token', hash('sha256', $token))
            ->where('borrower_id', $borrowerId)
            ->first();

        if (! $record || $record->isExpired()) {
            return null;
        }

        return $record;
    }
}
