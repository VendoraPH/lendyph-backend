<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One side-menu module an administrator switched in Settings → Menu
 * Visibility (finish run round 6): hidden, or turned back on, with who did it
 * and when. A module with no row is shown. Written only by
 * App\Services\MenuVisibility\MenuVisibility, which audits each save as one
 * `menu_visibility_changed` row.
 *
 * @property int $id
 * @property string $module_key one of App\Services\MenuVisibility\MenuModules::keys()
 * @property bool $hidden
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MenuVisibilitySwitch extends Model
{
    protected $fillable = ['module_key', 'hidden', 'updated_by'];

    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
