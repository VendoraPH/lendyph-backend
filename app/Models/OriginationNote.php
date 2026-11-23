<?php

namespace App\Models;

use App\Traits\OriginationAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff note on an application: internal, never shown to the applicant,
 * and permanent (append-only), like savings account notes.
 */
class OriginationNote extends Model
{
    use OriginationAppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['application_id', 'user_id', 'body'];

    protected function casts(): array
    {
        return ['application_id' => 'integer', 'user_id' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OriginationApplication::class, 'application_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
