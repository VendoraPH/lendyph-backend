<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time password sent while redeeming a code, used only when
 * `portal_settings.otp_required` is on (portal design §1). Hashed; 5 minutes;
 * 5 tries. Bound to the code and to the step token it was sent for
 * (`step_hash`), so a passed OTP never carries over to a later step.
 */
class PortalOtpChallenge extends Model
{
    public const UPDATED_AT = null;

    public const MAX_ATTEMPTS = 5;

    public const MINUTES = 5;

    protected $fillable = [
        'portal_user_id',
        'portal_code_id',
        'purpose',
        'code_hash',
        'step_hash',
        'masked_recipient',
        'expires_at',
        'attempts',
        'verified_at',
    ];

    protected $hidden = ['code_hash', 'step_hash'];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'portal_code_id' => 'integer',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
