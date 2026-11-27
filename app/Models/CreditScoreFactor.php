<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One factor of a credit score: its raw value, the points it earned out of
 * those possible, and the line that explains it. A factor without data
 * (`has_data` false) earned nothing and counted in nothing. Written with its
 * score and never changed.
 */
class CreditScoreFactor extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'credit_score_id',
        'factor_key',
        'category_key',
        'label',
        'has_data',
        'raw_value',
        'points_earned',
        'points_possible',
        'impact',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'has_data' => 'boolean',
            'raw_value' => 'array',
            'points_earned' => 'integer',
            'points_possible' => 'integer',
        ];
    }

    protected function performUpdate($query): never
    {
        throw new LogicException("Credit score factor {$this->getKey()} is immutable.");
    }

    public function creditScore(): BelongsTo
    {
        return $this->belongsTo(CreditScore::class);
    }
}
