<?php

namespace App\Models;

use App\Traits\OriginationAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One consent an applicant gave or declined (origination decision 9): its
 * type, the notice version and text hash, when, and from which IP and user
 * agent. The privacy notice is acknowledged at the code check (`verify`)
 * and again at submit; optional consents (`marketing`) are their own rows and
 * never pre-ticked. Append-only.
 */
class OriginationConsent extends Model
{
    use OriginationAppendOnly;

    public const UPDATED_AT = null;

    public const TYPES = ['privacy_notice', 'terms', 'declaration', 'credit_authorization', 'marketing'];

    public const STAGES = ['verify', 'submit'];

    protected $fillable = ['application_id', 'type', 'notice_id', 'version', 'text_hash', 'accepted', 'stage', 'ip_address', 'user_agent', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'notice_id' => 'integer',
            'accepted' => 'boolean',
            'accepted_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OriginationApplication::class, 'application_id');
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(OriginationNotice::class, 'notice_id');
    }
}
