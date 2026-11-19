<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored file of an application document, written only by
 * OriginationFileStore: on the private disk, with its SHA-256 (a document
 * never holds the same file twice) and its scan result. A file a scanner
 * found infected is never stored, so the result is either "clean" or
 * "not_scanned" (no scanner set up).
 */
class OriginationDocumentVersion extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public const SCAN_NOT_SCANNED = 'not_scanned';

    public const SCAN_CLEAN = 'clean';

    public const SCAN_LABELS = [
        self::SCAN_NOT_SCANNED => 'Not scanned',
        self::SCAN_CLEAN => 'Scanned, no threat found',
    ];

    protected $fillable = [
        'document_id', 'version', 'public_id', 'disk', 'path', 'file_name', 'mime_type', 'size_bytes', 'sha256',
        'scan_status', 'scanner', 'scanned_at', 'uploaded_at',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'version' => 'integer',
            'size_bytes' => 'integer',
            'scanned_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function scanLabel(): string
    {
        return self::SCAN_LABELS[$this->scan_status] ?? 'Not scanned';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OriginationDocument::class, 'document_id');
    }
}
