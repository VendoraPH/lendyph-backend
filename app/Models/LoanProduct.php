<?php

namespace App\Models;

use App\Enums\InterestRateFrequency;
use App\Enums\TermUnit;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanProduct extends Model
{
    use Auditable, HasFactory;

    /**
     * The database defaults, so a product built in memory reads the same as
     * one loaded back.
     */
    protected $attributes = [
        'term_unit' => 'months',
        'interest_rate_frequency' => 'monthly',
    ];

    protected $fillable = [
        'name',
        'description',
        'interest_rate',
        'interest_rate_frequency',
        'min_interest_rate',
        'interest_method',
        'term',
        'term_unit',
        'min_term',
        'max_term',
        'frequency',
        'frequencies',
        'processing_fee',
        'min_processing_fee',
        'max_processing_fee',
        'service_fee',
        'min_service_fee',
        'max_service_fee',
        'notarial_fee',
        'custom_fees',
        'penalty_rate',
        'grace_period_days',
        'scb_required',
        'min_scb',
        'max_scb',
        'min_amount',
        'max_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'min_interest_rate' => 'decimal:4',
            'processing_fee' => 'decimal:4',
            'min_processing_fee' => 'decimal:4',
            'max_processing_fee' => 'decimal:4',
            'service_fee' => 'decimal:4',
            'min_service_fee' => 'decimal:4',
            'max_service_fee' => 'decimal:4',
            'notarial_fee' => 'decimal:4',
            'penalty_rate' => 'decimal:4',
            'scb_required' => 'boolean',
            'min_scb' => 'decimal:2',
            'max_scb' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'frequencies' => 'array',
            'custom_fees' => 'array',
            'term_unit' => TermUnit::class,
            'interest_rate_frequency' => InterestRateFrequency::class,
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * The penalty configurations covering this product, active or not. At most
     * one is active (PenaltyConfigService); see activePenaltyConfigs().
     *
     * @return BelongsToMany<PenaltyConfig, $this>
     */
    public function penaltyConfigs(): BelongsToMany
    {
        return $this->belongsToMany(PenaltyConfig::class, 'penalty_config_products')
            ->using(PenaltyConfigProduct::class);
    }

    /**
     * The active configuration covering this product, as a list of at most
     * one, for eager loading (PenaltyTerms::activeConfigFor()).
     *
     * @return BelongsToMany<PenaltyConfig, $this>
     */
    public function activePenaltyConfigs(): BelongsToMany
    {
        return $this->penaltyConfigs()
            ->where('penalty_configs.status', PenaltyConfig::STATUS_ACTIVE)
            ->orderBy('penalty_configs.id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
