<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One required or offered document of an application, by type (one row per
 * application and type). Each file uploaded for it is a version
 * (OriginationDocumentVersion); `current_version_id` is the one staff
 * review. `public_id` is the random id the applicant's side uses, never the
 * row id.
 *
 * An ID document records the ID type and number staff read off it when they
 * verify it (`id_details`: `type`, a member valid ID type key, its
 * `custom_type_name` for "others", and `id_number`), encrypted and never
 * serialised; the hand-off makes it the member's valid ID with them
 * (MemberDocuments).
 *
 * @property string $status
 */
class OriginationDocument extends Model
{
    public const STATUSES = ['uploaded', 'pending_verification', 'verified', 'rejected', 'replacement_requested'];

    public const STATUS_LABELS = [
        'not_submitted' => 'Not submitted',
        'uploaded' => 'Uploaded',
        'pending_verification' => 'Pending verification',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'replacement_requested' => 'Replacement requested',
    ];

    protected $fillable = [
        'application_id', 'public_id', 'type', 'status', 'current_version_id', 'verified_by', 'verified_at', 'rejection_reason', 'notes',
        'id_details',
    ];

    protected $hidden = ['id_details'];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'current_version_id' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'id_details' => 'encrypted:array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OriginationApplication::class, 'application_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(OriginationDocumentVersion::class, 'document_id')->orderBy('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(OriginationDocumentVersion::class, 'current_version_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
