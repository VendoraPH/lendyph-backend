<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One attempt at migrating a coop's legacy records in from CSV.
 *
 * A run owns its two uploaded files and, through `phase` and `cursor_row_id`,
 * enough state to resume a half-finished import instead of restarting it and
 * writing everything a second time.
 *
 * Deliberately NOT Auditable. The trait writes an audit row per created,
 * updated and deleted model, and an import advances this row's phase and cursor
 * continually while it works — auditing that would bury the audit log under
 * thousands of entries saying nothing an operator wants. The importer writes a
 * single summary audit row when the run finishes instead.
 */
class CsvImportRun extends Model
{
    protected $fillable = [
        'branch_id',
        'as_of_date',
        'phase',
        'product_mapping',
        'cursor_row_id',
        'initiated_by',
        'initiated_ip',
        'started_at',
        'finished_at',
        'failure_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'product_mapping' => 'array',
            'cursor_row_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'notes' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CsvImportFile::class);
    }

    /**
     * The customers file for this run, or null if it has not been uploaded.
     *
     * Safe as a hasOne despite `files()` being a hasMany: the unique index on
     * `(csv_import_run_id, kind)` means at most one row can ever match.
     */
    public function customersFile(): HasOne
    {
        return $this->hasOne(CsvImportFile::class)->where('kind', 'customers');
    }

    public function loansFile(): HasOne
    {
        return $this->hasOne(CsvImportFile::class)->where('kind', 'loans');
    }
}
