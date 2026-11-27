<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bracket of a penalty configuration: the days past the due date it
 * covers (`days_to` null is open-ended) and the percent of the period's unpaid
 * principal a charge falling in it takes. Written only by
 * PenaltyConfigService, which audits the whole configuration's brackets in
 * words on its own row.
 *
 * @property int $days_from
 * @property int|null $days_to
 * @property string $rate
 */
class PenaltyConfigCondition extends Model
{
    use HasFactory;

    protected $fillable = [
        'penalty_config_id',
        'days_from',
        'days_to',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'days_from' => 'integer',
            'days_to' => 'integer',
            'rate' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PenaltyConfig, $this> */
    public function penaltyConfig(): BelongsTo
    {
        return $this->belongsTo(PenaltyConfig::class);
    }
}
