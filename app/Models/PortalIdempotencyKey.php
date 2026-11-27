<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A member write's Idempotency-Key and the answer it got (portal design §2),
 * the same shape as `savings_idempotency_keys`, keyed on the portal user.
 */
class PortalIdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['portal_user_id', 'key', 'request_hash', 'response_status', 'response_body'];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }
}
