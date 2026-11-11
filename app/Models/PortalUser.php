<?php

namespace App\Models;

use Database\Factories\PortalUserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A member's portal sign-in (portal design §1, §2): credentials only, one row
 * per member, never deleted (disabled instead).
 *
 * Signed in through the `portal` Sanctum guard, never the staff one
 * (`guards.sanctum.provider = users` refuses its tokens on every staff route).
 * Never `HasRoles`: members hold no permissions, and `Gate::before` answers
 * nothing for them. Not `Auditable` either, since that would copy the password
 * hash into audit rows; the portal services write their own rows.
 */
#[Hidden(['password', 'remember_token'])]
class PortalUser extends Authenticatable
{
    /** @use HasFactory<PortalUserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_DISABLED = 'disabled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_INVITED, self::STATUS_ACTIVE, self::STATUS_LOCKED, self::STATUS_DISABLED];

    /** Reasons staff give when turning access off. */
    public const STAFF_DISABLE_REASONS = ['member_request', 'security_concern', 'duplicate_account', 'administrative'];

    /** Reasons the system records when membership ends. */
    public const REASON_MEMBERSHIP_ENDED = 'membership_ended';

    public const REASON_DECEASED = 'deceased';

    /** The one ability a portal token carries. */
    public const ABILITY = 'portal';

    protected $rememberTokenName = '';

    protected $fillable = [
        'borrower_id',
        'password',
        'status',
        'disabled_reason',
        'disabled_at',
        'disabled_by',
        'failed_attempts',
        'locked_until',
        'lockouts_cleared_at',
        'invited_by',
        'invited_at',
        'activated_at',
        'password_changed_at',
        'last_login_at',
        'terms_version',
        'terms_accepted_at',
        'ecomm_consent_at',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'borrower_id' => 'integer',
            'failed_attempts' => 'integer',
            'disabled_by' => 'integer',
            'invited_by' => 'integer',
            'disabled_at' => 'datetime',
            'locked_until' => 'datetime',
            'lockouts_cleared_at' => 'datetime',
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'ecomm_consent_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PortalCode::class);
    }

    public function loginEvents(): HasMany
    {
        return $this->hasMany(PortalLoginEvent::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function disabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    /** Whether a lockout window is running now. */
    public function isTemporarilyLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
