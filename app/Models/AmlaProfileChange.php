<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to an AMLA profile (the KYC audit history, task §10): who, when,
 * and each field's old and new value. Encrypted at rest, since it holds KYC
 * answers; kept as written forever.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $user_id
 * @property array<string, array{from: mixed, to: mixed}> $changes
 */
class AmlaProfileChange extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['profile_id', 'user_id', 'changes'];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'user_id' => 'integer',
            'changes' => 'encrypted:array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
