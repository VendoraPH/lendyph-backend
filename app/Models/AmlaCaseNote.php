<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An investigation note on an AMLA case: encrypted, kept as written, never
 * shown outside AMLA (task §12: confidential notes never reach members).
 *
 * @property int $id
 * @property int $case_id
 * @property string $body
 * @property int $user_id
 */
class AmlaCaseNote extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['case_id', 'body', 'user_id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'case_id' => 'integer', 'user_id' => 'integer', 'created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
