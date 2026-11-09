<?php

namespace App\Models;

use App\Support\PhilippineMobile;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's reminder switches (both on unless a row says otherwise), and the
 * contact values staff verified or a send proved bad.
 *
 * Verified and invalid flags hold the value they were about (a mobile number
 * in its 639XXXXXXXXX form, an email in lower case), so a flag stops applying
 * the moment the member's contact differs from it: a corrected number is
 * neither "verified" nor "invalid" until something says so again.
 */
class BorrowerNotificationPreference extends Model
{
    use Auditable;

    public const CHANNELS = ['sms', 'email', 'both'];

    protected $fillable = [
        'borrower_id',
        'sms_enabled',
        'email_enabled',
        'preferred_channel',
        'language',
        'mobile_verified_value',
        'mobile_verified_at',
        'mobile_verified_by',
        'mobile_invalid_value',
        'mobile_invalid_reason',
        'mobile_invalid_at',
        'mobile_invalid_by',
        'email_verified_value',
        'email_verified_at',
        'email_verified_by',
        'email_invalid_value',
        'email_invalid_reason',
        'email_invalid_at',
        'email_invalid_by',
    ];

    protected $attributes = [
        'sms_enabled' => true,
        'email_enabled' => true,
        'preferred_channel' => 'both',
        'language' => 'en',
    ];

    protected function casts(): array
    {
        return [
            'sms_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'mobile_verified_at' => 'datetime',
            'mobile_invalid_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'email_invalid_at' => 'datetime',
        ];
    }

    public function channelEnabled(string $channel): bool
    {
        return $channel === 'email' ? (bool) $this->email_enabled : (bool) $this->sms_enabled;
    }

    /**
     * Whether the mobile number (any format) is the one a send proved bad.
     */
    public function mobileFlaggedFor(?string $number): bool
    {
        $normalised = PhilippineMobile::normalise($number);

        return $normalised !== null && $this->mobile_invalid_value === $normalised;
    }

    public function emailFlaggedFor(?string $email): bool
    {
        $email = self::normaliseEmail($email);

        return $email !== null && $this->email_invalid_value === $email;
    }

    public function mobileVerifiedFor(?string $number): bool
    {
        $normalised = PhilippineMobile::normalise($number);

        return $normalised !== null && $this->mobile_verified_value === $normalised;
    }

    public function emailVerifiedFor(?string $email): bool
    {
        $email = self::normaliseEmail($email);

        return $email !== null && $this->email_verified_value === $email;
    }

    public function contactFlaggedFor(string $channel, ?string $value): bool
    {
        return $channel === 'email' ? $this->emailFlaggedFor($value) : $this->mobileFlaggedFor($value);
    }

    public static function normaliseEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }
}
