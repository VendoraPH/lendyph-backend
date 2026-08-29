<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received slice of an uploaded CSV.
 *
 * The row's existence IS the record that the chunk arrived — there is no
 * separate "received" flag to keep in step with it. The unique index on
 * `(csv_import_file_id, chunk_index)` is what makes that equivalence hold under
 * the concurrent uploads a chunked transfer produces by definition.
 *
 * Deliberately NOT Auditable — see CsvImportRun for why.
 */
class CsvImportFileChunk extends Model
{
    protected $fillable = [
        'csv_import_file_id',
        'chunk_index',
        'size_bytes',
        'sha256',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(CsvImportFile::class, 'csv_import_file_id');
    }
}
