<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaDocumentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One file of a compliance document (CDA design §1): uploaded, or generated
 * by an export (with the snapshot it was rendered from). The file itself is a
 * `documents` row on the private disk, served by a signed link. Never
 * overwritten and never deleted; `final_key` (unique) lets one version per
 * document be the final one.
 */
class CdaDocumentVersion extends Model
{
    /** @use HasFactory<CdaDocumentVersionFactory> */
    use Auditable, HasFactory;

    public const UPDATED_AT = null;

    public const ORIGINS = ['uploaded', 'generated'];

    protected $fillable = [
        'cda_document_id', 'version', 'version_label', 'final_key', 'origin', 'format', 'is_draft',
        'snapshot_id', 'document_id', 'sha256', 'notes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'cda_document_id' => 'integer',
            'version' => 'integer',
            'is_draft' => 'boolean',
            'snapshot_id' => 'integer',
            'document_id' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CdaDocumentVersion $version): void {
            if (array_diff(array_keys($version->getDirty()), ['final_key']) !== []) {
                throw new LogicException('A CDA document version is never overwritten: upload a new version.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A CDA document version is never deleted: withdraw the document instead.');
        });
    }

    public function cdaDocument(): BelongsTo
    {
        return $this->belongsTo(CdaDocument::class, 'cda_document_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
