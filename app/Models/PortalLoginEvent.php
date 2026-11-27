<?php

namespace App\Models;

use Database\Factories\PortalLoginEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in attempt (portal design §2): the member's login history and the
 * device list. `login_hash` is a keyed hash of the normalised member number,
 * so a failed attempt on an unknown number is recorded without storing what
 * was typed. Failed sign-ins go here, not to the audit log. Pruned after 365
 * days (`portal:prune-login-events`).
 */
class PortalLoginEvent extends Model
{
    /** @use HasFactory<PortalLoginEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_REFUSED_LOCKED = 'refused_locked';

    public const OUTCOME_LOCKED_OUT = 'locked_out';

    public const OUTCOME_DISABLED = 'disabled';

    public const OUTCOME_PORTAL_OFF = 'portal_off';

    public const OUTCOMES = [
        self::OUTCOME_SUCCESS, self::OUTCOME_FAILED, self::OUTCOME_REFUSED_LOCKED,
        self::OUTCOME_LOCKED_OUT, self::OUTCOME_DISABLED, self::OUTCOME_PORTAL_OFF,
    ];

    public const OUTCOME_LABELS = [
        self::OUTCOME_SUCCESS => 'Signed in',
        self::OUTCOME_FAILED => 'Wrong password',
        self::OUTCOME_REFUSED_LOCKED => 'Refused while locked',
        self::OUTCOME_LOCKED_OUT => 'Locked after too many tries',
        self::OUTCOME_DISABLED => 'Refused: access turned off',
        self::OUTCOME_PORTAL_OFF => 'Refused: portal turned off',
    ];

    public const RETENTION_DAYS = 365;

    protected $fillable = [
        'portal_user_id',
        'login_hash',
        'outcome',
        'token_id',
        'device',
        'user_agent',
        'ip_address',
        'reported_at',
    ];

    protected $hidden = ['login_hash'];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'token_id' => 'integer',
            'reported_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }
}
