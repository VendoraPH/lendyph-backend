<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-issued activation or reset code (portal design §1). Stored as a hash
 * only. `live_key` is the portal user's id while the code is open, so a member
 * has at most one open code; issuing another supersedes it. `step_hash` is the
 * SHA-256 of the 15-minute step token handed out between verify and complete.
 */
class PortalCode extends Model
{
    public const UPDATED_AT = null;

    public const KIND_ACTIVATION = 'activation';

    public const KIND_RESET = 'reset';

    public const STATUS_OPEN = 'open';

    public const STATUS_USED = 'used';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_BURNED = 'burned';

    public const STATUS_EXPIRED = 'expired';

    /** Wrong tries that burn a code. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'portal_user_id',
        'kind',
        'code_hash',
        'expires_at',
        'attempts',
        'status',
        'live_key',
        'step_hash',
        'step_expires_at',
        'identity_checked_as',
        'issued_by',
        'used_at',
    ];

    protected $hidden = ['code_hash', 'step_hash'];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'attempts' => 'integer',
            'live_key' => 'integer',
            'issued_by' => 'integer',
            'expires_at' => 'datetime',
            'step_expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** The status a reader should see: an open code past its expiry reads expired. */
    public function effectiveStatus(): string
    {
        return $this->status === self::STATUS_OPEN && $this->expires_at->isPast() ? self::STATUS_EXPIRED : $this->status;
    }
}
