<?php

namespace App\Models;

use App\Services\CsvImport\CsvImportUploadService;
use Carbon\CarbonInterface;
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
        'rows_redacted_at',
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
            'rows_redacted_at' => 'datetime',
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

    /**
     * Phases in which a run is over, one way or another.
     *
     * THE one copy of that list on this side of the feature. It mirrors
     * CsvImportUploadService::CLOSED_PHASES and defers to it whenever that class
     * is present, so `cancelled` — added after the fact, with the cancel
     * endpoint — is honoured here the moment it exists rather than being missed
     * by a hardcoded pair. The literal below is the fallback for branches the
     * upload work has not merged into yet, and is dead the moment it has.
     *
     * Read by RunStatusReader for `is_closed` and by `imports:redact-rows` for
     * "finished long enough ago to redact"; a run that is not closed is still
     * being worked on and its staged rows are still the resume set.
     *
     * NOT CsvImportUploadService::STORAGE_RELEASE_PHASES, which omits `failed`.
     * That one is a file-retention list — a failed run keeps its uploaded CSVs
     * for 72 hours so a transient deadlock stays recoverable — and it answers a
     * different question. Substituted here it would make `is_closed` false for
     * every failed run, leaving the status endpoint polled forever, and it would
     * exempt failed runs from redaction permanently: the staged rows outlive the
     * file, so they are exactly the copy that needs the clock.
     *
     * @return list<string>
     */
    public static function closedPhases(): array
    {
        if (class_exists(CsvImportUploadService::class)) {
            return CsvImportUploadService::CLOSED_PHASES;
        }

        return ['completed', 'failed', 'cancelled'];
    }

    /**
     * Who admitted the members this run creates, and on what date.
     *
     * Spread straight into the importer's `Borrower::create()` payload — both
     * keys are $fillable on Borrower — so an imported member carries the same
     * two review columns a member approved through the UI does.
     *
     * IT IS NOT A CLAIM THAT KYC HAPPENED. The importer creates members with no
     * documents at all, and `approveRegistration()` would refuse every one of
     * them for exactly that reason. What these columns record is narrower and
     * true: this cooperative's own register admitted these people as of the date
     * the extract represents, and a named operator on this system chose to
     * carry that register across. Leaving them NULL was the dishonest option —
     * it describes a record that was admitted by nobody, on no date, and it is
     * the state `registrations:backfill-approvals` later fills in as
     * `approved_at = created_at, approved_by = null`, permanently recording the
     * upload date as the admission date because no audit trail contradicts it.
     *
     * `as_of_date` and NOT `now()`: the extract is true as of the date it was
     * pulled, and an import run on the 9th for a file cut on the 1st must not
     * date a decade-old membership to the afternoon it was uploaded. It is a
     * date cast, so this is midnight Manila on that day.
     *
     * `initiated_by` may be null — the column is nullOnDelete and staff leave.
     * A null approver is the same honest blank the backfill command leaves
     * rather than inventing a plausible operator.
     *
     * @return array{approved_at: CarbonInterface, approved_by: int|null}
     */
    public function admissionStamp(): array
    {
        return [
            // created_at behind it only so an unsaved run cannot produce a null
            // timestamp; `as_of_date` is NOT NULL on every persisted row.
            'approved_at' => $this->as_of_date?->startOfDay() ?? $this->created_at ?? now(),
            'approved_by' => $this->initiated_by,
        ];
    }
}
