<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A note staff add to a savings account's timeline (gap round S4). Never
 * edited or deleted; each one is audited on its account when it is added.
 *
 * @property int $id
 * @property int $account_id
 * @property string $note
 * @property int $created_by
 * @property Carbon|null $created_at
 */
class SavingsAccountNote extends Model
{
    public const MAX_LENGTH = 1000;

    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
