<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of the AML risk scoring rules (AMLA design §4.9): the points
 * for each factor's value, the Medium and High thresholds, and the high-risk
 * countries. Never changed: an approved `risk_rules` change request adds the
 * next version. Every version stays "to verify" until counsel confirms it.
 *
 * @property int $id
 * @property int $version
 * @property array{factors: array<string, array<string, int>>, thresholds: array{medium: int, high: int}, high_risk_countries: list<string>} $definition
 * @property bool $to_verify
 * @property Carbon $effective_at
 * @property int|null $request_id
 */
class AmlaRiskRuleVersion extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['version', 'definition', 'to_verify', 'effective_at', 'request_id', 'created_by', 'approved_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'definition' => 'array',
            'to_verify' => 'boolean',
            'effective_at' => 'datetime',
            'request_id' => 'integer',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
