<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the two CSV files belonging to an import run.
 *
 * Carries both what the uploader declared (`size_bytes`, `sha256`,
 * `total_chunks`) and what the server actually assembled (`assembled_path`,
 * `assembled_sha256`), kept apart on purpose so the two can be compared before
 * a single row is parsed.
 *
 * Deliberately NOT Auditable — see CsvImportRun for why.
 */
class CsvImportFile extends Model
{
    protected $fillable = [
        'csv_import_run_id',
        'kind',
        'original_filename',
        'size_bytes',
        'sha256',
        'chunk_size',
        'total_chunks',
        'assembled_path',
        'assembled_sha256',
        'delimiter',
        'encoding_note',
        'header_skipped',
        'record_count',
        'column_count',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'header_skipped' => 'boolean',
            'record_count' => 'integer',
            'column_count' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CsvImportRun::class, 'csv_import_run_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(CsvImportFileChunk::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CsvImportRow::class);
    }
}
