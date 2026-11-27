<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Online applications' company-wide settings (origination decisions 16–18):
 * one row, seeded with online applications off. Audited through Auditable.
 * The assessment limits live in OriginationRuleRevision, one immutable
 * revision per change; the per-product settings in OriginationProductSetting.
 *
 * @property bool $online_applications_enabled
 * @property string $reference_prefix
 * @property list<string> $accepted_file_types
 * @property list<array{type: string, label: string, description: ?string, required: bool}> $document_types
 * @property array<string, bool|int> $notifications
 */
class OriginationSetting extends Model
{
    use Auditable;

    /** A draft becomes incomplete after this many hours without a save (decision 18). */
    public const INCOMPLETE_AFTER_HOURS = 24;

    /** What the reference format reads like, after the prefix. */
    public const REFERENCE_PATTERN = '-YYYY-NNNNNN';

    protected $fillable = [
        'online_applications_enabled',
        'default_branch_id',
        'require_approved_member',
        'reference_prefix',
        'draft_expiration_days',
        'estimated_minutes',
        'accepted_file_types',
        'max_upload_mb',
        'retention_days',
        'document_types',
        'notifications',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'online_applications_enabled' => 'boolean',
            'default_branch_id' => 'integer',
            'require_approved_member' => 'boolean',
            'draft_expiration_days' => 'integer',
            'estimated_minutes' => 'integer',
            'accepted_file_types' => 'array',
            'max_upload_mb' => 'integer',
            'retention_days' => 'integer',
            'document_types' => 'array',
            'notifications' => 'array',
            'updated_by' => 'integer',
        ];
    }

    /** The settings row; the migration seeds it, so this only reads. */
    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrFail();
    }

    /** "OA-YYYY-NNNNNN". */
    public function referenceFormat(): string
    {
        return $this->reference_prefix.self::REFERENCE_PATTERN;
    }

    /** @return list<string> */
    public function documentTypeKeys(): array
    {
        return array_values(array_map(fn (array $type): string => (string) $type['type'], $this->document_types ?? []));
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
