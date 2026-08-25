<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerSubmissionToken extends Model
{
    /**
     * The plaintext token, present only on the instance returned by
     * BorrowerSubmissionTokenService::issue(). The `token` column stores its
     * SHA-256; this is never persisted and is never re-derivable afterwards.
     *
     * Declared as a real property on purpose. An undeclared property would fall
     * through Eloquent's __set and be treated as a model *attribute*, which
     * would put the plaintext into toArray() and into the next save.
     */
    public ?string $plainTextToken = null;

    protected $fillable = [
        'borrower_id',
        'token',
        'expires_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
