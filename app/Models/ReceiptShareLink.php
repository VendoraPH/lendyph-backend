<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A link that opens one payment receipt, masked, without signing in
 * (finish run round 2, decision 6). Only the SHA-256 of its token is stored;
 * the token is shown once, when the link is made.
 *
 * Revoked wins over expired: a link revoked before its 30 days ran out reads
 * revoked for good.
 *
 * @property int $id
 * @property int $repayment_id
 * @property string $token_hash
 * @property int $created_by
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 */
class ReceiptShareLink extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'repayment_id',
        'token_hash',
        'created_by',
        'expires_at',
        'revoked_at',
        'revoked_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** The stored form of a token: its SHA-256, in hex. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(Repayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Links that open their receipt now: not revoked and not yet expired.
     * Compared with the app's clock (Manila), as `expires_at` is stored, never
     * the database server's NOW() (UTC in production).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->expires_at->lte(now()) => self::STATUS_EXPIRED,
            default => self::STATUS_ACTIVE,
        };
    }
}
