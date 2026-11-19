<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An applicant's session on a public link (origination decision 8), sent back
 * in the `X-Application-Session` header. Kept only as the SHA-256 of its
 * token, tied to one link and one application. `apply` and `resume` sessions
 * work on the draft; a `track` session only reads the status (and replaces
 * documents staff asked for again).
 */
class OriginationSession extends Model
{
    public const UPDATED_AT = null;

    public const INTAKE_PURPOSES = ['apply', 'resume'];

    protected $fillable = [
        'application_id', 'link_id', 'purpose', 'token_hash', 'ip_address', 'user_agent', 'last_used_at', 'expires_at', 'ended_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'link_id' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function isTracking(): bool
    {
        return $this->purpose === 'track';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OriginationApplication::class, 'application_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(OriginationLink::class, 'link_id');
    }
}
