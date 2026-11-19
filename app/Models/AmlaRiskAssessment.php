<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AML risk assessment (AMLA design §4.9): the rules version, every
 * factor with its value and points, the score, the computed level and the
 * level in force, which is the computed one unless a person overrode it with
 * a reason. Kept as written, so the history shows every change.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $rule_version_id
 * @property int $score
 * @property string $computed_level
 * @property string $level
 * @property bool $overridden
 * @property bool $override_carried
 * @property string|null $override_reason
 * @property list<array{key: string, value: string, points: int}> $factors
 * @property string $trigger
 * @property int $assessed_by
 */
class AmlaRiskAssessment extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const LEVELS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    public const TRIGGERS = [
        'profile_change' => 'Profile changed',
        'id_verification' => 'ID verification recorded',
        'manual' => 'Assessed by staff',
        'override' => 'Level overridden',
    ];

    protected $fillable = [
        'profile_id', 'rule_version_id', 'score', 'computed_level', 'level', 'overridden', 'override_carried',
        'override_reason', 'factors', 'trigger', 'assessed_by',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'rule_version_id' => 'integer',
            'score' => 'integer',
            'overridden' => 'boolean',
            'override_carried' => 'boolean',
            'override_reason' => 'encrypted',
            'factors' => 'array',
            'assessed_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AmlaRiskRuleVersion::class, 'rule_version_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
