<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step on a portal request's timeline (portal design §2). Every message is
 * member-visible: v1 has no internal staff notes.
 */
class PortalRequestEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_MEMBER = 'member';

    public const ACTOR_STAFF = 'staff';

    public const ACTOR_SYSTEM = 'system';

    protected $fillable = ['request_id', 'from_status', 'to_status', 'actor_type', 'user_id', 'message'];

    protected function casts(): array
    {
        return [
            'request_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PortalRequest::class, 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
