<?php

namespace App\Models;

use App\Services\Penalties\BracketRate;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of late-penalty brackets covering one or more loan products
 * (Phase 4, Settings → Penalties).
 *
 * A loan copies its product's active configuration (id, name and brackets)
 * when it is created (PenaltyTerms::forProduct()) and charges from that copy
 * ever after, so editing, deactivating or deleting a configuration never
 * reprices a loan. Saves go through PenaltyConfigService, which checks under a
 * lock that a product sits in at most one active configuration and writes the
 * audit rows (brackets in words, products by name).
 *
 * @property int $id
 * @property string $name
 * @property string $status
 */
class PenaltyConfig extends Model
{
    use Auditable, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'name',
        'status',
    ];

    /** @return HasMany<PenaltyConfigCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(PenaltyConfigCondition::class)->orderBy('days_from');
    }

    /** @return BelongsToMany<LoanProduct, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(LoanProduct::class, 'penalty_config_products')
            ->using(PenaltyConfigProduct::class)
            ->orderBy('loan_products.name')
            ->orderBy('loan_products.id');
    }

    /** @return HasMany<Loan, $this> */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /** @param  Builder<PenaltyConfig>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The brackets as a loan stores them: `[{days_from, days_to, rate}]`, in
     * day order, each rate as stored (never rounded).
     *
     * @return list<array{days_from: int, days_to: int|null, rate: float}>
     */
    public function brackets(): array
    {
        return BracketRate::normalise($this->conditions->map(fn (PenaltyConfigCondition $condition): array => [
            'days_from' => $condition->days_from,
            'days_to' => $condition->days_to,
            'rate' => $condition->rate,
        ])->all());
    }
}
