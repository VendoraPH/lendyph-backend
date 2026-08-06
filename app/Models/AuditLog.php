<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /**
     * Audit rows are append-only, so the table has no `updated_at` column.
     *
     * Timestamps stay ENABLED (rather than `$timestamps = false`) so Eloquent
     * stamps `created_at` from PHP's clock, in the application timezone, exactly
     * like every other table in this schema. The column also carries a
     * `useCurrent()` default from its migration, but that default must never be
     * what actually fires: it resolves to MySQL's `CURRENT_TIMESTAMP`, and the
     * database server runs in UTC in production while the app runs in
     * Asia/Manila. Letting the database stamp the audit trail put every entry —
     * including `restructure_closed`, the justification record for written-off
     * debt — eight hours behind the event it describes.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
