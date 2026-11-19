<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable version of the AMLA detection rules (AMLA design §4.7):
 * which rules are active, each one's priority and its starting-point
 * parameters, "to verify". Version 1 is written by the detection migration;
 * each later version comes from an approved `detection_rules` change request.
 * Every alert cites the version it ran under. Kept forever and never changed.
 *
 * The definition is stored as text, so its rules keep their written order.
 *
 * @property int $id
 * @property int $version
 * @property array<string, array{active: bool, priority: string, params: array<string, mixed>}> $definition
 * @property bool $to_verify
 */
class AmlaRuleVersion extends Model
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
}
