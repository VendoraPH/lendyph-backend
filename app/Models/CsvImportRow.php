<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of one uploaded CSV, staged before anything reaches the real tables.
 *
 * `line_number` is the physical line in the submitted file, header included —
 * the number the admin's spreadsheet shows, and the only one that belongs in an
 * error message. `record_number` is the data ordinal after the header is
 * skipped. They are stored separately rather than derived from one another
 * because that derivation is how error reports end up off by one.
 *
 * A NULL `result` means the row has not been decided yet; that is the resume
 * marker the importer restarts from.
 *
 * Deliberately NOT Auditable. Auditing would write one audit row per staged
 * CSV line — doubling the write volume of the very operation this table exists
 * to make cheap, and telling an operator nothing the row itself does not.
 */
class CsvImportRow extends Model
{
    protected $fillable = [
        'csv_import_file_id',
        'line_number',
        'record_number',
        'raw',
        'normalized',
        'status',
        'errors',
        'result',
        'result_category',
        'result_message',
        'borrower_id',
        'loan_id',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'record_number' => 'integer',
            'raw' => 'array',
            'normalized' => 'array',
            'errors' => 'array',
            'attempts' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(CsvImportFile::class, 'csv_import_file_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Rows this run has not decided yet — the resume set.
     *
     * Hits the `(csv_import_file_id, status, result)` index, so finding where a
     * crashed import left off never scans the rows already behind it.
     */
    public function scopePending($query)
    {
        return $query->whereNull('result');
    }
}
