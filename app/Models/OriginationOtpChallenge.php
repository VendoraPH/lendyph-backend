<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code an applicant asked for on a public link (origination
 * decision 6), written only by ApplicantOtp. Six digits, valid 5 minutes, 5
 * tries, kept as a hash; the mobile only as its keyed hash. Asking again
 * closes the earlier open code for the same link, purpose and mobile.
 */
class OriginationOtpChallenge extends Model
{
    public const UPDATED_AT = null;

    public const PURPOSES = ['apply', 'resume', 'track'];

    protected $fillable = [
        'link_id', 'purpose', 'channel', 'mobile_hash', 'reference_number', 'code_hash', 'test_mode', 'delivered',
        'attempts', 'expires_at', 'verified_at', 'closed_at', 'ip_address',
    ];

    protected $hidden = ['code_hash', 'mobile_hash'];

    protected function casts(): array
    {
        return [
            'link_id' => 'integer',
            'test_mode' => 'boolean',
            'delivered' => 'boolean',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->verified_at === null && $this->closed_at === null && $this->expires_at->isFuture();
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(OriginationLink::class, 'link_id');
    }
}
