<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of AMLA's own log (AMLA design §3.6, §4.14): who did or viewed
 * what in AMLA, and every refused attempt. Append-only and kept forever, and
 * never written to the main audit trail, so holders of `audit_logs:view`
 * aren't tipped off. Written only through App\Services\Amla\Foundation\AmlaLog;
 * read with `amla:audit` and head-office access.
 *
 * @property int $id
 * @property string $action
 * @property string $actor_type user|system
 * @property int|null $user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $details
 */
class AmlaLogEntry extends Model
{
    use AmlaKeptForever;

    /** Kept as written, so no `updated_at`; `created_at` comes from PHP's clock, in Manila time, as AuditLog's does. */
    public const UPDATED_AT = null;

    protected $fillable = ['action', 'actor_type', 'user_id', 'subject_type', 'subject_id', 'details', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'subject_id' => 'integer',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
