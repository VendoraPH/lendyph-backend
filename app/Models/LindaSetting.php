<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Settings → Linda: one company-wide row holding the model Linda uses.
 *
 * Not Auditable: App\Services\Linda\LindaSettings writes the one audit row a
 * change needs, in words ("Linda model changed from … to …"), and is the only
 * writer. Read the model through LindaSettings::model(), which falls back to
 * `linda.model` when the stored one has left the price table.
 *
 * @property int $id
 * @property string $model
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
class LindaSetting extends Model
{
    protected $fillable = ['model', 'updated_by'];

    protected function casts(): array
    {
        return ['updated_by' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
