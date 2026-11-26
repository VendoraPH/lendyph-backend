<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An open administrator override for Settings → Menu Visibility (finish run
 * round 6): one per sign-in, bound to its Sanctum access token. The token's row
 * cascades this one away, so signing out ends it; a refresh carries it to the
 * new token with the same expiry (AuthController::refresh()). Past
 * `expires_at` it opens nothing. Written only by
 * App\Services\MenuVisibility\AdministratorOverride.
 *
 * @property int $id
 * @property int $personal_access_token_id
 * @property int $user_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MenuVisibilityOverride extends Model
{
    protected $fillable = ['personal_access_token_id', 'user_id', 'expires_at'];

    protected function casts(): array
    {
        return [
            'personal_access_token_id' => 'integer',
            'user_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Overrides still open now.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }
}
