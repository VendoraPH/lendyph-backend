<?php

namespace App\Models;

use Database\Factories\CdaReportValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field's value on a report (CDA design §1): `field_key` is
 * "section.field" (a table field holds a JSON row array), with the source it
 * came from (`source_key`, label, params and the value the source gave), an
 * override and its reason, remarks and evidence. One generic store, so a new
 * CDA form is a new template version, never a migration.
 *
 * Not Auditable: a refresh rewrites many values at once; the report writes
 * one audit row per save or refresh that changed something.
 *
 * @property string $field_key
 * @property mixed $value
 */
class CdaReportValue extends Model
{
    /** @use HasFactory<CdaReportValueFactory> */
    use HasFactory;

    protected $fillable = [
        'report_id', 'field_key', 'value', 'source_key', 'source_label', 'source_params', 'source_value',
        'is_override', 'override_reason', 'remarks', 'evidence', 'updated_by', 'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'report_id' => 'integer',
            'value' => 'json',
            'source_params' => 'array',
            'source_value' => 'json',
            'is_override' => 'boolean',
            'evidence' => 'array',
            'updated_by' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CdaReport::class, 'report_id');
    }
}
