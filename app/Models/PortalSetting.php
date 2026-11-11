<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The portal's company-wide settings (portal design §2): one row, seeded with
 * the portal switched off. Audited (old and new values) through Auditable.
 */
class PortalSetting extends Model
{
    use Auditable;

    /** The sign-in stand-in hash is never shown or audited. */
    protected $hidden = ['auth_dummy_hash'];

    /** @var list<string> */
    protected array $auditRedacted = ['auth_dummy_hash'];

    /** Feature keys, each on by default; a feature that is off answers 403 on its routes. */
    public const FEATURES = [
        'loans', 'share_capital', 'savings', 'time_deposits', 'statements', 'documents',
        'announcements', 'account_requests', 'profile_requests', 'withdrawal_requests',
    ];

    public const DASHBOARD_CARDS = [
        'share_capital', 'savings', 'time_deposits', 'loans', 'next_payment', 'announcements', 'recent_activity',
    ];

    public const WITHDRAWAL_METHODS = ['cash_pickup', 'bank_transfer', 'gcash'];

    protected $fillable = [
        'enabled',
        'portal_name',
        'welcome_message',
        'support_contact',
        'payment_instructions',
        'session_minutes',
        'idle_minutes',
        'max_login_attempts',
        'lockout_minutes',
        'hard_lock_after',
        'password_min_length',
        'activation_code_hours',
        'reset_code_hours',
        'otp_required',
        'features',
        'dashboard_cards',
        'withdrawal_methods',
        'withdrawal_hold_days',
        'request_expiry_days',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'otp_required' => 'boolean',
            'session_minutes' => 'integer',
            'idle_minutes' => 'integer',
            'max_login_attempts' => 'integer',
            'lockout_minutes' => 'integer',
            'hard_lock_after' => 'integer',
            'password_min_length' => 'integer',
            'activation_code_hours' => 'integer',
            'reset_code_hours' => 'integer',
            'withdrawal_hold_days' => 'integer',
            'request_expiry_days' => 'integer',
            'features' => 'array',
            'dashboard_cards' => 'array',
            'withdrawal_methods' => 'array',
            'updated_by' => 'integer',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
