<?php

namespace App\Models;

use Database\Factories\PortalTermsVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A version of the portal's Terms of Use, Privacy Notice and e-communication
 * consent (portal design §1). Append-only: the latest published version is
 * the current one, and one published with `requires_reacceptance` holds every
 * signed-in route except `me`, terms and logout until the member accepts it.
 */
class PortalTermsVersion extends Model
{
    /** @use HasFactory<PortalTermsVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'version',
        'title',
        'terms_body',
        'privacy_body',
        'consent_body',
        'requires_reacceptance',
        'is_draft',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_reacceptance' => 'boolean',
            'is_draft' => 'boolean',
            'published_at' => 'datetime',
            'published_by' => 'integer',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_draft', false)->whereNotNull('published_at');
    }

    public static function current(): ?self
    {
        return static::query()->published()->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
